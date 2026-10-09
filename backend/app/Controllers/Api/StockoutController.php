<?php

namespace App\Controllers\Api;

use App\Models\StockoutModel;
use App\Libraries\AuditLog;
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

        // Only this office's products can be requested
        $productBuilder = $db->table('product_table')->where('product_id', $productId);
        if ($userOfficeId > 0) {
            $productBuilder->where('user_office_id', $userOfficeId);
        }
        if ($productBuilder->countAllResults() === 0) {
            return $this->respondError('Product not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        if ($this->model->productArchived($productId)) {
            return $this->respondError('This item is no longer stocked (archived).', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

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

        $requestId = (int) $draft['temp_stockout_id'];
        if ($archived = $this->model->archivedProductsIn($requestId)) {
            return $this->respondError(
                'These items are no longer stocked (archived); remove them from your list first: ' . implode(', ', $archived) . '.',
                ['archived' => $archived],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        $items     = $this->model->getItems($requestId);
        $this->model->submitForApproval($requestId);
        AuditLog::record('stockout.submitted', 'stockout_request', $requestId,
            "Submitted stock-out request #{$requestId} (" . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ')',
            ['items' => $this->itemList($items)]
        );

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

        $before = $this->itemRow($itemId);
        $result = $this->model->approveItem($itemId, $this->currentUserId());

        if ($result === 'archived') {
            return $this->respondError('This product has been archived; restore it on the Products page before accepting, or reject the request.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($result === 'insufficient_stock') {
            return $this->respondError('Cannot approve: requested quantity exceeds available stock.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $result) {
            return $this->respondError('Item could not be approved.', [], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLog::record('stockout.approved', 'stockout_item', $itemId,
            'Accepted ' . $this->itemLabel($before) . " (request #{$before['temp_stockout_id']})",
            ['request_id' => (int) $before['temp_stockout_id']]
        );

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

        AuditLog::record('stockout.approved_all', 'stockout_request', $requestId,
            "Accepted all of request #{$requestId}: {$approved} approved, {$skipped} skipped (insufficient stock)",
            ['approved' => $approved, 'skipped' => $skipped]
        );

        $msg = "{$approved} item(s) approved and stock deducted.";
        if ($skipped > 0) {
            $msg .= " {$skipped} item(s) skipped (insufficient stock).";
        }

        return $this->respondSuccess(['approved' => $approved, 'skipped' => $skipped], $msg);
    }

    /**
     * Reject an item; the reason is shown to the staff member who requested it.
     * POST /api/stockout/reject-item/{itemId}   { reason }
     */
    public function rejectItem(int $itemId): ResponseInterface
    {
        if (! $this->pendingItemInScope($itemId)) {
            return $this->respondError('Item not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $reason = trim(preg_replace('/\s+/', ' ', (string) ($this->input()['reason'] ?? '')));
        if (mb_strlen($reason) < 3) {
            return $this->respondError('Give a short reason for rejecting this item.', ['reason' => 'Required.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (mb_strlen($reason) > 500) {
            return $this->respondError('Keep the reason under 500 characters.', ['reason' => 'Too long.'], ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $before = $this->itemRow($itemId);
        if (! $this->model->rejectItem($itemId, $this->currentUserId(), $reason)) {
            return $this->respondError('This item was already accepted or rejected.', [], ResponseInterface::HTTP_CONFLICT);
        }

        AuditLog::record('stockout.rejected', 'stockout_item', $itemId,
            'Rejected ' . $this->itemLabel($before) . " (request #{$before['temp_stockout_id']})",
            ['request_id' => (int) $before['temp_stockout_id'], 'reason' => $reason]
        );

        return $this->respondSuccess(null, 'Item rejected. The requester will see your reason.');
    }

    /**
     * Requested items and their outcome. Staff see their own requests; custodians and
     * managers see all requests of their office.
     * GET /api/stockout/history?status=&search=&page=&limit=
     */
    public function history(): ResponseInterface
    {
        $level = $this->currentLevelId();
        $page  = max(1, (int) ($this->request->getGet('page') ?? 1));
        $limit = max(5, min(100, (int) ($this->request->getGet('limit') ?? 15)));

        $result = $this->model->history(
            $this->currentOfficeId(),
            $level <= 1 ? $this->currentUserId() : null,
            [
                'status' => (string) ($this->request->getGet('status') ?? ''),
                'search' => (string) ($this->request->getGet('search') ?? ''),
            ],
            $page,
            $limit
        );

        return $this->respondSuccess([
            'items'      => $result['rows'],
            'counts'     => $result['counts'],
            'total'      => $result['total'],
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => max(1, (int) ceil($result['total'] / $limit)),
            'scope'      => $level <= 1 ? 'mine' : 'office',
        ], 'Request history retrieved');
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

        $before = $this->itemRow($itemId);
        if (($before['status'] ?? '') !== 'pending') {
            // Accepted lines already took the stock; rejected ones are closed
            return $this->respondError('This item was already accepted or rejected.', [], ResponseInterface::HTTP_CONFLICT);
        }
        $this->model->updateItem($itemId, ['quantity' => $quantity]);
        AuditLog::record('stockout.quantity_changed', 'stockout_item', $itemId,
            'Changed requested quantity of ' . $this->itemLabel($before) . " to {$quantity} (request #{$before['temp_stockout_id']})",
            ['before' => (float) $before['quantity'], 'after' => $quantity]
        );

        return $this->respondSuccess(null, 'Quantity updated successfully.');
    }

    /** A request line with its product name, for the audit trail. */
    private function itemRow(int $itemId): array
    {
        return db_connect()->table('temp_stockout_item i')
            ->select('i.*, p.product')
            ->join('product_table p', 'p.product_id = i.product_id', 'left')
            ->where('i.temp_stockout_item_id', $itemId)
            ->get(1)->getRowArray() ?? ['temp_stockout_id' => 0, 'quantity' => 0, 'product' => ''];
    }

    private function itemLabel(array $item): string
    {
        $qty = rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.');

        return "{$qty} × " . (($item['product'] ?? '') ?: 'item');
    }

    private function itemList(array $items): array
    {
        return array_map(static fn ($i) => [
            'product'  => $i['item_name'] ?? $i['product'] ?? '',
            'quantity' => (float) ($i['quantity'] ?? 0),
        ], $items);
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
