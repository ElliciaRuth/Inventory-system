<?php

namespace App\Controllers\Api;

use App\Models\StockoutModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Stock-out requests: staff (level 1+) build a draft list and submit it;
 * custodians/managers (level 2+) approve, edit or reject the pending items.
 */
class StockoutController extends BaseApiController
{
    public function __construct(
        private readonly StockoutModel $model = new StockoutModel(),
    ) {
    }

    /**
     * Products with stock available for a stock-out request.
     * GET /api/stockout
     */
    public function index(): ResponseInterface
    {
        return $this->respondSuccess([
            'items' => $this->model->availableItems($this->currentOfficeId()),
        ], 'Available items retrieved');
    }

    /**
     * Current user's draft stock-out list.
     * GET /api/stockout/temp
     */
    public function tempList(): ResponseInterface
    {
        $draft = $this->draft();

        return $this->respondSuccess([
            'draft' => $draft,
            'items' => $this->model->getItems((int) $draft['temp_stockout_id']),
        ], 'Draft retrieved');
    }

    /**
     * Add a product to the draft list.
     * POST /api/stockout/add-temp   { product_id, copy_id?, quantity, unit?, description? }
     */
    public function addToTemp(): ResponseInterface
    {
        $input        = $this->input();
        $userOfficeId = $this->currentOfficeId();

        $productId   = (int) ($input['product_id'] ?? 0);
        $copyId      = (int) ($input['copy_id'] ?? 0);
        $quantity    = (int) ($input['quantity'] ?? 0);
        $unit        = trim((string) ($input['unit'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($productId <= 0 || $quantity <= 0) {
            return $this->respondError('Please select a product and enter a valid quantity.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $db = db_connect();

        $copyBuilder = $db->table('product_copy_table')->where('product_id', $productId);
        if ($userOfficeId > 0) {
            $copyBuilder->where('user_office_id', $userOfficeId);
        }
        $copyCount = (int) $copyBuilder->countAllResults();

        if ($copyCount > 0 && $copyId <= 0) {
            return $this->respondError('Please select a sub-product for this stock-out request.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($copyId > 0) {
            $stockBuilder = $db->table('batch_table')
                ->selectSum('current_qty', 'stock')
                ->where('product_id', $productId)
                ->where('copy_id', $copyId);

            if ($userOfficeId > 0) {
                $stockBuilder->where('user_office_id', $userOfficeId);
            }

            $copyStock = (int) ($stockBuilder->get()->getRowArray()['stock'] ?? 0);

            if ($quantity > $copyStock) {
                return $this->respondError('Requested quantity exceeds the selected sub-product stock.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $draft = $this->draft();
        $this->model->addItem((int) $draft['temp_stockout_id'], [
            'product_id'  => $productId,
            'copy_id'     => $copyId,
            'quantity'    => $quantity,
            'unit'        => $unit,
            'description' => $description,
        ]);

        return $this->respondSuccess(null, 'Item added to your temporary stock-out list.', ResponseInterface::HTTP_CREATED);
    }

    /**
     * Edit an item in the current user's draft list.
     * POST /api/stockout/edit-temp/{itemId}   { quantity, unit?, description? }
     */
    public function editTemp(int $itemId): ResponseInterface
    {
        if (! $this->draftItem($itemId)) {
            return $this->respondError('Item not found in your list.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $input    = $this->input();
        $quantity = (int) ($input['quantity'] ?? 0);

        if ($quantity <= 0) {
            return $this->respondError('Quantity must be greater than 0.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->model->updateItem($itemId, [
            'quantity'    => $quantity,
            'description' => trim((string) ($input['description'] ?? '')),
            'unit'        => trim((string) ($input['unit'] ?? '')),
        ]);

        return $this->respondSuccess(null, 'Item updated.');
    }

    /**
     * Remove an item from the current user's draft list.
     * POST /api/stockout/remove-temp/{itemId}
     */
    public function removeFromTemp(int $itemId): ResponseInterface
    {
        if (! $this->draftItem($itemId)) {
            return $this->respondError('Item not found in your list.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $this->model->removeItem($itemId);

        return $this->respondSuccess(null, 'Item removed from list.');
    }

    /**
     * Submit the draft list for approval.
     * POST /api/stockout/submit
     */
    public function submitForApproval(): ResponseInterface
    {
        $draft = $this->draft();

        if (empty($this->model->getItems((int) $draft['temp_stockout_id']))) {
            return $this->respondError('Cannot submit an empty list.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->model->submitForApproval((int) $draft['temp_stockout_id']);

        return $this->respondSuccess(null, 'Stock-out request submitted for approval.');
    }

    /**
     * Pending stock-out requests with their (summed) items.
     * GET /api/stockout/pending
     */
    public function pendingRequests(): ResponseInterface
    {
        $requests = $this->model->pendingRequests($this->currentOfficeId(), $this->currentLevelId());

        foreach ($requests as &$request) {
            $request['items'] = $this->model->getItemsSummed((int) $request['temp_stockout_id']);
        }
        unset($request);

        return $this->respondSuccess(['requests' => $requests], 'Pending requests retrieved');
    }

    /**
     * POST /api/stockout/approve-item/{itemId}
     */
    public function approveItem(int $itemId): ResponseInterface
    {
        if (! $this->pendingItemInScope($itemId)) {
            return $this->respondError('Item not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $result = $this->model->approveItem($itemId, $this->currentUserId());

        if ($result === 'insufficient_stock') {
            return $this->respondError('Cannot approve: requested quantity exceeds available stock.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $result) {
            return $this->respondError('Item could not be approved.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondSuccess(null, 'Item approved and stock deducted.');
    }

    /**
     * POST /api/stockout/approve-all/{requestId}
     */
    public function approveAll(int $requestId): ResponseInterface
    {
        if (! $this->requestInScope($requestId)) {
            return $this->respondError('Request not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $result   = $this->model->approveAll($requestId, $this->currentUserId());
        $approved = $result['approved'];
        $skipped  = $result['skipped'];

        if ($approved === 0 && $skipped > 0) {
            return $this->respondError(
                "No items approved — all {$skipped} item(s) have insufficient stock.",
                [],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $msg = "{$approved} item(s) approved and stock deducted.";
        if ($skipped > 0) {
            $msg .= " {$skipped} item(s) skipped (insufficient stock).";
        }

        return $this->respondSuccess(['approved' => $approved, 'skipped' => $skipped], $msg);
    }

    /**
     * POST /api/stockout/reject-item/{itemId}
     */
    public function rejectItem(int $itemId): ResponseInterface
    {
        if (! $this->pendingItemInScope($itemId)) {
            return $this->respondError('Item not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $this->model->rejectItem($itemId);

        return $this->respondSuccess(null, 'Item rejected.');
    }

    /**
     * Edit quantity of a pending item before approval.
     * POST /api/stockout/edit-pending/{itemId}   { quantity }
     */
    public function editPendingItem(int $itemId): ResponseInterface
    {
        if (! $this->pendingItemInScope($itemId)) {
            return $this->respondError('Item not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $quantity = (int) ($this->input()['quantity'] ?? 0);
        if ($quantity <= 0) {
            return $this->respondError('Quantity must be greater than 0.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->model->updateItem($itemId, ['quantity' => $quantity]);

        return $this->respondSuccess(null, 'Quantity updated successfully.');
    }

    private function draft(): array
    {
        return $this->model->getOrCreateDraft($this->currentUserId(), $this->currentOfficeId() ?: null);
    }

    /**
     * Whether the item is in the current user's draft list.
     */
    private function draftItem(int $itemId): bool
    {
        return db_connect()->table('temp_stockout_item')
            ->where('temp_stockout_item_id', $itemId)
            ->where('temp_stockout_id', (int) $this->draft()['temp_stockout_id'])
            ->countAllResults() > 0;
    }

    /**
     * Whether the request belongs to the approver's office (level 4 sees all).
     */
    private function requestInScope(int $requestId): bool
    {
        $builder = db_connect()->table('temp_stockout')->where('temp_stockout_id', $requestId);
        if ($this->currentLevelId() < 4 && $this->currentOfficeId() > 0) {
            $builder->where('user_office_id', $this->currentOfficeId());
        }

        return $builder->countAllResults() > 0;
    }

    private function pendingItemInScope(int $itemId): bool
    {
        $item = db_connect()->table('temp_stockout_item')
            ->select('temp_stockout_id')
            ->where('temp_stockout_item_id', $itemId)
            ->get(1)
            ->getRowArray();

        return $item !== null && $this->requestInScope((int) $item['temp_stockout_id']);
    }
}
