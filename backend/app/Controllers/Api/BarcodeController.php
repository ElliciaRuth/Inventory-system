<?php

namespace App\Controllers\Api;

use App\Services\BarcodeService;
use CodeIgniter\HTTP\ResponseInterface;

class BarcodeController extends BaseApiController
{
    /**
     * Inline Code 39 SVG for a product (used on stockcard / product pages).
     * GET /api/barcode/product/{id}
     */
    public function product(int $productId): ResponseInterface
    {
        $builder = db_connect()->table('product_table')
            ->select('product_id, stock_no, product_no')
            ->where('product_id', $productId);
        if ($this->currentOfficeId() > 0) {
            $builder->where('user_office_id', $this->currentOfficeId());
        }
        $product = $builder->get(1)->getRowArray();

        if (! $product) {
            return $this->respondError('Product not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $value = $product['stock_no'] ?: 'P-' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT);

        return $this->response
            ->setHeader('Content-Type', 'image/svg+xml')
            ->setBody((new BarcodeService())->svg($value));
    }

    /**
     * Inline Code 39 SVG for a batch.
     * Uses barcode_value if available, otherwise batch_no.
     * GET /api/barcode/batch/{id}
     */
    public function batch(int $batchId): ResponseInterface
    {
        $builder = db_connect()->table('batch_table')
            ->select('batch_id, batch_no, barcode_value')
            ->where('batch_id', $batchId);
        if ($this->currentOfficeId() > 0) {
            $builder->where('user_office_id', $this->currentOfficeId());
        }
        $batch = $builder->get(1)->getRowArray();

        if (! $batch) {
            return $this->respondError('Batch not found.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        // Prefer the stored barcode_value; fall back to batch_no → generated id string
        $value = $batch['barcode_value']
            ?: ($batch['batch_no'] ?: 'B-' . str_pad((string) $batchId, 6, '0', STR_PAD_LEFT));

        return $this->response
            ->setHeader('Content-Type', 'image/svg+xml')
            ->setBody((new BarcodeService())->svg($value));
    }

    /**
     * Look up a batch by its scanned barcode_value (stock-out scanner).
     * GET /api/barcode/lookup?value={scanned_string}
     */
    public function lookupByValue(): ResponseInterface
    {
        $value = trim((string) ($this->request->getGet('value') ?? ''));

        if ($value === '') {
            return $this->respondError('Barcode value is required.', [], ResponseInterface::HTTP_BAD_REQUEST);
        }

        $builder = db_connect()->table('batch_table b')
            ->select('
                b.batch_id,
                b.batch_no,
                b.barcode_value,
                b.current_qty,
                b.expiration_date,
                b.date_received,
                p.product_id,
                p.product,
                p.product_description,
                COALESCE(ut.unit, "pcs") AS unit_name
            ')
            ->join('product_table p', 'b.product_id = p.product_id')
            ->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left')
            ->where('b.barcode_value', $value);

        if ($this->currentOfficeId() > 0) {
            $builder->where('b.user_office_id', $this->currentOfficeId());
        }

        $batch = $builder->get(1)->getRowArray();

        if (! $batch) {
            return $this->respondError('No batch found for this barcode.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        return $this->respondSuccess([
            'batch_id'        => (int) $batch['batch_id'],
            'batch_no'        => $batch['batch_no'],
            'barcode_value'   => $batch['barcode_value'],
            'product_id'      => (int) $batch['product_id'],
            'product'         => $batch['product'],
            'description'     => $batch['product_description'],
            'unit_name'       => $batch['unit_name'],
            'current_qty'     => (float) $batch['current_qty'],
            'expiration_date' => $batch['expiration_date'] ?? null,
            'date_received'   => $batch['date_received'] ?? null,
        ], 'Batch found');
    }

    /**
     * Finished Products for the current office with their saved barcodes.
     * GET /api/products/barcodes
     */
    public function finishedProducts(): ResponseInterface
    {
        $search = trim((string) ($this->request->getGet('search') ?? ''));

        $builder = db_connect()->table('product_table p')
            ->select('
                p.product_id,
                p.product_no,
                p.stock_no,
                p.product,
                COALESCE(ut.unit, "pcs") AS unit_name,
                tp.type
            ')
            ->join('type_of_product tp', 'p.type_id = tp.type_id')
            ->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left')
            ->where('tp.type', 'Finished Product');

        if ($this->currentOfficeId() > 0) {
            $builder->where('p.user_office_id', $this->currentOfficeId());
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('p.product', $search)
                ->orLike('p.stock_no', $search)
                ->orLike('p.product_no', $search)
                ->groupEnd();
        }

        $products = $builder->orderBy('p.product_no', 'ASC')->get()->getResultArray();

        // Attach barcode web paths where the SVG file already exists
        foreach ($products as &$product) {
            $value   = $this->finishedProductBarcodeValue($product);
            $safe    = preg_replace('/[^A-Za-z0-9\-_]/', '_', $value);
            $svgFile = FCPATH . 'barcodes' . DIRECTORY_SEPARATOR . $safe . '.svg';

            $product['barcode_value'] = $value;
            $product['barcode_url']   = file_exists($svgFile) ? base_url('barcodes/' . $safe . '.svg') : null;
        }
        unset($product);

        return $this->respondSuccess([
            'products' => $products,
            'search'   => $search,
        ], 'Finished products retrieved');
    }

    /**
     * Generate & save a barcode SVG for a Finished Product.
     * POST /api/products/barcodes/generate   { product_id }
     */
    public function generateFinishedProductBarcode(): ResponseInterface
    {
        $productId = (int) ($this->input()['product_id'] ?? 0);

        $builder = db_connect()->table('product_table p')
            ->select('p.product_id, p.stock_no, p.user_office_id, tp.type')
            ->join('type_of_product tp', 'p.type_id = tp.type_id')
            ->where('p.product_id', $productId)
            ->where('tp.type', 'Finished Product');
        if ($this->currentOfficeId() > 0) {
            $builder->where('p.user_office_id', $this->currentOfficeId());
        }
        $product = $builder->get(1)->getRowArray();

        if (! $product) {
            return $this->respondError('Product not found or access denied.', [], ResponseInterface::HTTP_NOT_FOUND);
        }

        $url = (new BarcodeService())->saveBatchBarcode($this->finishedProductBarcodeValue($product));

        return $this->respondSuccess([
            'product_id'  => $productId,
            'barcode_url' => $url,
        ], 'Barcode generated for product.');
    }

    private function finishedProductBarcodeValue(array $product): string
    {
        return $product['stock_no'] ?: 'P-' . str_pad((string) $product['product_id'], 6, '0', STR_PAD_LEFT);
    }
}
