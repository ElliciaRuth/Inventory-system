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

    /** Which batches a stock-out may use, see planDepletion(). */
    public const MODE_ISSUE   = 'issue';    // unexpired only, soonest expiry first (FEFO)
    public const MODE_ADJUST  = 'adjust';   // expired first, then soonest expiry
    public const MODE_EXPIRED = 'expired';  // expired batches only

    /**
     * Save one stock movement and return what it did (for the audit trail).
     *
     *   receipt    → new batch (unique batch no.) + receipt entry
     *   issue      → takes from the soonest-expiring unexpired batches of the sub-product
     *   borrow     → a borrow record (borrower, unit, qty) + stock-out entries linked to it
     *   return     → stock back in as a new batch, linked to the borrow it settles
     *   adjust_out → expired batches first ("Expired" reason: expired only), per-batch cost
     *   adjust_in  → adds to the newest batch of the sub-product at its cost
     *
     * @return array{type: string, product_id: int, quantity: float, batches: list<array>, borrow_id?: int, reason?: string}
     */
    public function saveStock(array $payload): array
    {
        $this->db->transException(true)->transStart();

        $productId    = (int) $payload['product_id'];
        $qty          = (float) $payload['quantity'];
        $typeId       = (int) ($payload['transaction_type_id'] ?? 0);
        $unitCost     = (float) ($payload['unit_cost'] ?? 0);
        $usagePct     = max(1, min(100, (int) ($payload['usage_pct'] ?? 100)));
        $officeId     = $this->resolveOfficeId($payload);
        $referenceId  = $this->resolveReferenceId($payload);
        $reasonId     = (int) ($payload['adjustment_reason_id'] ?? 0);
        $entityId     = $this->currentEntityId($productId);
        // For now every movement is stamped with the moment it is saved (no back-dating)
        $dateTime     = date('Y-m-d H:i:s');
        $expDate      = ($payload['expiration_date'] ?? '') ?: null;
        $mfgDate      = ($payload['manufacturing_date'] ?? '') ?: null;
        $dateReceived = date('Y-m-d');
        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);
        $userId       = (int) ($payload['user_id'] ?? 0);
        $copyId       = (int) ($payload['copy_id'] ?? 0);

        $typeRow = $this->db->table('transaction_type_table')
            ->select('transaction_type, transaction_type_id')
            ->where('transaction_type_id', $typeId)
            ->get(1)->getRowArray();
        $typeName = strtolower($typeRow['transaction_type'] ?? '');
        if ($typeName === '') {
            throw new DomainException('Unsupported transaction type.');
        }
        $resolvedTypeId = (int) $typeRow['transaction_type_id'];

        $officeRow = $this->db->table('user_office_table')
            ->where('user_office_id', $userOfficeId)
            ->get(1)->getRowArray();
        $userOfficeName = $officeRow['user_office_name'] ?? '';

        if ($this->isArchived($productId)) {
            throw new DomainException('This product is archived. Restore it on the Products page before recording stock for it.');
        }
        if ($entityId === null || $entityId <= 0) {
            throw new DomainException('Set an entity in Product setup before saving stock.');
        }
        if ($qty <= 0) {
            throw new DomainException('Quantity must be greater than 0.');
        }

        $result = ['type' => $typeName, 'product_id' => $productId, 'quantity' => $qty, 'batches' => []];
        $entry  = [
            'office_id'      => $officeId,
            'reference_id'   => $referenceId,
            'user_id'        => $userId,
            'user_office_id' => $userOfficeId,
            'date'           => $dateTime,
        ];

        if ($typeName === 'receipt') {
            if ($unitCost <= 0) {
                throw new DomainException('Unit cost is required for stock-in.');
            }
            foreach (['Expiration' => $expDate, 'Manufacturing' => $mfgDate] as $label => $date) {
                if ($date !== null && ! $this->isValidDate($date)) {
                    throw new DomainException("{$label} date is not a valid date.");
                }
            }
            if ($mfgDate !== null && $mfgDate > $dateReceived) {
                throw new DomainException('Manufacturing date cannot be in the future.');
            }
            if ($mfgDate !== null && $expDate !== null && $mfgDate > $expDate) {
                throw new DomainException('Manufacturing date must be on or before the expiration date.');
            }

            $copy    = (new ProductCopyModel())->findOrCreateCopy($productId, $unitCost, $userOfficeId);
            $batch   = $this->createBatch('B', $userOfficeName, $productId, (int) $copy['copy_id'], $qty, $userOfficeId, $entry, [
                'manufacturing_date' => $mfgDate,
                'expiration_date'    => $expDate,
            ]);

            $product = $this->db->table('product_table')->where('product_id', $productId)->get(1)->getRowArray();
            if (($product['stock_no'] ?? '') === '' && $userOfficeName !== '') {
                $stockNo = strtoupper($userOfficeName) . '-' . str_pad((string) ($product['product_no'] ?? $productId), 4, '0', STR_PAD_LEFT);
                $this->db->table('product_table')->where('product_id', $productId)->update(['stock_no' => $stockNo]);
            }

            $this->insertEntry($resolvedTypeId, $qty, $unitCost, $batch['batch_id'], (int) $copy['copy_id'], $entry);
            $result['batches'][] = ['batch_id' => $batch['batch_id'], 'batch_no' => $batch['batch_no'], 'qty' => $qty];
        } elseif ($typeName === 'issue') {
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to issue from.');
            }
            $effectiveQty      = $qty * $usagePct / 100;
            $result['batches'] = $this->depleteBatches($productId, $effectiveQty, $copyId, self::MODE_ISSUE, $resolvedTypeId, $unitCost, 0, $entry);
        } elseif ($typeName === 'borrow') {
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to lend from.');
            }
            $borrower = $this->borrowerFrom($payload, $userOfficeId);

            $this->db->table('borrow_table')->insert($borrower + [
                'user_office_id'      => $userOfficeId,
                'product_id'          => $productId,
                'copy_id'             => $copyId,
                'quantity'            => $qty,
                'returned_qty'        => 0,
                'status'              => 'outstanding',
                'borrowed_by_user_id' => $userId ?: null,
                'borrowed_at'         => $dateTime,
            ]);
            $borrowId = (int) $this->db->insertID();

            $result['borrow_id'] = $borrowId;
            $result['borrower']  = $borrower['borrower_name'] . ($borrower['borrower_unit'] !== '' ? " ({$borrower['borrower_unit']})" : '');
            $result['batches']   = $this->depleteBatches($productId, $qty, $copyId, self::MODE_ISSUE, $resolvedTypeId, $unitCost, 0, $entry + ['borrow_id' => $borrowId]);
        } elseif ($typeName === 'return') {
            $borrowId = (int) ($payload['borrow_id'] ?? 0);
            $borrow   = null;

            if ($borrowId > 0) {
                $borrow = $this->db->table('borrow_table')
                    ->where('borrow_id', $borrowId)
                    ->where('user_office_id', $userOfficeId)
                    ->get(1)->getRowArray();
                if (! $borrow || (int) $borrow['product_id'] !== $productId) {
                    throw new DomainException('That borrow record was not found for this product.');
                }
                $outstanding = (float) $borrow['quantity'] - (float) $borrow['returned_qty'];
                if ($outstanding <= 0) {
                    throw new DomainException('Everything from this borrow has already been returned.');
                }
                if ($qty > $outstanding + 0.0001) {
                    throw new DomainException('Only ' . $this->qtyText($outstanding) . ' is still to be returned for this borrow.');
                }
                $copyId = (int) ($borrow['copy_id'] ?: $copyId);
            } elseif ($this->db->table('borrow_table')->where('product_id', $productId)->where('user_office_id', $userOfficeId)->where('status !=', 'returned')->countAllResults() > 0) {
                throw new DomainException('Choose which borrow this return is for.');
            }

            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to return to.');
            }

            $batch = $this->createBatch('RET', $userOfficeName, $productId, $copyId, $qty, $userOfficeId, $entry, [
                'expiration_date' => $expDate,
            ]);
            $this->insertEntry($resolvedTypeId, $qty, $unitCost, $batch['batch_id'], $copyId, $entry + ['borrow_id' => $borrow ? $borrowId : null]);
            $result['batches'][] = ['batch_id' => $batch['batch_id'], 'batch_no' => $batch['batch_no'], 'qty' => $qty];

            if ($borrow) {
                $returned = (float) $borrow['returned_qty'] + $qty;
                $settled  = $returned + 0.0001 >= (float) $borrow['quantity'];
                $this->db->table('borrow_table')->where('borrow_id', $borrowId)->update([
                    'returned_qty' => $returned,
                    'status'       => $settled ? 'returned' : 'partial',
                    'returned_at'  => $settled ? $dateTime : null,
                ]);
                $result['borrow_id'] = $borrowId;
                $result['borrower']  = $borrow['borrower_name'] . ($borrow['borrower_unit'] !== '' ? " ({$borrow['borrower_unit']})" : '');
                $result['settled']   = $settled;
            }
        } elseif ($typeName === 'adjust_out') {
            $reason = $this->reasonName($reasonId);
            $mode   = strcasecmp($reason, 'Expired') === 0 ? self::MODE_EXPIRED : self::MODE_ADJUST;

            $result['reason']  = $reason;
            $result['batches'] = $this->depleteBatches($productId, $qty, $copyId, $mode, $resolvedTypeId, 0.0, $reasonId, $entry, true);
        } elseif ($typeName === 'adjust_in') {
            if ($copyId <= 0) {
                throw new DomainException('Please select a sub-product (price variant) to add the stock to.');
            }
            $result['reason']    = $this->reasonName($reasonId);
            $result['batches'][] = $this->addToLatestBatch($productId, $copyId, $qty, $resolvedTypeId, $reasonId, $entry);
        } else {
            throw new DomainException("Unsupported transaction type: '{$typeName}'.");
        }

        $this->db->transComplete();

        return $result;
    }

    /**
     * Which batches a stock-out of $qty would use, without changing anything.
     *
     *   MODE_ISSUE   unexpired batches only, soonest expiry first (batches without a date last)
     *   MODE_ADJUST  expired batches first, then soonest expiry
     *   MODE_EXPIRED expired batches only
     *
     * @return array{batches: list<array>, usable: float, expired_qty: float, shortfall: float}
     */
    public function planDepletion(int $productId, float $qty, int $userOfficeId, int $copyId = 0, string $mode = self::MODE_ISSUE): array
    {
        $builder = $this->db->table('batch_table')
            ->select('batch_id, batch_no, copy_id, current_qty, manufacturing_date, expiration_date, date_received,
                      DATEDIFF(expiration_date, CURDATE()) AS days_left', false)
            ->where('product_id', $productId)
            ->where('current_qty >', 0);
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        if ($copyId > 0) {
            $builder->where('copy_id', $copyId);
        }

        $today      = date('Y-m-d');
        $batches    = [];
        $expiredQty = 0.0;
        foreach ($builder->get()->getResultArray() as $row) {
            $row['expired']     = $row['expiration_date'] !== null && $row['expiration_date'] < $today;
            $row['current_qty'] = (float) $row['current_qty'];
            $row['days_left']   = $row['days_left'] !== null ? (int) $row['days_left'] : null;
            if ($row['expired']) {
                $expiredQty += $row['current_qty'];
            }
            $batches[] = $row;
        }

        $batches = array_values(array_filter($batches, static fn ($b) => match ($mode) {
            self::MODE_ISSUE   => ! $b['expired'],
            self::MODE_EXPIRED => $b['expired'],
            default            => true,
        }));

        usort($batches, static function (array $a, array $b) use ($mode): int {
            if ($mode === self::MODE_ADJUST && $a['expired'] !== $b['expired']) {
                return $a['expired'] ? -1 : 1;
            }
            // Soonest expiry first; undated batches after dated ones
            $ea = $a['expiration_date'] ?? '9999-12-31';
            $eb = $b['expiration_date'] ?? '9999-12-31';

            return [$ea, (string) $a['date_received'], (int) $a['batch_id']] <=> [$eb, (string) $b['date_received'], (int) $b['batch_id']];
        });

        $usable    = array_sum(array_column($batches, 'current_qty'));
        $remaining = $qty;
        foreach ($batches as &$batch) {
            $take          = min($batch['current_qty'], max(0.0, $remaining));
            $batch['take'] = round($take, 4);
            $remaining    -= $take;
        }
        unset($batch);

        return [
            'batches'     => $batches,
            'usable'      => round($usable, 4),
            'expired_qty' => round($expiredQty, 4),
            'shortfall'   => round(max(0.0, $remaining), 4),
        ];
    }

    /**
     * Next free batch number for a prefix: PREFIX-01, PREFIX-02…
     * (an older bare PREFIX counts as 01, so the next one is -02).
     */
    public function nextBatchNo(string $prefix): string
    {
        $rows = $this->db->table('batch_table')->select('batch_no')
            ->groupStart()
                ->where('batch_no', $prefix)
                ->orLike('batch_no', $prefix . '-', 'after')
            ->groupEnd()
            ->get()->getResultArray();

        $max = 0;
        foreach (array_column($rows, 'batch_no') as $no) {
            if ($no === $prefix) {
                $max = max($max, 1);
                continue;
            }
            $suffix = substr($no, strlen($prefix) + 1);
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $prefix . '-' . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Physical count: brings each product's system stock in line with what was counted.
     * Shortages leave as "Adjust Out — Physical Count" (expired batches first), surpluses come in
     * as "Adjust In — Physical Count" on the newest batch. Each count shares one reference.
     *
     * @param list<array{product_id: int, counted_qty: float}> $counts
     * @return array{reference: string, lines: list<array>}
     */
    public function reconcileCount(array $counts, int $userOfficeId, int $userId, string $note = ''): array
    {
        $this->db->transException(true)->transStart();

        $reference = 'COUNT-' . date('Ymd-His');
        $entry     = [
            'office_id'      => null,
            'reference_id'   => $this->resolveReferenceId(['reference' => $reference, 'user_office_id' => $userOfficeId]),
            'user_id'        => $userId,
            'user_office_id' => $userOfficeId,
            'date'           => date('Y-m-d H:i:s'),
        ];
        $reasonId = $this->reasonIdByName('Physical Count');
        $outType  = $this->typeIdByName('adjust_out');
        $inType   = $this->typeIdByName('adjust_in');
        $lines    = [];

        foreach ($counts as $count) {
            $productId = (int) ($count['product_id'] ?? 0);
            $counted   = round((float) ($count['counted_qty'] ?? -1), 4);
            if ($productId <= 0 || $counted < 0) {
                throw new DomainException('Every counted quantity must be 0 or more.');
            }

            $product = $this->db->table('product_table')->select('product, user_office_id, archived_at')->where('product_id', $productId)->get(1)->getRowArray();
            if (! $product || ($userOfficeId > 0 && (int) $product['user_office_id'] !== $userOfficeId)) {
                throw new DomainException('A counted product was not found.');
            }
            if (! empty($product['archived_at'])) {
                throw new DomainException('"' . $product['product'] . '" is archived; restore it before counting it.');
            }

            $system   = round($this->currentStock($productId, $userOfficeId), 4);
            $variance = round($counted - $system, 4);
            if (abs($variance) < 0.0001) {
                continue;
            }

            $batches = $variance < 0
                ? $this->depleteBatches($productId, -$variance, 0, self::MODE_ADJUST, $outType, 0.0, $reasonId, $entry, true)
                : [$this->addToLatestBatch($productId, 0, $variance, $inType, $reasonId, $entry)];

            $lines[] = [
                'product_id' => $productId,
                'product'    => $product['product'],
                'system_qty' => $system,
                'counted'    => $counted,
                'variance'   => $variance,
                'batches'    => $batches,
            ];
        }

        $this->db->transComplete();

        return ['reference' => $reference, 'note' => $note, 'lines' => $lines];
    }

    /**
     * Borrows of a product (or all products) for the office, newest first.
     */
    public function borrows(int $userOfficeId, int $productId = 0, string $status = ''): array
    {
        $builder = $this->db->table('borrow_table bt')
            ->select('bt.*, p.product, COALESCE(pc.unit_cost, 0) AS unit_cost, COALESCE(pc.label, "") AS copy_label,
                      COALESCE(ut.unit, "pcs") AS unit_name, u.username AS borrowed_by,
                      (bt.quantity - bt.returned_qty) AS outstanding_qty', false)
            ->join('product_table p', 'p.product_id = bt.product_id')
            ->join('product_copy_table pc', 'pc.copy_id = bt.copy_id', 'left')
            ->join('unit_table ut', 'ut.unit_id = p.unit_id', 'left')
            ->join('user_table u', 'u.user_id = bt.borrowed_by_user_id', 'left')
            ->orderBy('bt.borrowed_at', 'DESC');

        if ($userOfficeId > 0) {
            $builder->where('bt.user_office_id', $userOfficeId);
        }
        if ($productId > 0) {
            $builder->where('bt.product_id', $productId);
        }
        if ($status === 'open') {
            $builder->where('bt.status !=', 'returned');
        } elseif (in_array($status, ['outstanding', 'partial', 'returned'], true)) {
            $builder->where('bt.status', $status);
        }

        return $builder->get()->getResultArray();
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
     * Take $qty out of the batches chosen by planDepletion(), one ledger entry per batch.
     * $perBatchCost records each batch's own cost (adjustments), otherwise $unitCost.
     *
     * @return list<array{batch_id: int, batch_no: string, qty: float, expiration_date: ?string}>
     */
    private function depleteBatches(
        int $productId,
        float $qty,
        int $copyId,
        string $mode,
        int $transactionTypeId,
        float $unitCost,
        int $reasonId,
        array $entry,
        bool $perBatchCost = false
    ): array {
        $plan = $this->planDepletion($productId, $qty, (int) $entry['user_office_id'], $copyId, $mode);

        if ($plan['shortfall'] > 0) {
            $usable  = $this->qtyText($plan['usable']);
            $expired = $plan['expired_qty'];
            throw new DomainException(match ($mode) {
                self::MODE_ISSUE => "Only {$usable} can be taken out" . ($copyId > 0 ? ' of this sub-product' : '')
                    . ($expired > 0 ? '; another ' . $this->qtyText($expired) . ' is expired and has to be removed with Adjust Out, reason "Expired".' : '.'),
                self::MODE_EXPIRED => "Only {$usable} of this stock is expired.",
                default            => "Only {$usable} is in stock.",
            });
        }

        $used = [];
        foreach ($plan['batches'] as $batch) {
            if ($batch['take'] <= 0) {
                continue;
            }

            $this->db->table('batch_table')
                ->where('batch_id', $batch['batch_id'])
                ->update([
                    'current_qty' => $batch['current_qty'] - $batch['take'],
                    'updated_at'  => $entry['date'],
                ]);

            $cost = $perBatchCost ? $this->batchCost((int) $batch['batch_id']) : $unitCost;
            $this->insertEntry(
                $transactionTypeId,
                $batch['take'],
                $cost,
                (int) $batch['batch_id'],
                $copyId > 0 ? $copyId : (int) ($batch['copy_id'] ?? 0),
                $entry + ['reason_id' => $reasonId]
            );

            $used[] = [
                'batch_id'        => (int) $batch['batch_id'],
                'batch_no'        => $batch['batch_no'],
                'qty'             => $batch['take'],
                'expiration_date' => $batch['expiration_date'],
            ];
        }

        return $used;
    }

    /**
     * Adjust In: add to the newest unexpired batch (newest of any, if all are expired)
     * of the sub-product, or of the product when $copyId is 0.
     */
    private function addToLatestBatch(int $productId, int $copyId, float $qty, int $typeId, int $reasonId, array $entry): array
    {
        $builder = $this->db->table('batch_table')
            ->where('product_id', $productId)
            ->where('user_office_id', $entry['user_office_id']);
        if ($copyId > 0) {
            $builder->where('copy_id', $copyId);
        }
        $batch = $builder
            ->orderBy('(expiration_date IS NOT NULL AND expiration_date < CURDATE())', 'ASC', false)
            ->orderBy('date_received', 'DESC')
            ->orderBy('batch_id', 'DESC')
            ->get(1)->getRowArray();

        if (! $batch) {
            throw new DomainException('This product has no batch yet; record a Stock In (receipt) instead.');
        }

        $this->db->table('batch_table')->where('batch_id', $batch['batch_id'])->update([
            'current_qty' => (float) $batch['current_qty'] + $qty,
            'updated_at'  => $entry['date'],
        ]);

        $this->insertEntry($typeId, $qty, $this->batchCost((int) $batch['batch_id']), (int) $batch['batch_id'], (int) ($batch['copy_id'] ?? 0), $entry + ['reason_id' => $reasonId]);

        return ['batch_id' => (int) $batch['batch_id'], 'batch_no' => $batch['batch_no'], 'qty' => $qty, 'expiration_date' => $batch['expiration_date']];
    }

    /**
     * New batch with a unique number (PREFIX-OFFICE-YYYYMMDD-PRODUCT-NN) and its Code 128 barcode.
     */
    private function createBatch(string $kind, string $officeName, int $productId, int $copyId, float $qty, int $userOfficeId, array $entry, array $extra = []): array
    {
        $prefix  = $kind . '-' . strtoupper($officeName) . '-' . date('Ymd') . '-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);
        $batchNo = $this->nextBatchNo($prefix);

        $this->db->table('batch_table')->insert($extra + [
            'batch_no'       => $batchNo,
            'product_id'     => $productId,
            'copy_id'        => $copyId ?: null,
            'user_office_id' => $userOfficeId,
            'reference_id'   => $entry['reference_id'] ?: null,
            'office_id'      => $entry['office_id'] ?: null,
            'current_qty'    => $qty,
            'date_received'  => date('Y-m-d'),
            'created_at'     => $entry['date'],
            'updated_at'     => $entry['date'],
        ]);
        $batchId = (int) $this->db->insertID();

        $barcodeService = new BarcodeService();
        $barcodeValue   = $barcodeService->generateBatchValue($batchId);
        $barcodeService->saveBatchBarcode($barcodeValue);
        $this->db->table('batch_table')->where('batch_id', $batchId)->update(['barcode_value' => $barcodeValue]);

        return ['batch_id' => $batchId, 'batch_no' => $batchNo];
    }

    /** One ledger entry. $entry carries office, reference, user, date and optional reason/borrow ids. */
    private function insertEntry(int $typeId, float $qty, float $unitCost, int $batchId, int $copyId, array $entry): void
    {
        $this->db->table('transaction_table')->insert([
            'transaction_type_id'   => $typeId,
            'transaction_qty'       => $qty,
            'transaction_unit_cost' => $unitCost,
            'transaction_date'      => $entry['date'],
            'batch_id'              => $batchId,
            'copy_id'               => $copyId ?: null,
            'reference_id'          => $entry['reference_id'] ?: null,
            'office_id'             => $entry['office_id'] ?: null,
            'user_id'               => $entry['user_id'] ?: null,
            'user_office_id'        => $entry['user_office_id'],
            'adjustment_reason_id'  => ($entry['reason_id'] ?? 0) ?: null,
            'borrow_id'             => ($entry['borrow_id'] ?? 0) ?: null,
            'created_at'            => $entry['date'],
            'updated_at'            => $entry['date'],
        ]);
    }

    /** What one unit of a batch cost: its stock-in entry, else its sub-product's price. */
    private function batchCost(int $batchId): float
    {
        $row = $this->db->table('transaction_table t')
            ->select('t.transaction_unit_cost')
            ->join('transaction_type_table tt', 'tt.transaction_type_id = t.transaction_type_id')
            ->where('t.batch_id', $batchId)
            ->whereIn('tt.transaction_type', ['receipt', 'return'])
            ->where('t.transaction_unit_cost >', 0)
            ->orderBy('t.transaction_id', 'ASC')
            ->get(1)->getRowArray();
        if ($row) {
            return (float) $row['transaction_unit_cost'];
        }

        $copy = $this->db->table('batch_table b')
            ->select('pc.unit_cost')
            ->join('product_copy_table pc', 'pc.copy_id = b.copy_id')
            ->where('b.batch_id', $batchId)
            ->get(1)->getRowArray();

        return (float) ($copy['unit_cost'] ?? 0);
    }

    /** Borrower name and unit from the request (unit: one of the user offices, or free text). */
    private function borrowerFrom(array $payload, int $userOfficeId): array
    {
        $name = trim((string) ($payload['borrower_name'] ?? ''));
        if (mb_strlen($name) < 2) {
            throw new DomainException('Enter the name of the person borrowing.');
        }

        $unitId = (int) ($payload['borrower_user_office_id'] ?? 0);
        $unit   = trim((string) ($payload['borrower_unit'] ?? ''));
        if ($unitId > 0) {
            if ($unitId === $userOfficeId) {
                throw new DomainException('The borrowing unit must be a different unit from yours.');
            }
            $row  = $this->db->table('user_office_table')->select('user_office_name')->where('user_office_id', $unitId)->get(1)->getRowArray();
            $unit = $row['user_office_name'] ?? $unit;
        }
        if ($unit === '') {
            throw new DomainException('Choose the unit that is borrowing.');
        }

        $due = trim((string) ($payload['due_date'] ?? ''));
        if ($due !== '' && ! $this->isValidDate($due)) {
            throw new DomainException('The return date is not a valid date.');
        }

        return [
            'borrower_name'           => mb_substr($name, 0, 150),
            'borrower_user_office_id' => $unitId ?: null,
            'borrower_unit'           => mb_substr($unit, 0, 150),
            'due_date'                => $due ?: null,
            'notes'                   => mb_substr(trim((string) ($payload['borrow_notes'] ?? '')), 0, 500),
        ];
    }

    private function isArchived(int $productId): bool
    {
        $row = $this->db->table('product_table')->select('archived_at')->where('product_id', $productId)->get(1)->getRowArray();

        return ! empty($row['archived_at']);
    }

    private function reasonName(int $reasonId): string
    {
        if ($reasonId <= 0) {
            return '';
        }
        $row = $this->db->table('adjustment_reason')->where('adjustment_reason_id', $reasonId)->get(1)->getRowArray();

        return (string) ($row['adjustment_reason'] ?? '');
    }

    private function reasonIdByName(string $name): int
    {
        $row = $this->db->table('adjustment_reason')->where('adjustment_reason', $name)->get(1)->getRowArray();
        if ($row) {
            return (int) $row['adjustment_reason_id'];
        }
        $this->db->table('adjustment_reason')->insert(['adjustment_reason' => $name]);

        return (int) $this->db->insertID();
    }

    private function typeIdByName(string $name): int
    {
        $row = $this->db->table('transaction_type_table')->where('transaction_type', $name)->get(1)->getRowArray();
        if (! $row) {
            throw new DomainException("Transaction type '{$name}' is missing; run the database migrations.");
        }

        return (int) $row['transaction_type_id'];
    }

    private function qtyText(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** A real calendar date in Y-m-d form (rejects 2026-02-30 and the like). */
    private function isValidDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function resolveReferenceId(array $payload): ?int
    {
        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);

        // An id sent by the browser counts only when it is one of this office's references
        $referenceId = (int) ($payload['reference_id'] ?? 0);
        if ($referenceId > 0 && $this->belongsToOffice('reference_table', 'reference_id', $referenceId, $userOfficeId)) {
            return $referenceId;
        }

        $referenceText = trim((string) ($payload['reference'] ?? ''));
        if ($referenceText === '') {
            return null;
        }

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
        $userOfficeId = (int) ($payload['user_office_id'] ?? 0);

        // An id sent by the browser counts only when it is one of this office's destinations
        $officeId = (int) ($payload['office_id'] ?? 0);
        if ($officeId > 0 && $this->belongsToOffice('office_table', 'office_id', $officeId, $userOfficeId)) {
            return $officeId;
        }

        $officeText = trim((string) ($payload['office'] ?? ''));
        if ($officeText === '') {
            return null;
        }

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

    /** Whether a row of an office-owned lookup table belongs to the office (any office for a global account). */
    private function belongsToOffice(string $table, string $pk, int $id, int $userOfficeId): bool
    {
        $builder = $this->db->table($table)->where($pk, $id);
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        return $builder->countAllResults() > 0;
    }
}





