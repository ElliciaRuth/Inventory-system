<?php

namespace App\Models;

use CodeIgniter\Model;

class StockcardModel extends Model
{
    protected $table                 = 'stockcard';
    protected $primaryKey            = 'stockcard_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['transaction_id', 'reference_id', 'office_id', 'item_id', 'transaction_type_id', 'user_office_id'];
    protected bool $allowEmptyInserts = false;

    public function currentStock(int $itemId, int $userOfficeId = 0): int
    {
        $sql = 'SELECT COALESCE(SUM(t.receipt_qty), 0) - COALESCE(SUM(t.issue_qty), 0) AS stock
             FROM stockcard sc
             INNER JOIN transaction t ON t.transaction_id = sc.transaction_id
             WHERE sc.item_id = ?';
        $params = [$itemId];

        if ($userOfficeId > 0) {
            $sql .= ' AND sc.user_office_id = ?';
            $params[] = $userOfficeId;
        }

        $row = $this->db->query($sql, $params)->getRowArray();

        return (int) ($row['stock'] ?? 0);
    }

    public function paginatedHistory(
        int $itemId,
        string $filterType,
        int $page,
        int $limit,
        int $year = 0,
        string $month = '',
        string $search = '',
        int $userOfficeId = 0
    ): array {
        $order = $filterType === 'oldest' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $limit;
        $dateFilter = '';
        $searchFilter = '';
        $officeFilter = '';
        $params = [$itemId];

        if ($userOfficeId > 0) {
            $officeFilter = ' AND stockcard.user_office_id = ?';
            $params[] = $userOfficeId;
        }

        if ($year > 0 && preg_match('/^\d{1,2}$/', $month)) {
            $dateFilter = " AND DATE_FORMAT(date, '%Y-%m') = ? ";
            $params[] = sprintf('%04d-%02d', $year, (int) $month);
        }

        if ($search !== '') {
            $searchFilter = ' AND (item_name LIKE ? OR description LIKE ? OR item_no LIKE ? OR office LIKE ? OR reference LIKE ?)';
            $wild = '%' . $search . '%';
            array_push($params, $wild, $wild, $wild, $wild, $wild);
        }

        $rows = $this->db->query(
            "SELECT * FROM (
                SELECT stockcard.stockcard_id, transaction.transaction_id, transaction.date,
                       transaction.receipt_qty, transaction.issue_qty, item.item AS item_name,
                       item.description, item.item_no, COALESCE(unit.unit, 'Deleted Unit') AS unit_name,
                       CONCAT(COALESCE(item_category.item_category, 'Uncategorized'), '-', item.item_no) AS stockcard_no,
                       COALESCE(office.office, 'Deleted Office') AS office,
                       COALESCE(reference.reference, 'Deleted Reference') AS reference,
                       COALESCE(entity.entity_name, 'Deleted Entity') AS entity_name,
                       COALESCE(entity.fund_cluster, '-') AS fund_cluster,
                       SUM(transaction.receipt_qty - transaction.issue_qty)
                           OVER (PARTITION BY stockcard.item_id ORDER BY transaction.date ASC, stockcard.stockcard_id ASC) AS balance
                FROM stockcard
                INNER JOIN transaction ON stockcard.transaction_id = transaction.transaction_id
                INNER JOIN item ON stockcard.item_id = item.item_id
                LEFT JOIN item_category ON item_category.item_category_id = item.item_category_id
                LEFT JOIN unit ON item.unit_id = unit.unit_id
                LEFT JOIN office ON stockcard.office_id = office.office_id
                LEFT JOIN reference ON stockcard.reference_id = reference.reference_id
                LEFT JOIN entity ON item.entity_id = entity.entity_id
                WHERE stockcard.item_id = ? {$officeFilter}
            ) AS base
            WHERE 1=1 {$searchFilter} {$dateFilter}
            ORDER BY date {$order}, stockcard_id {$order}
            LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->getResultArray();

        $totalParams = [$itemId];
        $totalSql = 'SELECT COUNT(*) AS total
                     FROM stockcard
                     INNER JOIN transaction ON stockcard.transaction_id = transaction.transaction_id
                     WHERE stockcard.item_id = ?';

        if ($userOfficeId > 0) {
            $totalSql .= ' AND stockcard.user_office_id = ?';
            $totalParams[] = $userOfficeId;
        }

        if ($year > 0 && preg_match('/^\d{1,2}$/', $month)) {
            $totalSql .= " AND DATE_FORMAT(transaction.date, '%Y-%m') = ?";
            $totalParams[] = sprintf('%04d-%02d', $year, (int) $month);
        }

        $total = (int) ($this->db->query($totalSql, $totalParams)->getRowArray()['total'] ?? 0);

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }
}
