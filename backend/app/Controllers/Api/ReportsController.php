<?php

namespace App\Controllers\Api;

use App\Models\ReportModel;
use App\Services\BarcodeService;
use CodeIgniter\HTTP\ResponseInterface;

class ReportsController extends BaseApiController
{
    public function __construct(
        private readonly ReportModel $reportModel = new ReportModel(),
    ) {
    }

    /**
     * Monthly batch ledger summary.
     * GET /api/reports/batchlist
     */
    public function batchlist(): ResponseInterface
    {
        $userOfficeId = $this->currentOfficeId();
        $search       = trim((string) ($this->request->getGet('search') ?? ''));
        $year         = (int) ($this->request->getGet('year') ?? date('Y'));
        $month        = str_pad((string) ($this->request->getGet('month') ?? date('m')), 2, '0', STR_PAD_LEFT);
        $typeId       = (int) ($this->request->getGet('type_id') ?? 0);
        $report       = $this->reportModel->batchLedgerSummarized($search, $year, $month, $typeId, $userOfficeId);

        return $this->respondSuccess([
            ...$report,
            'search'       => $search,
            'year'         => $year,
            'month'        => $month,
            'typeId'       => $typeId,
            'productTypes' => $this->reportModel->orderedProductTypes($userOfficeId),
        ], 'Batch ledger retrieved');
    }

    /**
     * Batch Inventory — each batch_table row with its barcode.
     * GET /api/reports/batches
     */
    public function batches(): ResponseInterface
    {
        $userOfficeId = $this->currentOfficeId();
        $search       = trim((string) ($this->request->getGet('search') ?? ''));
        $showEmpty    = (string) ($this->request->getGet('show_empty') ?? '0') === '1';

        $db      = db_connect();
        $builder = $db->table('batch_table b')
            ->select('
                b.batch_id,
                b.batch_no,
                b.barcode_value,
                b.current_qty,
                b.expiration_date,
                b.date_received,
                p.product_id,
                p.product,
                p.stock_no,
                COALESCE(ut.unit, "pcs") AS unit_name
            ')
            ->join('product_table p', 'b.product_id = p.product_id')
            ->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left');

        if (! $showEmpty) {
            $builder->where('b.current_qty >', 0);
        }

        if ($userOfficeId > 0) {
            $builder->where('b.user_office_id', $userOfficeId);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('p.product', $search)
                ->orLike('b.batch_no', $search)
                ->orLike('b.barcode_value', $search)
                ->groupEnd();
        }

        $batches = $builder
            ->orderBy('b.date_received', 'DESC')
            ->orderBy('b.batch_id', 'DESC')
            ->get()
            ->getResultArray();

        // Saved SVG when it exists, otherwise the on-the-fly image endpoint
        $barcodes = new BarcodeService();
        foreach ($batches as &$batch) {
            $value = (string) ($batch['barcode_value'] ?? '');
            $batch['barcode_url'] = $value === ''
                ? null
                : ($barcodes->savedBarcodePath($value) ?? '/api/barcode/batch/' . (int) $batch['batch_id']);
        }
        unset($batch);

        return $this->respondSuccess([
            'batches'   => $batches,
            'search'    => $search,
            'showEmpty' => $showEmpty,
        ], 'Batches retrieved');
    }
}
