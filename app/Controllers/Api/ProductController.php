<?php

namespace App\Controllers\Api;

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
        $officeId = $this->currentOfficeId();

        $products = $this->productModel->searchProducts($search, $officeId, $typeId);

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
        $id = (int) $id;
        $product = $this->productModel->findProduct($id);

        if (! $product) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->respondSuccess($product, 'Product details retrieved');
    }

    /**
     * Create new product
     * POST /api/products
     */
    public function create(): ResponseInterface
    {
        $rules = [
            'product_no'            => 'required|integer|greater_than[0]',
            'product'               => 'required|min_length[2]|max_length[255]',
            'product_description'   => 'permit_empty|max_length[1000]',
            'measurement'           => 'permit_empty|max_length[100]',
            'product_reorder_point' => 'permit_empty|integer|greater_than_equal_to[0]',
            'expiry_warning_days'   => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
            'expiry_danger_days'    => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
        ];

        if (! $this->validate($rules)) {
            return $this->respondError(
                'Validation failed',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $officeId = $this->currentOfficeId() ?: (int) ($input['user_office_id'] ?? 2);
        $productNo = (int) $input['product_no'];

        // Check duplicate product_no in this office
        $duplicate = db_connect()->table('product_table')
            ->where('product_no', $productNo)
            ->where('user_office_id', $officeId)
            ->countAllResults();

        if ($duplicate > 0) {
            return $this->respondError("Product No {$productNo} already exists in this office.", [
                'product_no' => "Product No {$productNo} is already in use.",
            ], ResponseInterface::HTTP_CONFLICT);
        }

        // Auto-resolve or create types/units/entities
        $typeId = ! empty($input['type_id']) ? (int) $input['type_id'] : 0;
        if ($typeId === 0 && ! empty($input['type_name'])) {
            $typeId = $this->typeModel->firstOrCreate(trim($input['type_name']), $officeId);
        }

        $unitId = ! empty($input['unit_id']) ? (int) $input['unit_id'] : 0;
        if ($unitId === 0 && ! empty($input['unit_name'])) {
            $unitId = $this->unitModel->firstOrCreate(trim($input['unit_name']), $officeId);
        }

        $entityId = ! empty($input['entity_id']) ? (int) $input['entity_id'] : 0;
        if ($entityId === 0 && ! empty($input['entity_name'])) {
            $entityId = $this->entityModel->firstOrCreate(trim($input['entity_name']), $officeId);
        }

        // Generate stock_no
        $officeRow = db_connect()->table('user_office_table')->where('user_office_id', $officeId)->get(1)->getRowArray();
        $officeName = $officeRow['user_office_name'] ?? 'BSU';
        $stockNo = ! empty($input['stock_no'])
            ? trim($input['stock_no'])
            : strtoupper($officeName) . '-' . str_pad((string) $productNo, 4, '0', STR_PAD_LEFT);

        $payload = [
            'product_no'            => $productNo,
            'product'               => trim((string) $input['product']),
            'product_description'   => trim((string) ($input['product_description'] ?? '')),
            'measurement'           => trim((string) ($input['measurement'] ?? '')),
            'product_reorder_point' => (int) ($input['product_reorder_point'] ?? 10),
            'expiry_warning_days'   => (int) ($input['expiry_warning_days'] ?? 30),
            'expiry_danger_days'    => (int) ($input['expiry_danger_days'] ?? 7),
            'entity_id'             => $entityId ?: 1,
            'unit_id'               => $unitId ?: 1,
            'type_id'               => $typeId ?: 1,
            'user_office_id'        => $officeId,
            'stock_no'              => $stockNo,
        ];

        $newId = $this->productModel->insert($payload);
        if (! $newId) {
            return $this->respondError('Failed to create product', $this->productModel->errors());
        }

        $created = $this->productModel->findProduct((int) $newId);
        return $this->respondSuccess($created, 'Product created successfully', ResponseInterface::HTTP_CREATED);
    }

    /**
     * Update existing product
     * PUT/PATCH /api/products/{id}
     */
    public function update($id = null): ResponseInterface
    {
        $id = (int) $id;
        $existing = $this->productModel->find($id);

        if (! $existing) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $rules = [
            'product_no'            => 'permit_empty|integer|greater_than[0]',
            'product'               => 'permit_empty|min_length[2]|max_length[255]',
            'product_description'   => 'permit_empty|max_length[1000]',
            'measurement'           => 'permit_empty|max_length[100]',
            'product_reorder_point' => 'permit_empty|integer|greater_than_equal_to[0]',
            'expiry_warning_days'   => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
            'expiry_danger_days'    => 'permit_empty|integer|greater_than[0]|less_than_equal_to[365]',
        ];

        if (! $this->validate($rules)) {
            return $this->respondError(
                'Validation failed',
                $this->validator->getErrors(),
                ResponseInterface::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $officeId = (int) ($existing['user_office_id'] ?? 2);

        $payload = [];
        if (isset($input['product']))               $payload['product'] = trim((string) $input['product']);
        if (isset($input['product_no']))            $payload['product_no'] = (int) $input['product_no'];
        if (isset($input['product_description']))   $payload['product_description'] = trim((string) $input['product_description']);
        if (isset($input['measurement']))           $payload['measurement'] = trim((string) $input['measurement']);
        if (isset($input['product_reorder_point'])) $payload['product_reorder_point'] = (int) $input['product_reorder_point'];
        if (isset($input['expiry_warning_days']))   $payload['expiry_warning_days'] = (int) $input['expiry_warning_days'];
        if (isset($input['expiry_danger_days']))    $payload['expiry_danger_days'] = (int) $input['expiry_danger_days'];

        if (! empty($input['type_id'])) {
            $payload['type_id'] = (int) $input['type_id'];
        } elseif (! empty($input['type_name'])) {
            $payload['type_id'] = $this->typeModel->firstOrCreate(trim($input['type_name']), $officeId);
        }

        if (! empty($input['unit_id'])) {
            $payload['unit_id'] = (int) $input['unit_id'];
        } elseif (! empty($input['unit_name'])) {
            $payload['unit_id'] = $this->unitModel->firstOrCreate(trim($input['unit_name']), $officeId);
        }

        if (! empty($input['entity_id'])) {
            $payload['entity_id'] = (int) $input['entity_id'];
        } elseif (! empty($input['entity_name'])) {
            $payload['entity_id'] = $this->entityModel->firstOrCreate(trim($input['entity_name']), $officeId);
        }

        if (empty($payload)) {
            return $this->respondError('No fields to update provided.');
        }

        $updated = $this->productModel->update($id, $payload);
        if (! $updated) {
            return $this->respondError('Failed to update product', $this->productModel->errors());
        }

        $product = $this->productModel->findProduct($id);
        return $this->respondSuccess($product, 'Product updated successfully');
    }

    /**
     * Delete product
     * DELETE /api/products/{id}
     */
    public function delete($id = null): ResponseInterface
    {
        $id = (int) $id;
        $existing = $this->productModel->find($id);

        if (! $existing) {
            return $this->respondError('Product not found', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $this->productModel->delete($id);
        return $this->respondSuccess(null, 'Product deleted successfully');
    }
}
