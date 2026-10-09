<?php

namespace App\Controllers\Api;

use App\Libraries\AuditLog;
use App\Models\EntityModel;
use App\Models\ProductModel;
use App\Models\ProductTypeModel;
use App\Models\UnitModel;
use CodeIgniter\HTTP\ResponseInterface;

class ProductController extends BaseApiController
{
    protected ProductModel $productModel;
    protected UnitModel $unitModel;
    protected ProductTypeModel $typeModel;
    protected EntityModel $entityModel;

    public function __construct()
    {
        $this->productModel = new ProductModel();
        $this->unitModel    = new UnitModel();
        $this->typeModel    = new ProductTypeModel();
        $this->entityModel  = new EntityModel();
    }

    /**
     * List products with search and filtering
     * GET /api/products
     */
    public function index(): ResponseInterface
    {
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $typeId   = (int) ($this->request->getGet('type_id') ?? 0);
        $archived = (string) ($this->request->getGet('archived') ?? '') === '1';
        $officeId = $this->currentOfficeId();

        $products = $this->productModel->searchProducts($search, $officeId, $typeId, $archived);

        return $this->respondSuccess($products, 'Products retrieved successfully');
    }

    /**
     * Get metadata options for dropdowns (types, units, entities)
     * GET /api/products/meta
     */
    public function meta(): ResponseInterface
    {
        $officeId = $this->currentOfficeId();

        return $this->respondSuccess([
            'types'    => $this->typeModel->orderedList($officeId),
            'units'    => $this->unitModel->orderedList($officeId),
            'entities' => $this->entityModel->orderedList($officeId),
        ], 'Product metadata retrieved');
    }

    /**
     * Retrieve single product
     * GET /api/products/{id}
     */
    public function show($id = null): ResponseInterface
    {
        if (! $this->ownedProduct((int) $id)) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->respondSuccess($this->productModel->findProduct((int) $id), 'Product details retrieved');
    }

    /**
     * Create new product
     * POST /api/products
     */
    public function create(): ResponseInterface
    {
        $input    = $this->input();
        $officeId = $this->currentOfficeId();

        if ($error = $this->validateProduct($input, true)) {
            return $error;
        }

        $productNo = (int) $input['product_no'];
        if ($error = $this->duplicateProductNo($productNo, $officeId)) {
            return $error;
        }

        $payload = array_merge($this->productFields($input), [
            'product_no'     => $productNo,
            'entity_id'      => $this->resolveEntityId($input, $officeId) ?: 1,
            'unit_id'        => $this->resolveUnitId($input, $officeId) ?: 1,
            'type_id'        => $this->resolveTypeId($input, $officeId) ?: 1,
            'user_office_id' => $officeId,
            'stock_no'       => ! empty($input['stock_no']) ? trim((string) $input['stock_no']) : $this->stockNo($productNo, $officeId),
        ]);
        $payload['product_reorder_point'] ??= 10;
        $payload['expiry_warning_days']   ??= 30;
        $payload['expiry_danger_days']    ??= 7;

        $newId = $this->productModel->insert($payload);
        if (! $newId) {
            return $this->respondError('Failed to create product', $this->productModel->errors());
        }

        AuditLog::record('product.created', 'product', (int) $newId, 'Created product "' . ($payload['product'] ?? '') . "\" (no. {$productNo})", [
            'after' => $payload,
        ]);

        return $this->respondSuccess(
            $this->productModel->findProduct((int) $newId),
            'Product created successfully',
            ResponseInterface::HTTP_CREATED
        );
    }

    /**
     * Update existing product
     * PUT/PATCH /api/products/{id}
     *
     * Send product_action = "new" to reuse this product record as a brand-new
     * product: all of its batches (and their stock) are cleared.
     */
    public function update($id = null): ResponseInterface
    {
        $id       = (int) $id;
        $existing = $this->ownedProduct($id);

        if (! $existing) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $input = $this->input();
        if ($error = $this->validateProduct($input, false, $existing)) {
            return $error;
        }

        $officeId = (int) $existing['user_office_id'];
        $payload  = $this->productFields($input);

        if (isset($input['product_no']) && $input['product_no'] !== '') {
            $productNo = (int) $input['product_no'];
            if ($error = $this->duplicateProductNo($productNo, $officeId, $id)) {
                return $error;
            }
            $payload['product_no'] = $productNo;
            $payload['stock_no']   = $this->stockNo($productNo, $officeId);
        }

        if ($typeId = $this->resolveTypeId($input, $officeId)) {
            $payload['type_id'] = $typeId;
        }
        if ($unitId = $this->resolveUnitId($input, $officeId)) {
            $payload['unit_id'] = $unitId;
        }
        if ($entityId = $this->resolveEntityId($input, $officeId)) {
            $payload['entity_id'] = $entityId;
        }

        if (empty($payload)) {
            return $this->respondError('No fields to update provided.');
        }

        if (! $this->productModel->update($id, $payload)) {
            return $this->respondError('Failed to update product', $this->productModel->errors());
        }

        $message = 'Product updated successfully';
        $cleared = ($input['product_action'] ?? '') === 'new';
        if ($cleared) {
            db_connect()->table('batch_table')->where('product_id', $id)->delete();
            $message = 'New product created under the same product no. All previous stock and transactions have been cleared.';
        }

        $changed = array_filter($payload, static fn ($v, $k) => (string) ($existing[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
        AuditLog::record($cleared ? 'product.reset' : 'product.updated', 'product', $id,
            ($cleared ? 'Reused product "' : 'Updated product "') . $existing['product'] . '"' . ($cleared ? ' as a new product; all its stock and transactions were cleared' : ''),
            [
                'before' => array_intersect_key($existing, $changed),
                'after'  => $changed,
            ]
        );

        return $this->respondSuccess($this->productModel->findProduct($id), $message);
    }

    /**
     * Delete product (only when it has no remaining stock)
     * DELETE /api/products/{id}
     */
    public function delete($id = null): ResponseInterface
    {
        $id      = (int) $id;
        $product = $this->ownedProduct($id);
        if (! $product) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $remainingStock = (float) (db_connect()
            ->table('batch_table')
            ->selectSum('current_qty')
            ->where('product_id', $id)
            ->get()
            ->getRowArray()['current_qty'] ?? 0);

        if ($remainingStock > 0) {
            return $this->respondError(
                "Cannot delete: this product still has {$remainingStock} unit(s) in stock. Deplete all stock before deleting.",
                [],
                ResponseInterface::HTTP_CONFLICT
            );
        }

        try {
            $this->productModel->delete($id);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'foreign key') !== false || stripos($msg, 'constraint') !== false) {
                return $this->respondError('This product has stock history, so it can\'t be deleted. Archive it instead: it disappears from lists but its records are kept.', ['can_archive' => true], ResponseInterface::HTTP_CONFLICT);
            }
            return $this->respondError('Delete failed.', [], ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }

        AuditLog::record('product.deleted', 'product', $id, 'Deleted product "' . $product['product'] . '"', ['deleted' => $product]);

        return $this->respondSuccess(null, 'Product deleted successfully');
    }

    /**
     * Archive: hide a product that is no longer used, keeping all its records.
     * POST /api/products/{id}/archive   { reason? }
     */
    public function archive($id = null): ResponseInterface
    {
        $id      = (int) $id;
        $product = $this->ownedProduct($id);
        if (! $product) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }
        if (! empty($product['archived_at'])) {
            return $this->respondError('This product is already archived.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $blockers = $this->productModel->archiveBlockers($id, (int) $product['user_office_id']);
        if ($blockers !== []) {
            return $this->respondError('Can\'t archive "' . $product['product'] . '" yet: ' . implode('; ', $blockers) . '.', ['blockers' => $blockers], ResponseInterface::HTTP_CONFLICT);
        }

        $reason = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($this->input()['reason'] ?? ''))), 0, 255);
        $this->productModel->update($id, [
            'archived_at'    => date('Y-m-d H:i:s'),
            'archived_by'    => $this->currentUserId() ?: null,
            'archive_reason' => $reason,
        ]);

        AuditLog::record('product.archived', 'product', $id, 'Archived product "' . $product['product'] . '"' . ($reason !== '' ? " — {$reason}" : ''), [
            'reason' => $reason,
        ]);

        return $this->respondSuccess($this->productModel->findProduct($id), 'Product archived. Its records are kept; restore it any time from the Archived tab.');
    }

    /**
     * Restore an archived product to the active lists.
     * POST /api/products/{id}/restore
     */
    public function restore($id = null): ResponseInterface
    {
        $id      = (int) $id;
        $product = $this->ownedProduct($id);
        if (! $product) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }
        if (empty($product['archived_at'])) {
            return $this->respondError('This product is not archived.', [], ResponseInterface::HTTP_CONFLICT);
        }

        $this->productModel->update($id, ['archived_at' => null, 'archived_by' => null, 'archive_reason' => '']);

        AuditLog::record('product.restored', 'product', $id, 'Restored archived product "' . $product['product'] . '"', [
            'archived_at' => $product['archived_at'],
            'reason_was'  => $product['archive_reason'] ?? '',
        ]);

        return $this->respondSuccess($this->productModel->findProduct($id), 'Product restored.');
    }

    /**
     * Product row when it belongs to the current user's office, else null.
     */
    private function ownedProduct(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $builder = $this->productModel->where('product_id', $id);
        $officeId = $this->currentOfficeId();
        if ($officeId > 0) {
            $builder->where('user_office_id', $officeId);
        }

        return $builder->first();
    }

    /**
     * Validate product input; returns an error response or null when valid.
     */
    private function validateProduct(array $input, bool $creating, array $existing = []): ?ResponseInterface
    {
        $required = $creating ? 'required' : 'permit_empty';
        $rules = [
            'product_no'            => "{$required}|integer|greater_than[0]",
            'product'               => "{$required}|min_length[2]|max_length[255]",
            'product_description'   => 'permit_empty|max_length[1000]',
            'measurement'           => 'permit_empty|max_length[100]',
            'product_reorder_point' => 'permit_empty|integer|greater_than_equal_to[0]',
            'expiry_warning_days'   => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
            'expiry_danger_days'    => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
            'entity_name'           => 'permit_empty|max_length[255]',
            'unit_name'             => 'permit_empty|max_length[255]',
            'type_name'             => 'permit_empty|max_length[255]',
        ];

        if (! $this->validateData($input, $rules)) {
            return $this->respondError(
                'Validation failed',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $warningDays = (int) ($input['expiry_warning_days'] ?? $existing['expiry_warning_days'] ?? 30);
        $dangerDays  = (int) ($input['expiry_danger_days'] ?? $existing['expiry_danger_days'] ?? 7);
        if ($dangerDays >= $warningDays) {
            return $this->respondError(
                "Danger days must be less than warning days ({$warningDays}).",
                ['expiry_danger_days' => 'Must be less than warning days.'],
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    private function duplicateProductNo(int $productNo, int $officeId, ?int $exceptId = null): ?ResponseInterface
    {
        $builder = db_connect()->table('product_table')
            ->where('product_no', $productNo)
            ->where('user_office_id', $officeId);
        if ($exceptId !== null) {
            $builder->where('product_id !=', $exceptId);
        }

        if ($builder->countAllResults() === 0) {
            return null;
        }

        return $this->respondError(
            "Product No {$productNo} is already used by another product in your office.",
            ['product_no' => "Product No {$productNo} is already in use."],
            ResponseInterface::HTTP_CONFLICT
        );
    }

    /**
     * Plain product columns present in the input.
     */
    private function productFields(array $input): array
    {
        $fields = [];
        foreach (['product', 'product_description', 'measurement'] as $key) {
            if (isset($input[$key])) {
                $fields[$key] = trim((string) $input[$key]);
            }
        }
        foreach (['product_reorder_point', 'expiry_warning_days', 'expiry_danger_days'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $fields[$key] = (int) $input[$key];
            }
        }
        return $fields;
    }

    /**
     * stock_no = UPPERCASE user office name + zero-padded product no.
     */
    private function stockNo(int $productNo, int $officeId): string
    {
        $officeRow  = db_connect()->table('user_office_table')->where('user_office_id', $officeId)->get(1)->getRowArray();
        $officeName = $officeRow['user_office_name'] ?? 'BSU';

        return strtoupper($officeName) . '-' . str_pad((string) $productNo, 4, '0', STR_PAD_LEFT);
    }

    private function resolveTypeId(array $input, int $officeId): int
    {
        if (! empty($input['type_id']) && $this->ownLookup('type_of_product', 'type_id', (int) $input['type_id'], $officeId)) {
            return (int) $input['type_id'];
        }
        return ! empty($input['type_name']) ? $this->typeModel->firstOrCreate(trim($input['type_name']), $officeId) : 0;
    }

    private function resolveUnitId(array $input, int $officeId): int
    {
        if (! empty($input['unit_id']) && $this->ownLookup('unit_table', 'unit_id', (int) $input['unit_id'], $officeId)) {
            return (int) $input['unit_id'];
        }
        return ! empty($input['unit_name']) ? $this->unitModel->firstOrCreate(trim($input['unit_name']), $officeId) : 0;
    }

    private function resolveEntityId(array $input, int $officeId): int
    {
        if (! empty($input['entity_id']) && $this->ownLookup('entity_table', 'entity_id', (int) $input['entity_id'], $officeId)) {
            return (int) $input['entity_id'];
        }
        return ! empty($input['entity_name']) ? $this->entityModel->firstOrCreate(trim($input['entity_name']), $officeId) : 0;
    }

    /** An id sent by the browser counts only when the row belongs to the product's office. */
    private function ownLookup(string $table, string $pk, int $id, int $officeId): bool
    {
        $builder = db_connect()->table($table)->where($pk, $id);
        if ($officeId > 0) {
            $builder->where('user_office_id', $officeId);
        }

        return $builder->countAllResults() > 0;
    }
}
