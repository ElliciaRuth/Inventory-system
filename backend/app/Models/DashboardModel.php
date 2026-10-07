<?php

namespace App\Models;

use CodeIgniter\Model;

class DashboardModel extends Model
{
    protected $table = 'product_table';

    public function overview(int $userOfficeId = 0): array
    {
        $lowStock      = $this->lowStock($userOfficeId);
        $expiring      = $this->expiringSoon($userOfficeId);
        $outOfStock    = $this->outOfStock($userOfficeId);
        $activeBorrows = $this->activeBorrows($userOfficeId);

        return [
            'lowStock'           => $lowStock,
            'expiring'           => $expiring,
            'outOfStock'         => $outOfStock,
            'recentTransactions' => $this->recentTransactions($userOfficeId),
            'activeBorrows'      => $activeBorrows,
            // Kept for legacy template compatibility — no longer used for threshold logic
            'warningDays'        => 30,
            'dangerDays'         => 7,
            'summary'            => [
                'totalItems'        => $this->totalItems($userOfficeId),
                'lowStockCount'     => count($lowStock),
                'expiringCount'     => count($expiring),
                'outOfStockCount'   => count($outOfStock),
                'activeBorrowCount' => count($activeBorrows),
            ],
        ];
    }

    public function lowStock(int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND p.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            'SELECT p.product AS item,
                    COALESCE(SUM(b.current_qty), 0) AS stock_left,
                    COALESCE(p.product_reorder_point, 0) AS re_order_point
             FROM product_table p
             LEFT JOIN batch_table b ON p.product_id = b.product_id
             WHERE 1=1' . $officeFilter . '
             GROUP BY p.product_id, p.product, p.product_reorder_point
             HAVING stock_left <= re_order_point
                AND stock_left > 0
                AND re_order_point > 0'
        )->getResultArray();
    }

    public function expiringSoon(int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND p.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            'SELECT b.batch_id, p.product AS item, b.expiration_date, b.current_qty AS remaining_qty,
                    DATEDIFF(b.expiration_date, CURDATE()) AS days_left,
                    p.expiry_warning_days,
                    p.expiry_danger_days
             FROM batch_table b
             INNER JOIN product_table p ON b.product_id = p.product_id
             WHERE b.current_qty > 0
               AND b.expiration_date IS NOT NULL
               AND b.expiration_date >= CURDATE()
               AND DATEDIFF(b.expiration_date, CURDATE()) <= p.expiry_warning_days' . $officeFilter . '
             ORDER BY b.expiration_date ASC'
        )->getResultArray();
    }

    public function outOfStock(int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND p.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            'SELECT p.product AS item
             FROM product_table p
             LEFT JOIN batch_table b ON p.product_id = b.product_id
             WHERE 1=1' . $officeFilter . '
             GROUP BY p.product_id, p.product
             HAVING COALESCE(SUM(b.current_qty), 0) = 0'
        )->getResultArray();
    }

    public function recentTransactions(int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND t.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            'SELECT p.product AS item,
                    t.transaction_qty,
                    tt.transaction_type,
                    t.transaction_date AS date
             FROM transaction_table t
             INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
             INNER JOIN batch_table b ON t.batch_id = b.batch_id
             INNER JOIN product_table p ON b.product_id = p.product_id
             WHERE 1=1' . $officeFilter . '
             ORDER BY t.transaction_date DESC
             LIMIT 5'
        )->getResultArray();
    }

    public function totalItems(int $userOfficeId = 0): int
    {
        $builder = $this->db->table('product_table')->selectCount('product_id', 'total');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return (int) ($builder->get()->getRowArray()['total'] ?? 0);
    }

    /**
     * Net active borrows per product:
     * items where SUM(borrowed qty) > SUM(returned qty).
     * Uses transaction_type names so it works regardless of DB IDs.
     */
    public function activeBorrows(int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND t.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            "SELECT p.product AS item,
                    SUM(CASE WHEN tt.transaction_type = 'borrow' THEN t.transaction_qty ELSE 0 END) AS total_borrowed,
                    SUM(CASE WHEN tt.transaction_type = 'return' THEN t.transaction_qty ELSE 0 END) AS total_returned,
                    SUM(CASE WHEN tt.transaction_type = 'borrow' THEN  t.transaction_qty
                             WHEN tt.transaction_type = 'return' THEN -t.transaction_qty
                             ELSE 0 END) AS net_borrowed,
                    p.product_id,
                    COALESCE(ot.office_name, '') AS office,
                    MAX(t.transaction_date) AS last_borrowed
             FROM transaction_table t
             INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
             INNER JOIN batch_table b ON t.batch_id = b.batch_id
             INNER JOIN product_table p ON b.product_id = p.product_id
             LEFT JOIN office_table ot ON t.office_id = ot.office_id
             WHERE tt.transaction_type IN ('borrow', 'return')" . $officeFilter . "
             GROUP BY b.product_id, p.product
             HAVING net_borrowed > 0
             ORDER BY net_borrowed DESC"
        )->getResultArray();
    }

    /**
     * Full paginated transaction log across all products for the office.
     * Used by the comprehensive transaction log page.
     */
    public function transactionLog(
        int    $userOfficeId = 0,
        string $search       = '',
        string $type         = '',
        string $dateFrom     = '',
        string $dateTo       = '',
        int    $page         = 1,
        int    $limit        = 50
    ): array {
        $offset = ($page - 1) * $limit;
        $params = [];
        $where  = ['tt.transaction_type IN (\'receipt\',\'issue\',\'adjust_out\',\'borrow\',\'return\')'];

        if ($userOfficeId > 0) {
            $where[]  = 't.user_office_id = ?';
            $params[] = $userOfficeId;
        }
        if ($type !== '') {
            $where[]  = 'tt.transaction_type = ?';
            $params[] = $type;
        }
        if ($dateFrom !== '') {
            $where[]  = 'DATE(t.transaction_date) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]  = 'DATE(t.transaction_date) <= ?';
            $params[] = $dateTo;
        }
        if ($search !== '') {
            $where[]  = '(p.product LIKE ? OR p.stock_no LIKE ? OR ot.office_name LIKE ? OR r.reference LIKE ? OR u.username LIKE ?)';
            $wild     = '%' . $search . '%';
            array_push($params, $wild, $wild, $wild, $wild, $wild);
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                    t.transaction_id,
                    t.transaction_date,
                    t.transaction_qty,
                    t.transaction_unit_cost,
                    tt.transaction_type,
                    p.product_id,
                    p.product,
                    p.stock_no,
                    p.product_no,
                    COALESCE(ut.unit, 'pcs') AS unit_name,
                    COALESCE(ot.office_name, '—') AS office_name,
                    COALESCE(r.reference, '—') AS reference,
                    COALESCE(u.username, '—') AS recorded_by,
                    COALESCE(ar.adjustment_reason, '—') AS adjustment_reason
                FROM transaction_table t
                INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
                INNER JOIN batch_table b ON t.batch_id = b.batch_id
                INNER JOIN product_table p ON b.product_id = p.product_id
                LEFT JOIN unit_table ut ON p.unit_id = ut.unit_id
                LEFT JOIN office_table ot ON t.office_id = ot.office_id
                LEFT JOIN reference_table r ON t.reference_id = r.reference_id
                LEFT JOIN user_table u ON t.user_id = u.user_id
                LEFT JOIN adjustment_reason ar ON t.adjustment_reason_id = ar.adjustment_reason_id
                {$whereClause}
                ORDER BY t.transaction_date DESC, t.transaction_id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $rows = $this->db->query($sql, $params)->getResultArray();

        // Count query (reuse same filters, no LIMIT)
        $countSql = "SELECT COUNT(*) AS total
                     FROM transaction_table t
                     INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
                     INNER JOIN batch_table b ON t.batch_id = b.batch_id
                     INNER JOIN product_table p ON b.product_id = p.product_id
                     LEFT JOIN office_table ot ON t.office_id = ot.office_id
                     LEFT JOIN reference_table r ON t.reference_id = r.reference_id
                     LEFT JOIN user_table u ON t.user_id = u.user_id
                     {$whereClause}";

        $total = (int) ($this->db->query($countSql, $params)->getRowArray()['total'] ?? 0);

        return ['rows' => $rows, 'total' => $total];
    }
}
