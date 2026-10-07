<?php

namespace App\Controllers\Api;

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
        if ($productId === 0) {
            $productId = $this->productModel->firstProductId($officeId);
        }

        $itemInfo   = [];
        $stockcard  = [];
        $totalPages = 1;
        $total      = 0;

        if ($productId > 0) {
            $itemInfo = $this->productModel->stockcardInfo($productId);
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
            'items'      => $this->productModel->listForSelect($officeId),
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
        ], 'Stock options retrieved');
    }

    /**
     * Save a stock transaction (Stock-In, Stock-Out, Adjust, etc.)
     * POST /api/stock/add
     */
    public function add(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? $this->request->getPost();

        $rules = [
            'product_id' => 'required|integer|greater_than[0]',
            'quantity'   => 'required|numeric|greater_than[0]',
        ];

        if (! $this->validate($rules)) {
            return $this->respondError(
                'Validation failed',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $officeId = $this->currentOfficeId();
        if ($officeId === 0) {
            $officeId = (int) ($input['user_office_id'] ?? 2);
        }

        $userId = $this->currentUserId();
        if ($userId === 0) {
            $userId = (int) ($input['user_id'] ?? 1);
        }

        $payload = array_merge($input, [
            'user_office_id' => $officeId,
            'user_id'        => $userId,
        ]);

        try {
            $db = db_connect();
            $service = new InventoryService($db);
            $service->saveStock($payload);

            $productId = (int) $payload['product_id'];
            $updatedStock = $this->transactionModel->currentStock($productId, $officeId);

            return $this->respondSuccess([
                'product_id'    => $productId,
                'updated_stock' => $updatedStock,
            ], 'Stock transaction recorded successfully', ResponseInterface::HTTP_CREATED);
        } catch (DomainException $e) {
            return $this->respondError($e->getMessage(), [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            return $this->respondError('Failed to record stock transaction: ' . $e->getMessage(), [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Edit / correct an existing transaction quantity, reference, office, or type.
     * POST /api/stock/edit-transaction
     */
    public function editTransaction(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?: $this->request->getPost();

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

        $oldQty    = (float) $txn['transaction_qty'];
        $batchId   = (int) $txn['batch_id'];
        $oldTypeId = (int) $txn['transaction_type_id'];
        $finalType = $newTypeId ?? $oldTypeId;

        // Resolve stock-adding type IDs from DB (both 'receipt' and 'return' add to stock)
        $stockInTypes = $db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->whereIn('transaction_type', ['receipt', 'return'])
            ->get()->getResultArray();
        $stockInTypeIds = array_column($stockInTypes, 'transaction_type_id');

        $batch = $db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray();
        if (! $batch) {
            return $this->respondError('Linked batch not found.', [], 404);
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
        $input = $this->request->getJSON(true) ?: $this->request->getPost();
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

        $qty     = (float) $txn['transaction_qty'];
        $batchId = (int) $txn['batch_id'];
        $typeId  = (int) $txn['transaction_type_id'];

        $stockInTypes = $db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->whereIn('transaction_type', ['receipt', 'return'])
            ->get()->getResultArray();
        $stockInTypeIds = array_column($stockInTypes, 'transaction_type_id');

        $batch = $db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray();
        if (! $batch) {
            return $this->respondError('Linked batch not found.', [], 404);
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

        return $this->respondSuccess([
            'product_id' => $productId,
            'new_stock'  => $newStock,
        ], 'Transaction deleted successfully');
    }
}
