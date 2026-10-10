<?php

namespace App\Models;

use App\Services\InventoryService;
use CodeIgniter\Model;

class StockoutModel extends Model
{
    protected $table      = 'temp_stockout';
    protected $primaryKey = 'temp_stockout_id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'user_id', 'status', 'created_at', 'submitted_at',
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

        $this->update($tempStockoutId, ['status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Get pending stockout requests.
     */
    public function pendingRequests(int $userOfficeId = 0, int $levelId = 2): array
    {
        $builder = $this->db->table('temp_stockout AS ts')
            ->select('ts.*, u.username AS requester_name,
                      COALESCE(uot.user_office_name, "N/A") AS office_name,
                      (SELECT COUNT(*) FROM temp_stockout_item i
                        WHERE i.temp_stockout_id = ts.temp_stockout_id AND i.status = "pending") AS pending_items')
            ->join('user_table u', 'ts.user_id = u.user_id', 'left')
            ->join('user_office_table uot', 'ts.user_office_id = uot.user_office_id', 'left')
            ->where('ts.status', 'pending')
            ->orderBy('COALESCE(ts.submitted_at, ts.created_at)', 'ASC', false);

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

        if ($this->productArchived((int) $item['product_id'])) {
            return 'archived';
        }

        // ── Stock availability check ─────────────────────────────────────────
        $copyId         = (int) ($item['copy_id'] ?? 0);
        $availableStock = $this->availableStock((int) $item['product_id'], $userOfficeId, $copyId);

        if ($availableStock + 0.0001 < (float) $item['quantity']) {
            return 'insufficient_stock';
        }

        $deductions = $this->deductStock((int) $item['product_id'], (float) $item['quantity'], $userOfficeId, $copyId);
        if (empty($deductions)) {
            return 'insufficient_stock';
        }
        foreach ($deductions as $deduction) {
            $this->createStockoutTransaction(
                (int) $deduction['batch_id'],
                (float) $deduction['quantity'],
                $userOfficeId,
                $approvedByUserId,
                (int) ($deduction['copy_id'] ?? 0)
            );
        }

        // Mark item approved
        $this->db->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $tempStockoutItemId)
            ->update($this->decision('approved', $approvedByUserId));

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
            // Archived products can't be issued; leave them pending
            if ($this->productArchived((int) $item['product_id'])) {
                $skipped++;
                continue;
            }

            // Check stock before approving
            $copyId         = (int) ($item['copy_id'] ?? 0);
            $availableStock = $this->availableStock((int) $item['product_id'], $userOfficeId, $copyId);

            if ($availableStock + 0.0001 < (float) $item['quantity']) {
                $skipped++;
                continue; // Leave as pending — not enough stock
            }

            $deductions = $this->deductStock((int) $item['product_id'], (float) $item['quantity'], $userOfficeId, $copyId);
            if (empty($deductions)) {
                $skipped++;
                continue;
            }
            foreach ($deductions as $deduction) {
                $this->createStockoutTransaction(
                    (int) $deduction['batch_id'],
                    (float) $deduction['quantity'],
                    $userOfficeId,
                    $approvedByUserId,
                    (int) ($deduction['copy_id'] ?? 0)
                );
            }
            $this->db->table('temp_stockout_item')
                ->where('temp_stockout_item_id', $item['temp_stockout_item_id'])
                ->update($this->decision('approved', $approvedByUserId));
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
     * Reject a single item, with the reason the requester will see.
     */
    public function rejectItem(int $tempStockoutItemId, int $rejectedByUserId, string $reason): bool
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
            ->update($this->decision('rejected', $rejectedByUserId, $reason));

        $this->checkAndFinalizeRequest((int) $item['temp_stockout_id']);

        return true;
    }

    /**
     * Columns written when an item is accepted or rejected.
     */
    private function decision(string $status, int $userId, string $reason = ''): array
    {
        return [
            'status'          => $status,
            'decided_by'      => $userId ?: null,
            'decided_at'      => date('Y-m-d H:i:s'),
            'decision_reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
        ];
    }

    /**
     * Submitted request items with their outcome, newest first.
     * Staff see their own ($userId); custodians and managers their office's.
     *
     * @param array{status?: string, search?: string} $filters
     * @return array{rows: array, total: int, counts: array}
     */
    public function history(int $userOfficeId, ?int $userId, array $filters, int $page, int $limit): array
    {
        $scope = function ($builder) use ($userOfficeId, $userId) {
            $builder->join('temp_stockout ts', 'tsi.temp_stockout_id = ts.temp_stockout_id')
                ->join('product_table p', 'tsi.product_id = p.product_id', 'left')
                ->join('user_table req', 'ts.user_id = req.user_id', 'left')
                ->where('ts.status <>', 'draft');
            if ($userId !== null) {
                $builder->where('ts.user_id', $userId);
            } elseif ($userOfficeId > 0) {
                $builder->where('ts.user_office_id', $userOfficeId);
            }

            return $builder;
        };

        // Counts per outcome (before the status filter), for the filter chips
        $counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        $countRows = $scope($this->db->table('temp_stockout_item AS tsi'))
            ->select('tsi.status, COUNT(*) AS n')
            ->groupBy('tsi.status')
            ->get()->getResultArray();
        foreach ($countRows as $row) {
            $counts[$row['status']] = (int) $row['n'];
            $counts['all'] += (int) $row['n'];
        }

        $builder = $scope($this->db->table('temp_stockout_item AS tsi'))
            ->select('tsi.temp_stockout_item_id, tsi.temp_stockout_id, tsi.quantity, tsi.unit, tsi.description,
                      tsi.status, tsi.decision_reason, tsi.decided_at,
                      p.product AS item_name,
                      COALESCE(pc.label, "") AS copy_label,
                      COALESCE(ts.submitted_at, ts.created_at) AS submitted_at,
                      COALESCE(NULLIF(req.name, ""), req.username, "Unknown") AS requester_name,
                      COALESCE(NULLIF(dec.name, ""), dec.username, "") AS decided_by_name')
            ->join('product_copy_table pc', 'tsi.copy_id = pc.copy_id', 'left')
            ->join('user_table dec', 'tsi.decided_by = dec.user_id', 'left');

        $status = $filters['status'] ?? '';
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $builder->where('tsi.status', $status);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $builder->groupStart()
                ->like('p.product', $search)
                ->orLike('req.username', $search)
                ->orLike('req.name', $search)
                ->orLike('tsi.decision_reason', $search)
                ->orLike('tsi.temp_stockout_id', $search)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder
            ->orderBy('COALESCE(tsi.decided_at, ts.submitted_at, ts.created_at)', 'DESC', false)
            ->orderBy('tsi.temp_stockout_item_id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Recently accepted/rejected items of one user's requests, for their notifications.
     */
    public function recentDecisionsFor(int $userId, int $days = 14): array
    {
        return $this->db->table('temp_stockout_item AS tsi')
            ->select('tsi.temp_stockout_item_id, tsi.temp_stockout_id, tsi.quantity, tsi.unit, tsi.status,
                      tsi.decision_reason, tsi.decided_at, p.product AS item_name,
                      COALESCE(NULLIF(dec.name, ""), dec.username, "") AS decided_by_name')
            ->join('temp_stockout ts', 'tsi.temp_stockout_id = ts.temp_stockout_id')
            ->join('product_table p', 'tsi.product_id = p.product_id', 'left')
            ->join('user_table dec', 'tsi.decided_by = dec.user_id', 'left')
            ->where('ts.user_id', $userId)
            ->whereIn('tsi.status', ['approved', 'rejected'])
            ->where('tsi.decided_at >=', date('Y-m-d H:i:s', strtotime("-{$days} days")))
            ->orderBy('tsi.decided_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Stock that can be issued: unexpired batches only (expired stock is removed with Adjust Out).
     */
    private function availableStock(int $productId, int $userOfficeId = 0, int $copyId = 0): float
    {
        return (new InventoryService($this->db))->planDepletion($productId, 0, $userOfficeId, $copyId)['usable'];
    }

    /**
     * Deduct stock from the oldest received unexpired batches first (FIFO), like a direct issue.
     */
    private function deductStock(int $productId, float $quantity, int $userOfficeId = 0, int $copyId = 0): array
    {
        $plan = (new InventoryService($this->db))->planDepletion($productId, $quantity, $userOfficeId, $copyId, InventoryService::MODE_ISSUE);
        if ($plan['shortfall'] > 0) {
            return [];
        }

        $deductions = [];
        foreach ($plan['batches'] as $batch) {
            if ($batch['take'] <= 0) {
                continue;
            }
            $this->db->table('batch_table')
                ->where('batch_id', $batch['batch_id'])
                ->set('current_qty', 'current_qty - ' . (float) $batch['take'], false)
                ->set('updated_at', date('Y-m-d H:i:s'))
                ->update();
            $deductions[] = [
                'batch_id' => (int) $batch['batch_id'],
                'quantity' => $batch['take'],
                'copy_id'  => (int) ($batch['copy_id'] ?? 0),
            ];
        }

        return $deductions;
    }

    /**
     * Create a transaction record for a stock-out approval.
     */
    private function createStockoutTransaction(int $batchId, float $quantity, int $userOfficeId, int $userId, int $copyId = 0): void
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
    public function productArchived(int $productId): bool
    {
        $row = $this->db->table('product_table')->select('archived_at')->where('product_id', $productId)->get(1)->getRowArray();

        return ! empty($row['archived_at']);
    }

    /** Names of archived products in a request (or draft). */
    public function archivedProductsIn(int $tempStockoutId): array
    {
        return array_column($this->db->table('temp_stockout_item i')
            ->select('p.product')
            ->join('product_table p', 'p.product_id = i.product_id')
            ->where('i.temp_stockout_id', $tempStockoutId)
            ->where('p.archived_at IS NOT NULL', null, false)
            ->groupBy('p.product')
            ->get()->getResultArray(), 'product');
    }

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
                    WHERE p.user_office_id = ? AND p.archived_at IS NULL
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
                    WHERE p.archived_at IS NULL
                    GROUP BY p.product_id, p.product, p.product_description, ut.unit
                    ORDER BY p.product ASC';
        }

        return $this->db->query($sql, $params)->getResultArray();
    }
}
