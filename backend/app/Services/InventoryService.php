<?php

namespace App\Services;

use App\Models\ProductCopyModel;
use CodeIgniter\Database\BaseConnection;
use DomainException;

class InventoryService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function currentReorderPoint(int $productId): int
    {
        $row = $this->db->query(
            'SELECT COALESCE(product_reorder_point, 0) AS product_reorder_point
             FROM product_table
             WHERE product_id = ?
             LIMIT 1',
            [$productId],
        )->getRowArray();

        return (int) ($row['product_reorder_point'] ?? 0);
    }

    public function currentEntityId(int $productId): ?int
    {
        $row = $this->db->query(
            'SELECT entity_id FROM product_table WHERE product_id = ? LIMIT 1',
            [$productId],
        )->getRowArray();

        if (! $row || $row['entity_id'] === null) {
            return null;
        }

        return (int) $row['entity_id'];
    }

    /**
     * Current stock for a product = SUM of batch_table.current_qty.
     */
    public function currentStock(int $productId, int $userOfficeId = 0): float
    {
        $builder = $this->db->table('batch_table')
            ->selectSum('current_qty', 'stock')
            ->where('product_id', $productId);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        $row = $builder->get()->getRowArray();
        return (float) ($row['stock'] ?? 0);
    }

    /**
     * Save a stock-in or stock-out transaction.
     * Stock-in  â†’ creates a new batch_table row + transaction_table (receipt).
     * Stock-out â†’ depletes existing batches FIFO + transaction_table (issue).
     */
    public function saveStock(array $payload): void
    {
        $this->db->transException(true)->transStart();

        $productId   = (int) $payload['product_id'];
        $qty         = (float) $payload['quantity'];
        $typeId      = (int) ($payload['transaction_type_id'] ?? 0);
        $legacyType  = (string) ($payload['adjust_type'] ?? '');
        $unitCost    = (float) ($payload['unit_cost'] ?? 0);
        $usagePct    = max(1, min(100, (int) ($payload['usage_pct'] ?? 100)));
        $officeId    = $this->resolveOfficeId($payload);
        $referenceId = $this->resolveReferenceId($payload);
        $reasonId    = (int) ($payload['adjustment_reason_id'] ?? 0);
        $entityId    = $this->currentEntityId($productId);
        $dateTime    = $this->dateTime($payload['date'] ?? date('Y-m-d'));
        $expDate     = ($payload['expiration_date'] ?? '') ?: null;
        $dateReceived = $payload['date'] ?? date('Y-m-d');
        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);
        $userId      = (int) ($payload['user_id'] ?? 0);
        $copyId      = (int) ($payload['copy_id'] ?? 0);

        if ($typeId <= 0) {
            $typeId = match ($legacyType) {
                'IN'      => 1,
                'OUT'     => 2,
                'SPOILED' => 3,
                'BORROW'  => 4,
                'RETURN'  => 5,
                default   => 1,
            };
        }

        $officeRow = $this->db->table('user_office_table')
            ->where('user_office_id', $userOfficeId)
            ->get(1)->getRowArray();
        $userOfficeName = $officeRow['user_office_name'] ?? '';

        if ($entityId === null || $entityId <= 0) {
            throw new DomainException('Set an entity in Product setup before saving stock.');
        }

        if ($qty <= 0) {
            throw new DomainException('Quantity must be greater than 0.');
        }

        // ── Resolve the type name from the DB (so we don't depend on hardcoded IDs) ──
        $typeRow = $this->db->table('transaction_type_table')
            ->select('transaction_type, transaction_type_id')
            ->where('transaction_type_id', $typeId)
            ->get(1)->getRowArray();
        $typeName = strtolower($typeRow['transaction_type'] ?? '');

        if ($typeName === '') {
            throw new DomainException('Unsupported transaction type.');
        }

        // Use the actual DB ID for writing to transaction_table
        $resolvedTypeId = (int) ($typeRow['transaction_type_id'] ?? $typeId);

        if ($typeName === 'receipt') {
            if ($unitCost <= 0) {
                throw new DomainException('Unit cost is required for stock-in.');
            }

            // ── Auto-create or find copy for this price ─────────────────────
            $copyModel   = new ProductCopyModel();
            $copy        = $copyModel->findOrCreateCopy($productId, $unitCost, $userOfficeId);
            $resolvedCopyId = (int) $copy['copy_id'];

            $batchNo = 'B-' . strtoupper($userOfficeName) . '-' . date('Ymd') . '-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);

            $this->db->table('batch_table')->insert([
                'batch_no'            => $batchNo,
                'product_id'          => $productId,
                'copy_id'             => $resolvedCopyId,
                'expiration_date'     => $expDate,
                'user_office_id'      => $userOfficeId,
                'reference_id'        => $referenceId ?: null,
                'office_id'           => $officeId,
                'current_qty'         => $qty,
                'date_received'       => $dateReceived,
                'created_at'          => $dateTime,
                'updated_at'          => $dateTime,
            ]);
            $batchId = (int) $this->db->insertID();

            // ── Auto-generate Code 128 barcode for this batch ──────────────────
            $barcodeService = new \App\Services\BarcodeService();
            $barcodeValue   = $barcodeService->generateBatchValue($batchId);
            $barcodeService->saveBatchBarcode($barcodeValue);          // writes SVG to disk
            $this->db->table('batch_table')
                ->where('batch_id', $batchId)
                ->update(['barcode_value' => $barcodeValue]);
            // ───────────────────────────────────────────────────────────────────

            $product = $this->db->table('product_table')->where('product_id', $productId)->get(1)->getRowArray();
            if (($product['stock_no'] ?? '') === '' && $userOfficeName !== '') {
                $stockNo = strtoupper($userOfficeName) . '-' . str_pad((string) ($product['product_no'] ?? $productId), 4, '0', STR_PAD_LEFT);
                $this->db->table('product_table')->where('product_id', $productId)->update(['stock_no' => $stockNo]);
            }

            $this->db->table('transaction_table')->insert([
                'transaction_type_id'   => $resolvedTypeId,
                'transaction_qty'       => $qty,
                'transaction_unit_cost' => $unitCost,
                'transaction_date'      => $dateTime,
                'batch_id'              => $batchId,
                'copy_id'              => $resolvedCopyId,
                'reference_id'          => $referenceId ?: null,
                'office_id'             => $officeId,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $userOfficeId,
                'adjustment_reason_id'  => null,
                'created_at'            => $dateTime,
                'updated_at'            => $dateTime,
            ]);
        } elseif ($typeName === 'issue') {
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to issue from.');
            }
            $currentStock = $this->currentCopyStock($copyId, $userOfficeId);
            if ($currentStock <= 0) {
                throw new DomainException('Cannot issue stock — there is no stock available for this sub-product.');
            }
            if ($qty > $currentStock) {
                throw new DomainException("Cannot issue {$qty} — only {$currentStock} unit(s) available in this sub-product.");
            }
            $effectiveQty = $qty * $usagePct / 100;
            $this->depleteBatches($productId, $effectiveQty, $userOfficeId, $officeId, $referenceId, $userId, $dateTime, 0, $resolvedTypeId, $unitCost, $copyId);
        } elseif ($typeName === 'borrow') {
            // ── Borrow: behaves like issue (FIFO stock depletion) ────────────
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to borrow from.');
            }
            $currentStock = $this->currentCopyStock($copyId, $userOfficeId);
            if ($currentStock <= 0) {
                throw new DomainException('Cannot borrow — there is no stock available for this sub-product.');
            }
            if ($qty > $currentStock) {
                throw new DomainException("Cannot borrow {$qty} — only {$currentStock} unit(s) available in this sub-product.");
            }
            $this->depleteBatches($productId, $qty, $userOfficeId, $officeId, $referenceId, $userId, $dateTime, 0, $resolvedTypeId, $unitCost, $copyId);
        } elseif ($typeName === 'return') {
            // ── Return: creates a new batch and a receipt-style transaction ──
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to return to.');
            }
            $batchNo = 'RET-' . strtoupper($userOfficeName) . '-' . date('Ymd') . '-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);

            $this->db->table('batch_table')->insert([
                'batch_no'        => $batchNo,
                'product_id'      => $productId,
                'copy_id'         => $copyId,
                'expiration_date' => $expDate,
                'user_office_id'  => $userOfficeId,
                'reference_id'    => $referenceId ?: null,
                'office_id'       => $officeId,
                'current_qty'     => $qty,
                'date_received'   => $dateReceived,
                'created_at'      => $dateTime,
                'updated_at'      => $dateTime,
            ]);
            $returnBatchId = (int) $this->db->insertID();

            // ── Auto-generate Code 128 barcode for return batch ─────────────
            $barcodeService = new \App\Services\BarcodeService();
            $barcodeValue   = $barcodeService->generateBatchValue($returnBatchId);
            $barcodeService->saveBatchBarcode($barcodeValue);
            $this->db->table('batch_table')
                ->where('batch_id', $returnBatchId)
                ->update(['barcode_value' => $barcodeValue]);
            // ────────────────────────────────────────────────────────────────

            $this->db->table('transaction_table')->insert([
                'transaction_type_id'   => $resolvedTypeId,
                'transaction_qty'       => $qty,
                'transaction_unit_cost' => $unitCost,
                'transaction_date'      => $dateTime,
                'batch_id'              => $returnBatchId,
                'copy_id'              => $copyId,
                'reference_id'          => $referenceId ?: null,
                'office_id'             => $officeId,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $userOfficeId,
                'adjustment_reason_id'  => null,
                'created_at'            => $dateTime,
                'updated_at'            => $dateTime,
            ]);
        } elseif ($typeName === 'adjust_out') {
            $latestBatch = $this->db->table('batch_table')
                ->where('product_id', $productId)
                ->where('user_office_id', $userOfficeId)
                ->where('current_qty >', 0)
                ->orderBy('date_received', 'DESC')
                ->orderBy('batch_id', 'DESC')
                ->get(1)
                ->getRowArray();

            if (! $latestBatch) {
                throw new DomainException('No receipt batch found to spoil from.');
            }

            $latestQty = (int) $latestBatch['current_qty'];
            if ($qty > $latestQty) {
                throw new DomainException('Adjustment out quantity cannot exceed the latest receipt batch quantity.');
            }

            $receiptRow = $this->db->table('transaction_type_table')
                ->select('transaction_type_id')
                ->where('transaction_type', 'receipt')
                ->get(1)->getRowArray();
            $receiptTypeId = (int) ($receiptRow['transaction_type_id'] ?? 1);

            $costRow = $this->db->table('transaction_table')
                ->select('transaction_unit_cost')
                ->where('batch_id', $latestBatch['batch_id'])
                ->whereIn('transaction_type_id', [$receiptTypeId])
                ->orderBy('transaction_id', 'DESC')
                ->get(1)
                ->getRowArray();
            $spoiledUnitCost = (float) ($costRow['transaction_unit_cost'] ?? 0);

            $this->db->table('batch_table')
                ->where('batch_id', $latestBatch['batch_id'])
                ->update([
                    'current_qty' => $latestQty - $qty,
                    'updated_at'  => $dateTime,
                ]);

            $this->db->table('transaction_table')->insert([
                'transaction_type_id'   => $resolvedTypeId,
                'transaction_qty'       => $qty,
                'transaction_unit_cost' => $spoiledUnitCost,
                'transaction_date'      => $dateTime,
                'batch_id'              => (int) $latestBatch['batch_id'],
                'reference_id'          => $referenceId ?: null,
                'office_id'             => $officeId,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $userOfficeId,
                'adjustment_reason_id'  => $reasonId ?: null,
                'created_at'            => $dateTime,
                'updated_at'            => $dateTime,
            ]);
        } else {
            throw new DomainException("Unsupported transaction type: '{$typeName}'.");
        }

        $this->db->transComplete();
    }

    /**
     * Save a stock adjustment â€” silently corrects batch_table.current_qty.
     * No transaction_table record is created; adjustments never appear in
     * the stockcard or reports.
     *
     * IN  â†’ adds qty to the most recent non-zero batch, or creates a
     *        minimal correction batch if none exists.
     * OUT â†’ removes qty from batches using FIFO depletion.
     */
    public function adjustStock(array $payload): void
    {
        $this->db->transException(true)->transStart();

        $productId    = (int) $payload['product_id'];
        $qty          = (float) $payload['quantity'];
        $type         = $payload['adjust_type'];
        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);
        $userId       = (int) ($payload['user_id'] ?? 0);
        $now          = date('Y-m-d H:i:s');

        if ($productId <= 0) {
            throw new DomainException('Select a product first.');
        }

        if ($qty <= 0) {
            throw new DomainException('Quantity must be greater than 0.');
        }

        if ($type === 'OUT') {
            $currentStock = $this->currentStock($productId, $userOfficeId);
            if ($qty > $currentStock) {
                throw new DomainException('Not enough stock to subtract.');
            }

            $batches = $this->db->table('batch_table')
                ->where('product_id', $productId)
                ->where('current_qty >', 0)
                ->where('user_office_id', $userOfficeId)
                ->orderBy('date_received', 'ASC')
                ->orderBy('batch_id', 'ASC')
                ->get()
                ->getResultArray();

            $remaining = $qty;
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((float) $batch['current_qty'], $remaining);
                $this->db->table('batch_table')
                    ->where('batch_id', $batch['batch_id'])
                    ->update([
                        'current_qty' => (float) $batch['current_qty'] - $take,
                        'updated_at'  => $now,
                    ]);
                $remaining -= $take;
            }
        } elseif ($type === 'SPOILED') {
            $latestBatch = $this->db->table('batch_table')
                ->where('product_id', $productId)
                ->where('user_office_id', $userOfficeId)
                ->where('current_qty >', 0)
                ->orderBy('date_received', 'DESC')
                ->orderBy('batch_id', 'DESC')
                ->get(1)
                ->getRowArray();

            if (! $latestBatch) {
                throw new DomainException('No receipt batch found to spoil from.');
            }

            $latestQty = (float) $latestBatch['current_qty'];
            if ($qty > $latestQty) {
                throw new DomainException('Spoiled quantity cannot exceed the latest receipt batch quantity.');
            }

            $receiptRow = $this->db->table('transaction_table')
                ->select('transaction_unit_cost')
                ->where('batch_id', $latestBatch['batch_id'])
                ->whereIn('transaction_type_id', [1])
                ->orderBy('transaction_id', 'DESC')
                ->get(1)
                ->getRowArray();
            $spoiledUnitCost = (float) ($receiptRow['transaction_unit_cost'] ?? 0);

            $this->db->table('batch_table')
                ->where('batch_id', $latestBatch['batch_id'])
                ->update([
                    'current_qty' => $latestQty - $qty,
                    'updated_at'  => $now,
                ]);

            $this->db->table('transaction_table')->insert([
                'transaction_type_id'   => 3,
                'transaction_qty'       => $qty,
                'transaction_unit_cost' => $spoiledUnitCost,
                'transaction_date'      => $now,
                'batch_id'              => (int) $latestBatch['batch_id'],
                'reference_id'          => null,
                'office_id'             => null,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $userOfficeId,
                'adjustment_reason_id'  => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } else {
            $latestBatch = $this->db->table('batch_table')
                ->where('product_id', $productId)
                ->where('user_office_id', $userOfficeId)
                ->where('current_qty >', 0)
                ->orderBy('date_received', 'DESC')
                ->orderBy('batch_id', 'DESC')
                ->get(1)
                ->getRowArray();

            if ($latestBatch) {
                $this->db->table('batch_table')
                    ->where('batch_id', $latestBatch['batch_id'])
                    ->update([
                        'current_qty' => (float) $latestBatch['current_qty'] + $qty,
                        'updated_at'  => $now,
                    ]);
            } else {
                $this->db->table('batch_table')->insert([
                    'batch_no'        => 'ADJ-' . date('YmdHis') . '-P' . $productId,
                    'product_id'      => $productId,
                    'user_office_id'  => $userOfficeId,
                    'current_qty'     => $qty,
                    'date_received'   => date('Y-m-d'),
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
        }

        $this->db->transComplete();
    }

    /**
     * Get current stock for a specific copy.
     */
    public function currentCopyStock(int $copyId, int $userOfficeId = 0): float
    {
        $builder = $this->db->table('batch_table')
            ->selectSum('current_qty', 'stock')
            ->where('copy_id', $copyId);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        $row = $builder->get()->getRowArray();
        return (float) ($row['stock'] ?? 0);
    }

    /**
     * Deplete batches FIFO and create transaction records.
     * When $copyId is provided, only batches belonging to that copy are depleted.
     */
    private function depleteBatches(
        int $productId,
        float $qty,
        int $userOfficeId,
        ?int $officeId,
        ?int $referenceId,
        ?int $userId,
        string $dateTime,
        ?int $reasonId = 0,
        int $transactionTypeId = 2,
        float $unitCost = 0.0,
        int $copyId = 0
    ): void {
        $builder = $this->db->table('batch_table')
            ->where('product_id', $productId)
            ->where('current_qty >', 0);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        if ($copyId > 0) {
            $builder->where('copy_id', $copyId);
        }

        $batches   = $builder->orderBy('date_received', 'ASC')->orderBy('batch_id', 'ASC')->get()->getResultArray();
        $remaining = $qty;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $batch['current_qty'], $remaining);

            $this->db->table('batch_table')
                ->where('batch_id', $batch['batch_id'])
                ->update([
                    'current_qty' => (float) $batch['current_qty'] - $take,
                    'updated_at'  => $dateTime,
                ]);

            $this->db->table('transaction_table')->insert([
                'transaction_type_id'   => $transactionTypeId,
                'transaction_qty'       => $take,
                'transaction_unit_cost' => $unitCost,
                'transaction_date'      => $dateTime,
                'batch_id'              => (int) $batch['batch_id'],
                'copy_id'              => $copyId > 0 ? $copyId : ($batch['copy_id'] ?? null),
                'reference_id'          => $referenceId ?: null,
                'office_id'             => $officeId ?: null,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $userOfficeId,
                'adjustment_reason_id'  => $reasonId ?: null,
                'created_at'            => $dateTime,
                'updated_at'            => $dateTime,
            ]);

            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw new DomainException('Insufficient batch stock.');
        }
    }

    private function dateTime(string $date): string
    {
        return date('Y-m-d H:i:s', strtotime($date . ' ' . date('H:i:s')));
    }

    private function resolveReferenceId(array $payload): ?int
    {
        $referenceId = (int) ($payload['reference_id'] ?? 0);
        if ($referenceId > 0) {
            return $referenceId;
        }

        $referenceText = trim((string) ($payload['reference'] ?? ''));
        if ($referenceText === '') {
            return null;
        }

        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);

        $builder = $this->db->table('reference_table')
            ->select('reference_id')
            ->where('reference', $referenceText);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        $existing = $builder->get(1)->getRowArray();

        if ($existing) {
            return (int) $existing['reference_id'];
        }

        $insertData = ['reference' => $referenceText];
        if ($userOfficeId > 0) {
            $insertData['user_office_id'] = $userOfficeId;
        }

        $this->db->table('reference_table')->insert($insertData);

        return (int) $this->db->insertID();
    }
    private function resolveOfficeId(array $payload): ?int
    {
        $officeId = (int) ($payload['office_id'] ?? 0);
        if ($officeId > 0) {
            return $officeId;
        }

        $officeText = trim((string) ($payload['office'] ?? ''));
        if ($officeText === '') {
            return null;
        }

        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);

        $builder = $this->db->table('office_table')
            ->select('office_id')
            ->where('office_name', $officeText);

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        $existing = $builder->get(1)->getRowArray();
        if ($existing) {
            return (int) $existing['office_id'];
        }

        $insertData = ['office_name' => $officeText];
        if ($userOfficeId > 0) {
            $insertData['user_office_id'] = $userOfficeId;
        }

        $this->db->table('office_table')->insert($insertData);

        return (int) $this->db->insertID();
    }
}





