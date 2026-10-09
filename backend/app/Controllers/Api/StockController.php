<?php

namespace App\Controllers\Api;

use App\Libraries\AuditLog;
use App\Models\AdjustmentReasonModel;
use App\Models\OfficeModel;
use App\Models\ProductCopyModel;
use App\Models\ProductModel;
use App\Models\ReferenceModel;
use App\Models\TransactionModel;
use App\Services\InventoryService;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

class StockController extends BaseApiController
{
    /** Most counted items one physical count may submit. */
    private const MAX_COUNT_LINES = 1500;

    private ProductModel $productModel;
    private TransactionModel $transactionModel;

    public function __construct()
    {
        $this->productModel     = new ProductModel();
        $this->transactionModel = new TransactionModel();
    }

    /**
     * Stockcard history and product metadata.
     * GET /api/stockcard
     */
    public function stockcard(): ResponseInterface
    {
        $officeId   = $this->currentOfficeId();
        $limit      = max(5, min(100, (int) ($this->request->getGet('limit') ?? 15)));
        $page       = max(1, (int) ($this->request->getGet('page') ?? 1));
        $filterType = $this->request->getGet('filter_type') === 'oldest' ? 'oldest' : 'latest';
        $year       = (int) ($this->request->getGet('year') ?? 0);
        $month      = trim((string) ($this->request->getGet('month') ?? ''));
        $search     = trim((string) ($this->request->getGet('search') ?? ''));

        $productId  = (int) ($this->request->getGet('item_id') ?? 0);
        if ($productId === 0 || ! $this->productInOffice($productId)) {
            // Another office's product id: show this office's first product instead of its card
            $productId = $this->productModel->firstProductId($officeId);
        }

        $itemInfo   = [];
        $batches    = [];
        $stockcard  = [];
        $totalPages = 1;
        $total      = 0;

        if ($productId > 0) {
            $itemInfo = $this->productModel->stockcardInfo($productId);
            $batches  = $this->productModel->datedBatches($productId, $officeId);
            $history  = $this->transactionModel->paginatedHistory(
                $productId, $filterType, $page, $limit, $year, $month, $search, $officeId
            );
            $stockcard  = $history['rows'] ?? [];
            $total      = (int) ($history['total'] ?? 0);
            $totalPages = max(1, (int) ceil($total / $limit));
        }

        return $this->respondSuccess([
            'itemId'     => $productId,
            'itemInfo'   => $itemInfo,
            'batches'    => $batches,
            'items'      => $this->productModel->listForSelect($officeId, $productId),
            'stockcard'  => $stockcard,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => $totalPages,
            'limit'      => $limit,
            'filterType' => $filterType,
        ], 'Stockcard retrieved');
    }

    /**
     * Dropdown options for Stock operations (Stock In / Stock Out / Adjust).
     * GET /api/stock/options
     */
    public function options(): ResponseInterface
    {
        $officeId       = $this->currentOfficeId();
        $db             = db_connect();
        $officeModel    = new OfficeModel();
        $referenceModel = new ReferenceModel();
        $reasonModel    = new AdjustmentReasonModel();
        $copyModel      = new ProductCopyModel();

        $items = $this->productModel->listForSelect($officeId);
        $stockMap = [];
        foreach ($items as &$item) {
            $pid = (int) $item['product_id'];
            $stock = $this->transactionModel->currentStock($pid, $officeId);
            $item['current_stock'] = $stock;
            $stockMap[$pid] = $stock;
        }
        unset($item);

        return $this->respondSuccess([
            'items'             => $items,
            'stockMap'          => $stockMap,
            'offices'           => $officeModel->orderedList($officeId),
            'references'        => $referenceModel->orderedList($officeId),
            'transactionTypes'  => $db->table('transaction_type_table')->orderBy('transaction_type_id', 'ASC')->get()->getResultArray(),
            'adjustmentReasons' => $reasonModel->orderedList(),
            'copiesMap'         => $copyModel->allCopiesGrouped($officeId),
            // Units that can borrow from this one (the other Bakery / FPC offices)
            'borrowerUnits'     => $db->table('user_office_table')->select('user_office_id, user_office_name')
                ->where('user_office_id !=', $officeId)->orderBy('user_office_name', 'ASC')->get()->getResultArray(),
        ], 'Stock options retrieved');
    }

    /**
     * Save a stock transaction (Stock-In, Stock-Out, Adjust, etc.)
     * POST /api/stock/add
     */
    public function add(): ResponseInterface
    {
        $input = $this->input();

        $rules = [
            'product_id' => 'required|integer|greater_than[0]',
            'quantity'   => 'required|numeric|greater_than[0]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->respondError(
                'Validation failed',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $officeId = $this->currentOfficeId();
        $userId   = $this->currentUserId();

        if (! $this->productInOffice((int) $input['product_id'])) {
            return $this->respondError('Product not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $payload = array_merge($input, [
            'user_office_id' => $officeId,
            'user_id'        => $userId,
        ]);

        try {
            $db = db_connect();
            $service = new InventoryService($db);
            $result  = $service->saveStock($payload);

            $productId = (int) $payload['product_id'];
            $updatedStock = $this->transactionModel->currentStock($productId, $officeId);

            $this->auditMovement($result, $updatedStock);

            return $this->respondSuccess([
                'product_id'    => $productId,
                'updated_stock' => $updatedStock,
                'batches'       => $result['batches'],
                'borrow_id'     => $result['borrow_id'] ?? null,
            ], 'Stock transaction recorded successfully', ResponseInterface::HTTP_CREATED);
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            // Database errors stay in the log; they can describe the schema
            log_message('error', 'Stock transaction failed: ' . $e->getMessage());

            return $this->respondError('Failed to record stock transaction. Nothing was saved; the details were written to the server log.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Whether the product belongs to the signed-in user's office (any office for a global account). */
    private function productInOffice(int $productId): bool
    {
        $builder = db_connect()->table('product_table')->where('product_id', $productId);
        if ($this->currentOfficeId() > 0) {
            $builder->where('user_office_id', $this->currentOfficeId());
        }

        return $productId > 0 && $builder->countAllResults() > 0;
    }

    /**
     * Edit / correct an existing transaction quantity, reference, office, or type.
     * POST /api/stock/edit-transaction
     */
    public function editTransaction(): ResponseInterface
    {
        $input = $this->input();

        $transactionId = (int) ($input['transaction_id'] ?? 0);
        $newQty        = (float) ($input['new_qty'] ?? $input['quantity'] ?? 0);
        $newTypeInput  = $input['new_type'] ?? $input['transaction_type_id'] ?? null;
        $newTypeId     = $newTypeInput !== null ? (int) $newTypeInput : null;
        $newOffice     = trim((string) ($input['new_office'] ?? $input['office'] ?? ''));
        $newRef        = trim((string) ($input['new_reference'] ?? $input['reference'] ?? ''));

        if ($transactionId <= 0) {
            return $this->respondError('Invalid transaction ID.', [], 422);
        }
        if ($newQty <= 0) {
            return $this->respondError('Quantity must be greater than 0.', [], 422);
        }

        $db           = db_connect();
        $transModel   = new TransactionModel();
        $userOfficeId = $this->currentOfficeId();

        $txn = $db->table('transaction_table')->where('transaction_id', $transactionId)->get(1)->getRowArray();
        if (! $txn) {
            return $this->respondError('Transaction not found.', [], 404);
        }

        if (! empty($txn['borrow_id'])) {
            return $this->respondError('This entry belongs to a borrow record. Record a Return instead of editing it, so the borrow stays correct.', [], 422);
        }

        $oldQty    = (float) $txn['transaction_qty'];
        $batchId   = (int) $txn['batch_id'];
        $oldTypeId = (int) $txn['transaction_type_id'];
        $finalType = $newTypeId ?? $oldTypeId;

        // Resolve stock-adding type IDs from DB (receipt, return and adjust_in add to stock)
        $stockInTypes = $db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->whereIn('transaction_type', TransactionModel::STOCK_IN_TYPES)
            ->get()->getResultArray();
        $stockInTypeIds = array_column($stockInTypes, 'transaction_type_id');

        $batch = $db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray();
        if (! $batch) {
            return $this->respondError('Linked batch not found.', [], 404);
        }
        if ($userOfficeId > 0 && (int) $batch['user_office_id'] !== $userOfficeId) {
            return $this->respondError('Transaction not found.', [], 404);
        }

        $currentBatchQty = (float) $batch['current_qty'];
        $oldIsReceipt    = in_array($oldTypeId, $stockInTypeIds);
        $newIsReceipt    = in_array($finalType, $stockInTypeIds);

        // Undo old transaction effect, then apply new
        // Undo: receipt gave +qty; issue gave -qty
        $undoneQty = $oldIsReceipt ? $currentBatchQty - $oldQty : $currentBatchQty + $oldQty;
        // Apply new qty+type
        $newBatchQty = $newIsReceipt ? $undoneQty + $newQty : $undoneQty - $newQty;

        if ($newBatchQty < 0) {
            return $this->respondError(
                'Cannot apply this change — it would leave the batch at ' . $newBatchQty . '. Some stock may already be issued.',
                [],
                422
            );
        }

        $db->transException(true)->transStart();

        $updateFields = [
            'transaction_qty' => $newQty,
            'updated_at'      => date('Y-m-d H:i:s'),
        ];
        if ($newOffice !== '') {
            $officeQuery = $db->table('office_table')->select('office_id')->where('office_name', $newOffice);
            if ($userOfficeId > 0) {
                $officeQuery->where('user_office_id', $userOfficeId);
            }
            $existingOffice = $officeQuery->get(1)->getRowArray();
            if ($existingOffice) {
                $updateFields['office_id'] = (int) $existingOffice['office_id'];
            } else {
                $officeInsert = ['office_name' => $newOffice];
                if ($userOfficeId > 0) {
                    $officeInsert['user_office_id'] = $userOfficeId;
                }
                $db->table('office_table')->insert($officeInsert);
                $updateFields['office_id'] = (int) $db->insertID();
            }
        } else {
            $updateFields['office_id'] = null;
        }

        if ($newRef !== '') {
            $refQuery = $db->table('reference_table')->select('reference_id')->where('reference', $newRef);
            if ($userOfficeId > 0) {
                $refQuery->where('user_office_id', $userOfficeId);
            }
            $existingRef = $refQuery->get(1)->getRowArray();
            if ($existingRef) {
                $updateFields['reference_id'] = (int) $existingRef['reference_id'];
            } else {
                $refInsert = ['reference' => $newRef];
                if ($userOfficeId > 0) {
                    $refInsert['user_office_id'] = $userOfficeId;
                }
                $db->table('reference_table')->insert($refInsert);
                $updateFields['reference_id'] = (int) $db->insertID();
            }
        } else {
            $updateFields['reference_id'] = null;
        }

        if ($newTypeId !== null && $newTypeId !== $oldTypeId) {
            $updateFields['transaction_type_id'] = $newTypeId;
        }

        $db->table('transaction_table')->where('transaction_id', $transactionId)->update($updateFields);

        $db->table('batch_table')->where('batch_id', $batchId)->update([
            'current_qty' => $newBatchQty,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        $productId = (int) $batch['product_id'];
        $newStock = $transModel->currentStock($productId, $userOfficeId);

        $after = $db->table('transaction_table')->where('transaction_id', $transactionId)->get(1)->getRowArray();
        AuditLog::record('stock.transaction_edited', 'transaction', $transactionId,
            'Edited ledger entry #' . $transactionId . ' of ' . $this->productName($productId) . " (qty {$oldQty} → {$newQty})",
            [
                'batch_no'  => $batch['batch_no'] ?? '',
                'before'    => $this->auditFields($txn),
                'after'     => $this->auditFields($after ?? []),
                'batch_qty' => ['before' => $currentBatchQty, 'after' => $newBatchQty],
            ]
        );

        return $this->respondSuccess([
            'product_id' => $productId,
            'new_qty'    => $newQty,
            'new_type'   => $finalType,
            'new_stock'  => $newStock,
        ], 'Transaction updated successfully');
    }

    /**
     * Delete a transaction and reverse its effect on the batch.
     * POST /api/stock/delete-transaction
     */
    public function deleteTransaction(): ResponseInterface
    {
        $input = $this->input();
        $transactionId = (int) ($input['transaction_id'] ?? 0);

        if ($transactionId <= 0) {
            return $this->respondError('Invalid transaction ID.', [], 422);
        }

        $db           = db_connect();
        $transModel   = new TransactionModel();
        $userOfficeId = $this->currentOfficeId();

        $txn = $db->table('transaction_table')->where('transaction_id', $transactionId)->get(1)->getRowArray();
        if (! $txn) {
            return $this->respondError('Transaction not found.', [], 404);
        }

        if (! empty($txn['borrow_id'])) {
            return $this->respondError('This entry belongs to a borrow record and cannot be deleted; the borrow history would no longer add up.', [], 422);
        }

        $qty     = (float) $txn['transaction_qty'];
        $batchId = (int) $txn['batch_id'];
        $typeId  = (int) $txn['transaction_type_id'];

        $stockInTypes = $db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->whereIn('transaction_type', TransactionModel::STOCK_IN_TYPES)
            ->get()->getResultArray();
        $stockInTypeIds = array_column($stockInTypes, 'transaction_type_id');

        $batch = $db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray();
        if (! $batch) {
            return $this->respondError('Linked batch not found.', [], 404);
        }
        if ($userOfficeId > 0 && (int) $batch['user_office_id'] !== $userOfficeId) {
            return $this->respondError('Transaction not found.', [], 404);
        }

        $currentBatchQty = (float) $batch['current_qty'];
        $isReceipt       = in_array($typeId, $stockInTypeIds);

        // Reversal: receipt gave +qty (so subtract); issue gave -qty (so add back)
        $newBatchQty = $isReceipt ? $currentBatchQty - $qty : $currentBatchQty + $qty;

        if ($newBatchQty < 0) {
            return $this->respondError(
                'Cannot delete this receipt — it would leave the batch at ' . $newBatchQty . '. Units have already been issued.',
                [],
                422
            );
        }

        $db->transException(true)->transStart();

        $db->table('transaction_table')->where('transaction_id', $transactionId)->delete();

        $db->table('batch_table')->where('batch_id', $batchId)->update([
            'current_qty' => $newBatchQty,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        $productId = (int) $batch['product_id'];
        $newStock = $transModel->currentStock($productId, $userOfficeId);

        AuditLog::record('stock.transaction_deleted', 'transaction', $transactionId,
            'Deleted ledger entry #' . $transactionId . ' of ' . $this->productName($productId) . " (qty {$qty})",
            [
                'batch_no'  => $batch['batch_no'] ?? '',
                'deleted'   => $this->auditFields($txn),
                'batch_qty' => ['before' => $currentBatchQty, 'after' => $newBatchQty],
            ]
        );

        return $this->respondSuccess([
            'product_id' => $productId,
            'new_stock'  => $newStock,
        ], 'Transaction deleted successfully');
    }

    /**
     * Update the unit cost recorded on a single transaction.
     * POST /api/stock/edit-report-cost
     */
    public function editReportCost(): ResponseInterface
    {
        $input         = $this->input();
        $transactionId = (int) ($input['transaction_id'] ?? 0);
        $newCost       = (float) ($input['new_cost'] ?? -1);

        if ($transactionId <= 0 || $newCost < 0) {
            return $this->respondError('Invalid input.', [], 422);
        }

        $db      = db_connect();
        $builder = $db->table('transaction_table t')
            ->select('t.transaction_id, t.transaction_unit_cost, b.product_id')
            ->join('batch_table b', 'b.batch_id = t.batch_id')
            ->where('t.transaction_id', $transactionId);
        if ($this->currentOfficeId() > 0) {
            $builder->where('b.user_office_id', $this->currentOfficeId());
        }
        $existing = $builder->get(1)->getRowArray();
        if (! $existing) {
            return $this->respondError('Transaction not found.', [], 404);
        }

        $db->table('transaction_table')->where('transaction_id', $transactionId)->update([
            'transaction_unit_cost' => $newCost,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        AuditLog::record('stock.cost_override', 'transaction', $transactionId,
            'Report cost of entry #' . $transactionId . ' (' . $this->productName((int) $existing['product_id']) . ') set to ' . number_format($newCost, 2),
            ['before' => (float) $existing['transaction_unit_cost'], 'after' => $newCost]
        );

        return $this->respondSuccess([
            'transaction_id' => $transactionId,
            'new_cost'       => $newCost,
        ], 'Unit cost updated');
    }

    /**
     * Which batches a stock-out would take from (shown in the stock form before saving).
     * GET /api/stock/batch-plan?product_id=&copy_id=&quantity=&type=issue|borrow|adjust_out&reason_id=
     */
    public function batchPlan(): ResponseInterface
    {
        $productId = (int) ($this->request->getGet('product_id') ?? 0);
        $copyId    = (int) ($this->request->getGet('copy_id') ?? 0);
        $quantity  = max(0.0, (float) ($this->request->getGet('quantity') ?? 0));
        $type      = (string) ($this->request->getGet('type') ?? 'issue');
        $reasonId  = (int) ($this->request->getGet('reason_id') ?? 0);

        if ($productId <= 0) {
            return $this->respondError('Choose a product.', [], 422);
        }

        $mode = InventoryService::MODE_ISSUE;
        if ($type === 'adjust_out') {
            $reason = db_connect()->table('adjustment_reason')->where('adjustment_reason_id', $reasonId)->get(1)->getRowArray();
            $mode   = strcasecmp((string) ($reason['adjustment_reason'] ?? ''), 'Expired') === 0
                ? InventoryService::MODE_EXPIRED
                : InventoryService::MODE_ADJUST;
        }

        $plan = (new InventoryService(db_connect()))->planDepletion($productId, $quantity, $this->currentOfficeId(), $copyId, $mode);

        return $this->respondSuccess($plan + ['mode' => $mode], 'Batch plan');
    }

    /**
     * Borrow records of this unit.
     * GET /api/stock/borrows?product_id=&status=open|outstanding|partial|returned
     */
    public function borrows(): ResponseInterface
    {
        $rows = (new InventoryService(db_connect()))->borrows(
            $this->currentOfficeId(),
            (int) ($this->request->getGet('product_id') ?? 0),
            (string) ($this->request->getGet('status') ?? '')
        );

        return $this->respondSuccess(['borrows' => $rows], 'Borrows retrieved');
    }

    /**
     * Physical count: align system stock with counted quantities.
     * POST /api/stock/count   { counts: [{ product_id, counted_qty }], note? }
     */
    public function count(): ResponseInterface
    {
        $input  = $this->input();
        $counts = is_array($input['counts'] ?? null) ? $input['counts'] : [];
        $note   = mb_substr(trim((string) ($input['note'] ?? '')), 0, 300);

        if ($counts === []) {
            return $this->respondError('Enter at least one counted quantity.', [], 422);
        }
        if (count($counts) > self::MAX_COUNT_LINES) {
            return $this->respondError('Save at most ' . self::MAX_COUNT_LINES . ' counted items at a time.', [], 422);
        }

        try {
            $result = (new InventoryService(db_connect()))->reconcileCount($counts, $this->currentOfficeId(), $this->currentUserId(), $note);
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), [], 422);
        } catch (Throwable $e) {
            log_message('error', 'Physical count failed: ' . $e->getMessage());

            return $this->respondError('The count could not be saved; nothing was changed. The details were written to the server log.', [], 500);
        }

        $lines = $result['lines'];
        AuditLog::record('count.reconciled', 'physical_count', null,
            'Physical count ' . $result['reference'] . ': ' . count($counts) . ' item(s) counted, ' . count($lines) . ' adjusted'
            . ($note !== '' ? " — {$note}" : ''),
            [
                'reference'   => $result['reference'],
                'note'        => $note,
                'counted'     => count($counts),
                'adjustments' => array_map(static fn ($l) => [
                    'product'  => $l['product'],
                    'system'   => $l['system_qty'],
                    'counted'  => $l['counted'],
                    'variance' => $l['variance'],
                    'batches'  => array_column($l['batches'], 'batch_no'),
                ], $lines),
            ]
        );

        return $this->respondSuccess($result, count($lines)
            ? count($lines) . ' item(s) adjusted to match the count (reference ' . $result['reference'] . ').'
            : 'Every counted quantity already matched the system. Nothing was adjusted.');
    }

    /**
     * One audit entry per stock movement, e.g. "Issued 5 kg of Flour from B-FPC-20261009-0128-01".
     */
    private function auditMovement(array $result, float $stockAfter): void
    {
        $product = db_connect()->table('product_table p')
            ->select('p.product, COALESCE(ut.unit, "") AS unit', false)
            ->join('unit_table ut', 'ut.unit_id = p.unit_id', 'left')
            ->where('p.product_id', $result['product_id'])
            ->get(1)->getRowArray() ?? ['product' => 'product', 'unit' => ''];

        $qty     = rtrim(rtrim(number_format((float) $result['quantity'], 2, '.', ''), '0'), '.');
        $what    = trim("{$qty} {$product['unit']}") . ' of ' . $product['product'];
        $batches = implode(', ', array_column($result['batches'], 'batch_no'));
        $reason  = ($result['reason'] ?? '') !== '' ? " ({$result['reason']})" : '';

        $summary = match ($result['type']) {
            'receipt'    => "Stock in: {$what} as batch {$batches}",
            'issue'      => "Issued {$what} from {$batches}",
            'borrow'     => "Lent {$what} to " . ($result['borrower'] ?? 'borrower') . " from {$batches}",
            'return'     => "Returned {$what}" . (isset($result['borrower']) ? " from {$result['borrower']}" : '') . (! empty($result['settled']) ? ' (borrow settled)' : ''),
            'adjust_out' => "Adjust out{$reason}: {$what} from {$batches}",
            'adjust_in'  => "Adjust in{$reason}: {$what} into {$batches}",
            default      => ucfirst($result['type']) . ": {$what}",
        };

        AuditLog::record('stock.' . $result['type'], 'product', (int) $result['product_id'], $summary, [
            'batches'     => $result['batches'],
            'borrow_id'   => $result['borrow_id'] ?? null,
            'stock_after' => $stockAfter,
        ]);
    }

    private function productName(int $productId): string
    {
        $row = db_connect()->table('product_table')->select('product')->where('product_id', $productId)->get(1)->getRowArray();

        return $row['product'] ?? "product #{$productId}";
    }

    /** The ledger fields worth keeping in the audit trail. */
    private function auditFields(array $txn): array
    {
        return array_intersect_key($txn, array_flip([
            'transaction_type_id', 'transaction_qty', 'transaction_unit_cost', 'transaction_date',
            'batch_id', 'copy_id', 'office_id', 'reference_id', 'user_id', 'adjustment_reason_id',
        ]));
    }

    /**
     * Copies (sub-products) of a product.
     * GET /api/stock/copies/{product_id}
     */
    public function copies(int $productId): ResponseInterface
    {
        $copies = (new ProductCopyModel())->copiesForProduct($productId, $this->currentOfficeId());

        return $this->respondSuccess(['copies' => $copies], 'Copies retrieved');
    }
}
