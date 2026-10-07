<?php

namespace App\Models;

use CodeIgniter\Model;

class StockoutModel extends Model
{
    protected $table      = 'temp_stockout';
    protected $primaryKey = 'temp_stockout_id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'user_id', 'status', 'created_at',
        'approved_by', 'approved_at', 'user_office_id',
    ];

    /**
     * Get or create the current draft stockout request for a user.
     */
    public function getOrCreateDraft(int $userId, ?int $userOfficeId): array
    {
        $draft = $this->where('user_id', $userId)
            ->where('status', 'draft')
            ->first();

        if ($draft) {
            return $draft;
        }

        $this->insert([
            'user_id'        => $userId,
            'status'         => 'draft',
            'created_at'     => date('Y-m-d H:i:s'),
            'user_office_id' => $userOfficeId,
        ]);

        return $this->find($this->getInsertID());
    }

    /**
     * Get items in a temp stockout request.
     */
    public function getItems(int $tempStockoutId): array
    {
        return $this->db->table('temp_stockout_item AS tsi')
            ->select('tsi.*,
                      p.product AS item_name,
                      COALESCE(pc.unit_cost, 0) AS copy_unit_cost,
                      COALESCE(pc.label, "") AS copy_label,
                      COALESCE((
                          SELECT SUM(b.current_qty)
                          FROM batch_table b
                          WHERE b.product_id = tsi.product_id
                            AND (ts.user_office_id IS NULL OR b.user_office_id = ts.user_office_id)
                            AND (tsi.copy_id IS NULL OR b.copy_id = tsi.copy_id)
                      ), 0) AS current_stock')
            ->join('temp_stockout ts', 'tsi.temp_stockout_id = ts.temp_stockout_id')
            ->join('product_table p', 'tsi.product_id = p.product_id', 'left')
            ->join('product_copy_table pc', 'tsi.copy_id = pc.copy_id', 'left')
            ->where('tsi.temp_stockout_id', $tempStockoutId)
            ->orderBy('tsi.temp_stockout_item_id', 'ASC')
            ->get()
            ->getResultArray();
    }


    /**
     * Get items in a request, grouped/summed by product_id.
     */
    public function getItemsSummed(int $tempStockoutId): array
    {
        return $this->db->table('temp_stockout_item AS tsi')
            ->select('tsi.product_id, tsi.copy_id, p.product AS item_name, tsi.unit, tsi.description,
                      SUM(tsi.quantity) AS quantity, MIN(tsi.status) AS status,
                      GROUP_CONCAT(tsi.temp_stockout_item_id) AS item_ids,
                      COALESCE(pc.unit_cost, 0) AS copy_unit_cost,
                      COALESCE(pc.label, "") AS copy_label,
                      COALESCE((
                          SELECT SUM(b.current_qty)
                          FROM batch_table b
                          WHERE b.product_id = tsi.product_id
                            AND (ts.user_office_id IS NULL OR b.user_office_id = ts.user_office_id)
                            AND (tsi.copy_id IS NULL OR b.copy_id = tsi.copy_id)
                      ), 0) AS current_stock')
            ->join('temp_stockout ts', 'tsi.temp_stockout_id = ts.temp_stockout_id')
            ->join('product_table p', 'tsi.product_id = p.product_id', 'left')
            ->join('product_copy_table pc', 'tsi.copy_id = pc.copy_id', 'left')
            ->where('tsi.temp_stockout_id', $tempStockoutId)
            ->groupBy('tsi.product_id, tsi.copy_id, p.product, tsi.unit, tsi.description, pc.unit_cost, pc.label, ts.user_office_id')
            ->orderBy('p.product', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Add a product to a draft stockout.
     */
    public function addItem(int $tempStockoutId, array $data): void
    {
        $this->db->table('temp_stockout_item')->insert([
            'temp_stockout_id' => $tempStockoutId,
            'product_id'       => (int) $data['product_id'],
            'copy_id'          => ((int) ($data['copy_id'] ?? 0)) ?: null,
            'quantity'         => (int) $data['quantity'],
            'unit'             => trim((string) ($data['unit'] ?? '')),
            'description'      => trim((string) ($data['description'] ?? '')),
            'status'           => 'pending',
        ]);
    }

    /**
     * Update an item in a draft stockout.
     */
    public function updateItem(int $itemId, array $data): void
    {
        $update = [];
        if (isset($data['quantity'])) {
            $update['quantity'] = (int) $data['quantity'];
        }
        if (isset($data['description'])) {
            $update['description'] = trim((string) $data['description']);
        }
        if (isset($data['unit'])) {
            $update['unit'] = trim((string) $data['unit']);
        }

        if ($update) {
            $this->db->table('temp_stockout_item')
                ->where('temp_stockout_item_id', $itemId)
                ->update($update);
        }
    }

    /**
     * Remove an item from a draft stockout.
     */
    public function removeItem(int $itemId): void
    {
        $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $itemId)
            ->delete();
    }

    /**
     * Submit a draft for approval — merges duplicate product_id rows.
     */
    public function submitForApproval(int $tempStockoutId): void
    {
        $items = $this->db->table('temp_stockout_item')
            ->where('temp_stockout_id', $tempStockoutId)
            ->orderBy('temp_stockout_item_id', 'ASC')
            ->get()
            ->getResultArray();

        $grouped = [];
        foreach ($items as $item) {
            $key = (int) $item['product_id'] . ':' . (int) ($item['copy_id'] ?? 0);
            if (! isset($grouped[$key])) {
                $grouped[$key] = $item;
            } else {
                $grouped[$key]['quantity'] = (int) $grouped[$key]['quantity'] + (int) $item['quantity'];
                if (trim((string) $item['description']) !== '') {
                    $grouped[$key]['description'] = $item['description'];
                }
                if (trim((string) $item['unit']) !== '') {
                    $grouped[$key]['unit'] = $item['unit'];
                }
                $this->db->table('temp_stockout_item')
                    ->where('temp_stockout_item_id', $item['temp_stockout_item_id'])
                    ->delete();
            }
        }

        foreach ($grouped as $item) {
            $this->db->table('temp_stockout_item')
                ->where('temp_stockout_item_id', $item['temp_stockout_item_id'])
                ->update([
                    'quantity'    => (int) $item['quantity'],
                    'description' => $item['description'],
                    'unit'        => $item['unit'],
                ]);
        }

        $this->update($tempStockoutId, ['status' => 'pending']);
    }

    /**
     * Get pending stockout requests.
     */
    public function pendingRequests(int $userOfficeId = 0, int $levelId = 2): array
    {
        $builder = $this->db->table('temp_stockout AS ts')
            ->select('ts.*, u.username AS requester_name,
                      COALESCE(uot.user_office_name, "N/A") AS office_name')
            ->join('user_table u', 'ts.user_id = u.user_id', 'left')
            ->join('user_office_table uot', 'ts.user_office_id = uot.user_office_id', 'left')
            ->where('ts.status', 'pending')
            ->orderBy('ts.created_at', 'ASC');

        if ($levelId < 4 && $userOfficeId > 0) {
            $builder->where('ts.user_office_id', $userOfficeId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Approve a single item and deduct from batch_table.current_qty.
     * Returns true on success, or a string error key on failure.
     *
     * @return true|string
     */
    public function approveItem(int $tempStockoutItemId, int $approvedByUserId)
    {
        $item = $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $tempStockoutItemId)
            ->get()
            ->getRowArray();

        if (! $item || $item['status'] !== 'pending') {
            return false;
        }

        $header = $this->find((int) $item['temp_stockout_id']);
        if (! $header) {
            return false;
        }

        $userOfficeId = (int) ($header['user_office_id'] ?? 0);

        // ── Stock availability check ─────────────────────────────────────────
        $copyId         = (int) ($item['copy_id'] ?? 0);
        $availableStock = $this->availableStock((int) $item['product_id'], $userOfficeId, $copyId);

        if ($availableStock < (int) $item['quantity']) {
            return 'insufficient_stock';
        }

        $deductions = $this->deductStock((int) $item['product_id'], (int) $item['quantity'], $userOfficeId, $copyId);
        if (empty($deductions)) {
            return 'insufficient_stock';
        }
        foreach ($deductions as $deduction) {
            $this->createStockoutTransaction(
                (int) $deduction['batch_id'],
                (int) $deduction['quantity'],
                $userOfficeId,
                $approvedByUserId,
                (int) ($deduction['copy_id'] ?? 0)
            );
        }

        // Mark item approved
        $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $tempStockoutItemId)
            ->update(['status' => 'approved']);

        $this->update($header['temp_stockout_id'], [
            'approved_by' => $approvedByUserId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $this->checkAndFinalizeRequest((int) $item['temp_stockout_id']);

        return true;
    }

    /**
     * Approve all pending items in a request that have sufficient stock.
     * Items with insufficient stock are silently skipped (left as pending).
     *
     * @return array{approved: int, skipped: int}
     */
    public function approveAll(int $tempStockoutId, int $approvedByUserId): array
    {
        $header = $this->find($tempStockoutId);
        if (! $header) {
            return ['approved' => 0, 'skipped' => 0];
        }

        $userOfficeId = (int) ($header['user_office_id'] ?? 0);

        $items = $this->db->table('temp_stockout_item')
            ->where('temp_stockout_id', $tempStockoutId)
            ->where('status', 'pending')
            ->get()
            ->getResultArray();

        $approved = 0;
        $skipped  = 0;

        foreach ($items as $item) {
            // Check stock before approving
            $copyId         = (int) ($item['copy_id'] ?? 0);
            $availableStock = $this->availableStock((int) $item['product_id'], $userOfficeId, $copyId);

            if ($availableStock < (int) $item['quantity']) {
                $skipped++;
                continue; // Leave as pending — not enough stock
            }

            $deductions = $this->deductStock((int) $item['product_id'], (int) $item['quantity'], $userOfficeId, $copyId);
            if (empty($deductions)) {
                $skipped++;
                continue;
            }
            foreach ($deductions as $deduction) {
                $this->createStockoutTransaction(
                    (int) $deduction['batch_id'],
                    (int) $deduction['quantity'],
                    $userOfficeId,
                    $approvedByUserId,
                    (int) ($deduction['copy_id'] ?? 0)
                );
            }
            $this->db->table('temp_stockout_item')
                ->where('temp_stockout_item_id', $item['temp_stockout_item_id'])
                ->update(['status' => 'approved']);
            $approved++;
        }

        if ($approved > 0) {
            $this->update($tempStockoutId, [
                'approved_by' => $approvedByUserId,
                'approved_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->checkAndFinalizeRequest($tempStockoutId);

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /**
     * Reject a single item.
     */
    public function rejectItem(int $tempStockoutItemId): bool
    {
        $item = $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $tempStockoutItemId)
            ->get()
            ->getRowArray();

        if (! $item || $item['status'] !== 'pending') {
            return false;
        }

        $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $tempStockoutItemId)
            ->update(['status' => 'rejected']);

        $this->checkAndFinalizeRequest((int) $item['temp_stockout_id']);

        return true;
    }

    private function availableStock(int $productId, int $userOfficeId = 0, int $copyId = 0): int
    {
        $builder = $this->db->table('batch_table')
            ->selectSum('current_qty', 'total')
            ->where('product_id', $productId);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        if ($copyId > 0) {
            $builder->where('copy_id', $copyId);
        }

        return (int) ($builder->get()->getRowArray()['total'] ?? 0);
    }

    /**
     * Deduct stock from batches using FIFO (oldest first by date_received).
     */
    private function deductStock(int $productId, int $quantity, int $userOfficeId = 0, int $copyId = 0): array
    {
        $builder = $this->db->table('batch_table')
            ->where('product_id', $productId)
            ->where('current_qty >', 0);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        if ($copyId > 0) {
            $builder->where('copy_id', $copyId);
        }

        $batches = $builder->orderBy('date_received', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->get()
            ->getResultArray();

        $remaining  = $quantity;
        $deductions = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $deduct = min($remaining, (int) $batch['current_qty']);
            $deductions[] = [
                'batch_id' => (int) $batch['batch_id'],
                'quantity' => $deduct,
                'copy_id'  => (int) ($batch['copy_id'] ?? 0),
            ];
            $remaining -= $deduct;
        }

        if ($remaining > 0) {
            return [];
        }

        foreach ($deductions as $deduction) {
            $this->db->table('batch_table')
                ->where('batch_id', $deduction['batch_id'])
                ->set('current_qty', 'current_qty - ' . (int) $deduction['quantity'], false)
                ->set('updated_at', date('Y-m-d H:i:s'))
                ->update();
        }

        return $deductions;
    }

    /**
     * Create a transaction record for a stock-out approval.
     */
    private function createStockoutTransaction(int $batchId, int $quantity, int $userOfficeId, int $userId, int $copyId = 0): void
    {
        // Resolve the 'issue' type ID dynamically — never hardcode
        $issueRow = $this->db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->where('transaction_type', 'issue')
            ->get(1)->getRowArray();
        $issueTypeId = (int) ($issueRow['transaction_type_id'] ?? 2);

        $this->db->table('transaction_table')->insert([
            'transaction_type_id'   => $issueTypeId,
            'transaction_qty'       => $quantity,
            'transaction_unit_cost' => 0,
            'transaction_date'      => date('Y-m-d H:i:s'),
            'batch_id'              => $batchId,
            'copy_id'              => $copyId > 0 ? $copyId : null,
            'reference_id'          => null,
            'office_id'             => null,
            'user_id'               => $userId,
            'user_office_id'        => $userOfficeId,
            'adjustment_reason_id'  => null,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if all items processed and finalize the request.
     */
    private function checkAndFinalizeRequest(int $tempStockoutId): void
    {
        $pendingCount = (int) $this->db->table('temp_stockout_item')
            ->where('temp_stockout_id', $tempStockoutId)
            ->where('status', 'pending')
            ->countAllResults();

        if ($pendingCount === 0) {
            $approvedCount = (int) $this->db->table('temp_stockout_item')
                ->where('temp_stockout_id', $tempStockoutId)
                ->where('status', 'approved')
                ->countAllResults();

            $status = $approvedCount > 0 ? 'approved' : 'rejected';
            $this->update($tempStockoutId, ['status' => $status]);
        }
    }

    /**
     * Get available products for stock-out (products with stock) for an office.
     */
    public function availableItems(int $userOfficeId = 0): array
    {
        $params = [];

        if ($userOfficeId > 0) {
            $sql = 'SELECT p.product_id, p.product, p.product_description AS description,
                           ut.unit AS unit_name,
                           COALESCE(SUM(b.current_qty), 0) AS current_stock
                    FROM product_table p
                    LEFT JOIN unit_table ut ON p.unit_id = ut.unit_id
                    LEFT JOIN batch_table b ON p.product_id = b.product_id
                                           AND b.user_office_id = ?
                    WHERE p.user_office_id = ?
                    GROUP BY p.product_id, p.product, p.product_description, ut.unit
                    ORDER BY p.product ASC';
            $params = [$userOfficeId, $userOfficeId];
        } else {
            $sql = 'SELECT p.product_id, p.product, p.product_description AS description,
                           ut.unit AS unit_name,
                           COALESCE(SUM(b.current_qty), 0) AS current_stock
                    FROM product_table p
                    LEFT JOIN unit_table ut ON p.unit_id = ut.unit_id
                    LEFT JOIN batch_table b ON p.product_id = b.product_id
                    GROUP BY p.product_id, p.product, p.product_description, ut.unit
                    ORDER BY p.product ASC';
        }

        return $this->db->query($sql, $params)->getResultArray();
    }
}
