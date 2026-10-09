<?php

namespace App\Controllers\Api;

use App\Models\ProductModel;
use App\Models\ReportModel;
use App\Models\TransactionModel;
use Dompdf\Dompdf;
use Dompdf\Options;
use CodeIgniter\HTTP\ResponseInterface;

class ExportController extends BaseApiController
{
    /** 2.50 → "2.5", 3.00 → "3" */
    private function qty(float|int|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function userOfficeId(): int
    {
        return $this->currentOfficeId();
    }

    private function isMonth(string $value): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * Text that Excel would run as a formula (=, +, -, @, tab, CR at the start, e.g. a product
     * named "=HYPERLINK(…)") gets a leading apostrophe so it shows as plain text. Numbers stay as they are.
     */
    private function csvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    /** fputcsv with every text cell made formula-safe */
    private function putCsv($handle, array $cells): void
    {
        fputcsv($handle, array_map(fn ($c) => $this->csvCell($c), $cells));
    }

    /**
     * Product dropdown for the stockcard export form.
     * GET /api/export/stockcard/options
     */
    public function stockcardOptions(): ResponseInterface
    {
        $productModel = new ProductModel();
        return $this->respondSuccess([
            'products' => $productModel->listForSelect($this->userOfficeId()),
        ], 'Export options retrieved');
    }

    /**
     * POST /api/export/stockcard   { format: pdf|csv|word, product_id?, month_from, month_to, sort_order?, paper_size? }
     */
    public function stockcardDownload()
    {
        $input = $this->input();
        if (empty($input)) {
            $input = $this->request->getGet();
        }

        $format    = trim((string) ($input['format']     ?? ''));
        $productId = (int)         ($input['product_id'] ?? 0);
        $monthFrom = trim((string) ($input['month_from'] ?? ''));
        $monthTo   = trim((string) ($input['month_to']   ?? ''));
        $sortOrder = ($input['sort_order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
        $paperSize = $input['paper_size'] ?? 'long';

        // Normalise paper size → dompdf paper name + CSS page size
        $paperMap  = [
            'a4'    => ['dompdf' => 'A4',     'css' => 'A4'],
            'long'  => ['dompdf' => 'folio',  'css' => '8.5in 13in'],   // Philippine Long Bond
            'short' => ['dompdf' => 'letter', 'css' => 'letter'],        // 8.5 × 11
        ];
        $paper = $paperMap[$paperSize] ?? $paperMap['long'];


        if (! in_array($format, ['pdf', 'csv', 'word'], true)) {
            return $this->respondError('Please select a download format.');
        }
        if ($monthFrom === '' || $monthTo === '') {
            return $this->respondError('Please select a date range.');
        }
        // YYYY-MM only: the months also become part of the download's file name
        if (! $this->isMonth($monthFrom) || ! $this->isMonth($monthTo)) {
            return $this->respondError('Please select a valid date range.');
        }

        $dateFrom = $monthFrom . '-01';
        $dateTo   = date('Y-m-d', strtotime($monthTo . '-01 +1 month'));

        if ($dateFrom > $dateTo) {
            return $this->respondError('From Month must be before To Month.');
        }

        $products = $this->fetchExportData($dateFrom, $dateTo, $productId, $this->userOfficeId(), $sortOrder);
        $filename = 'Stockcard_' . $monthFrom . '_to_' . $monthTo;

        return match ($format) {
            'csv'  => $this->downloadCsv($products, $filename),
            'word' => $this->downloadWord($products, $filename, $paper),
            'pdf'  => $this->downloadPdf($products, $filename, $paper),
        };
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Data: per-transaction rows grouped by product
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function fetchExportData(
        string $dateFrom,
        string $dateTo,
        int $productId,
        int $userOfficeId,
        string $sortOrder
    ): array {
        $db = db_connect();

        $builder = $db->table('transaction_table t')
            ->select([
                't.transaction_id',
                't.transaction_date',
                't.transaction_type_id',
                'tt.transaction_type',
                't.transaction_qty',
                'b.product_id',
                'p.product',
                'p.product_description',
                'p.stock_no',
                'p.product_reorder_point',
                'COALESCE(ut.unit, "pcs") AS unit',
                'COALESCE(r.reference, "") AS reference',
                'COALESCE(o.office_name, COALESCE(uot.user_office_name, "")) AS office',
                'COALESCE(e.entity, "") AS entity_name',
                'COALESCE(e.fund_cluster, "") AS fund_cluster',
            ])
            ->join('batch_table b',         't.batch_id = b.batch_id')
            ->join('transaction_type_table tt', 'tt.transaction_type_id = t.transaction_type_id')
            ->join('product_table p',        'b.product_id = p.product_id')
            ->join('unit_table ut',          'p.unit_id = ut.unit_id',           'left')
            ->join('reference_table r',      't.reference_id = r.reference_id',  'left')
            ->join('office_table o',         't.office_id = o.office_id',         'left')
            ->join('user_office_table uot',  't.user_office_id = uot.user_office_id', 'left')
            ->join('entity_table e',         'p.entity_id = e.entity_id',         'left')
            ->where('t.transaction_date >=', $dateFrom)
            ->where('t.transaction_date <',  $dateTo)
            ->whereIn('tt.transaction_type', array_merge(TransactionModel::STOCK_IN_TYPES, TransactionModel::STOCK_OUT_TYPES));

        if ($userOfficeId > 0) { $builder->where('t.user_office_id', $userOfficeId); }
        if ($productId    > 0) { $builder->where('b.product_id',     $productId); }

        $transactions = $builder
            ->orderBy('b.product_id',        'ASC')
            ->orderBy('t.transaction_date',   $sortOrder)
            ->orderBy('t.transaction_id',     $sortOrder)
            ->get()->getResultArray();

        // Opening balance per product (everything before dateFrom)
        $productIds      = array_unique(array_column($transactions, 'product_id'));
        $openingBalances = [];

        if (! empty($productIds)) {
            $ph  = implode(',', array_fill(0, count($productIds), '?'));
            $in  = "'" . implode("','", TransactionModel::STOCK_IN_TYPES) . "'";
            $out = "'" . implode("','", TransactionModel::STOCK_OUT_TYPES) . "'";
            $sql = 'SELECT b.product_id,
                           SUM(CASE WHEN tt.transaction_type IN (' . $in . ')  THEN t.transaction_qty ELSE 0 END) AS receipts,
                           SUM(CASE WHEN tt.transaction_type IN (' . $out . ') THEN t.transaction_qty ELSE 0 END) AS issues
                    FROM transaction_table t
                    INNER JOIN batch_table b ON t.batch_id = b.batch_id
                    INNER JOIN transaction_type_table tt ON tt.transaction_type_id = t.transaction_type_id
                    WHERE t.transaction_date < ?
                      AND b.product_id IN (' . $ph . ')'
                . ($userOfficeId > 0 ? ' AND t.user_office_id = ' . (int) $userOfficeId : '')
                . ' GROUP BY b.product_id';

            foreach ($db->query($sql, array_merge([$dateFrom], $productIds))->getResultArray() as $row) {
                $openingBalances[(int) $row['product_id']] = (float) $row['receipts'] - (float) $row['issues'];
            }
        }

        $productMap = [];
        foreach ($transactions as $txn) {
            $pid = (int) $txn['product_id'];
            if (! isset($productMap[$pid])) {
                $productMap[$pid] = [
                    'product_id'    => $pid,
                    'product'       => $txn['product'],
                    'description'   => $txn['product_description'],
                    'stock_no'      => $txn['stock_no'],
                    'reorder_point' => $txn['product_reorder_point'],
                    'unit'          => $txn['unit'],
                    'entity_name'   => $txn['entity_name'],
                    'fund_cluster'  => $txn['fund_cluster'],
                    'balance'       => $openingBalances[$pid] ?? 0,
                    'rows'          => [],
                ];
            }

            $qty    = (float) $txn['transaction_qty'];

            if (in_array($txn['transaction_type'], TransactionModel::STOCK_IN_TYPES, true)) {
                $productMap[$pid]['balance'] += $qty;
                $receiptQty = $qty;
                $issueQty   = null;
            } else {
                $productMap[$pid]['balance'] -= $qty;
                $receiptQty = null;
                $issueQty   = $qty;
            }

            $productMap[$pid]['rows'][] = [
                'date'      => $txn['transaction_date'],
                'reference' => $txn['reference'],
                'receipt'   => $receiptQty,
                'issue'     => $issueQty,
                'office'    => $txn['office'],
                'balance'   => $productMap[$pid]['balance'],
            ];
        }

        return array_values($productMap);
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // CSV
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadCsv(array $products, string $filename): \CodeIgniter\HTTP\Response
    {
        ob_start();
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");

        foreach ($products as $p) {
            $this->putCsv($fp, ['STOCK CARD']);
            $this->putCsv($fp, ['Entity Name:', $p['entity_name'], 'Fund Cluster:', $p['fund_cluster']]);
            $this->putCsv($fp, ['Item:', $p['product'], 'Stock No.:', $p['stock_no']]);
            $this->putCsv($fp, ['Description:', $p['description'], 'Re-order Point:', $p['reorder_point']]);
            $this->putCsv($fp, ['Unit of Measurement:', $p['unit']]);
            $this->putCsv($fp, []);
            $this->putCsv($fp, ['Date', 'Reference', 'Receipt Qty', 'Issue Qty', 'Office', 'Balance Qty', 'No. of Days to Consume']);
            foreach ($p['rows'] as $row) {
                $this->putCsv($fp, [
                    $row['date'],
                    $row['reference'],
                    $row['receipt'] ?? '',
                    $row['issue']   ?? '',
                    $row['office'],
                    $row['balance'],
                    '',
                ]);
            }
            $this->putCsv($fp, []);
        }

        fclose($fp);
        $csv = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($csv);
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Word
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadWord(array $products, string $filename, array $paper): \CodeIgniter\HTTP\Response
    {
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.ms-word')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.doc"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($this->buildStockcardHtml($products, $paper['css']));
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // PDF
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadPdf(array $products, string $filename, array $paper): \CodeIgniter\HTTP\Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildStockcardHtml($products, $paper['css']), 'UTF-8');
        $dompdf->setPaper($paper['dompdf'], 'portrait');
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.pdf"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($dompdf->output());
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // HTML template
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function buildStockcardHtml(array $products, string $cssPageSize = 'A4'): string
    {
        $cards = '';

        foreach ($products as $idx => $p) {
            $entity   = htmlspecialchars($p['entity_name']  ?? '');
            $cluster  = htmlspecialchars($p['fund_cluster'] ?? '');
            $item     = htmlspecialchars($p['product']      ?? '');
            $stockNo  = htmlspecialchars($p['stock_no']     ?? '');
            $desc     = htmlspecialchars($p['description']  ?? '');
            $reorder  = htmlspecialchars((string) ($p['reorder_point'] ?? ''));
            $unit     = htmlspecialchars($p['unit']         ?? '');
            $pb       = $idx > 0 ? ' style="page-break-before:always;"' : '';

            $rows = '';
            foreach ($p['rows'] as $row) {
                $date    = $row['date']    ? date('m/d/Y', strtotime($row['date'])) : '';
                $ref     = htmlspecialchars((string) ($row['reference'] ?? ''));
                $receipt = $row['receipt'] !== null ? $this->qty($row['receipt']) : '';
                $issue   = $row['issue']   !== null ? $this->qty($row['issue'])   : '';
                $office  = htmlspecialchars((string) ($row['office'] ?? ''));
                $balance = $this->qty($row['balance']);

                $rows .= '<tr>'
                    . "<td class=\"c-date\">{$date}</td>"
                    . "<td class=\"c-ref\">{$ref}</td>"
                    . "<td class=\"c-qty\">{$receipt}</td>"
                    . "<td class=\"c-qty\">{$issue}</td>"
                    . "<td class=\"c-off\">{$office}</td>"
                    . "<td class=\"c-qty\">{$balance}</td>"
                    . '<td class="c-days"></td>'
                    . '</tr>';
            }

            // Pad to 30 rows
            for ($i = count($p['rows']); $i < 30; $i++) {
                $rows .= '<tr><td class="c-date">&nbsp;</td><td class="c-ref"></td>'
                    . '<td class="c-qty"></td><td class="c-qty"></td>'
                    . '<td class="c-off"></td><td class="c-qty"></td>'
                    . '<td class="c-days"></td></tr>';
            }

            $cards .= <<<CARD
<div class="sc-page"{$pb}>

  <div class="sc-appendix">Appendix 58</div>
  <div class="sc-title">STOCK CARD</div>

  <table class="sc-entity-tbl">
    <tr>
      <td class="lbl">Entity Name :</td>
      <td class="entity-val">{$entity}</td>
      <td class="lbl fc-lbl">Fund Cluster :</td>
      <td class="fc-val">{$cluster}</td>
    </tr>
  </table>

  <!-- ONE unified table: info rows + header rows + data rows â€” columns always align -->
  <table class="sc-data">

    <!-- Info rows: left=cols1-5 (64%) | right=cols6-7 aligned with Balance -->
    <tr>
      <td class="i-left" colspan="5">Item : {$item}</td>
      <td class="i-right" colspan="2">Stock No. : <strong>{$stockNo}</strong></td>
    </tr>
    <tr>
      <td class="i-left" colspan="5">Description : {$desc}</td>
      <td class="i-right" colspan="2">Re-order Point : <strong>{$reorder}</strong></td>
    </tr>
    <tr>
      <td class="i-left" colspan="7">Unit of Measurement : {$unit}</td>
    </tr>

    <!-- â”€â”€ Column headers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    <tr>
      <th rowspan="2" style="width:15%">Date</th>
      <th rowspan="2" style="width:15%">Reference</th>
      <th style="width:9%">Receipt</th>
      <th colspan="2" style="width:25%">Issue</th>
      <th style="width:18%">Balance</th>
      <th rowspan="2" style="width:18%">No. of Days<br>to Consume</th>
    </tr>
    <tr>
      <th>Qty.</th>
      <th>Qty.</th>
      <th>Office</th>
      <th>Qty.</th>
    </tr>

    <!-- â”€â”€ Data rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
    {$rows}

  </table>

</div>
CARD;
        }

        if ($cards === '') {
            $cards = '<div class="sc-page"><p style="margin-top:40px;text-align:center;">No transactions found.</p></div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Stock Card</title>
<style>
* { margin:0; padding:0; }

body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    color: #000;
    background: #fff;
}

/* â”€â”€ Page wrapper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
   NO width:100% here â€” block elements auto-fill their container.
   In dompdf's content-box model, padding then correctly SUBTRACTS
   from the available width rather than adding to it.
   This gives proper left/right margins without overflow.            */
.sc-page {
    padding: 8mm 16mm 6mm 16mm;
}

/* Appendix 58 â€” upper right */
.sc-appendix {
    text-align: right;
    font-style: italic;
    font-size: 10pt;
    margin-bottom: 1mm;
}

/* Title */
.sc-title {
    text-align: center;
    font-size: 15pt;
    font-weight: bold;
    text-decoration: underline;
    letter-spacing: 4px;
    margin-bottom: 5px;
}

/* Entity / Fund Cluster row */
.sc-entity-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 3px;
    font-size: 12pt;
}
.lbl        { white-space: nowrap; padding-right: 4px; }
.entity-val { font-weight: bold; text-decoration: underline; width: 55%; }
.fc-lbl     { padding-left: 10px; white-space: nowrap; }
.fc-val     { font-weight: bold; text-decoration: underline; width: 60px; text-align: center; }

/* Info rows â€” inside .sc-data; !important overrides td{text-align:center} */
.sc-data td.i-left  { text-align: left !important; padding: 3px 6px; font-size: 12pt; }
.sc-data td.i-rlbl  { text-align: left !important; padding: 3px 5px; font-size: 11pt; white-space: normal; vertical-align: middle; }
.sc-data td.i-rval  { text-align: center !important; padding: 3px 5px; font-size: 12pt; font-weight: bold; vertical-align: middle; }
.sc-data td.i-right { text-align: left !important; padding: 3px 6px; font-size: 12pt; }

/* Transaction data table */
.sc-data {
    width: 100%;
    border-collapse: collapse;
    margin-top: 5px;
    font-size: 11pt;
    table-layout: fixed;
}
.sc-data th, .sc-data td {
    border: 1px solid #000;
    padding: 2px 3px;
    text-align: center;
    height: 22px;
    overflow: hidden;
    word-break: break-word;
}
.sc-data thead th {
    font-weight: bold;
    font-size: 10pt;
    vertical-align: middle;
}

/* Cell alignment */
.c-date { text-align: left; }
.c-ref  { text-align: center; }
.c-qty  { text-align: right; padding-right: 4px; }
.c-off  { text-align: center; }
.c-days { text-align: center; }

@page { size: {$cssPageSize} portrait; margin: 8mm; }
</style>
</head>
<body>
{$cards}
</body>
</html>
HTML;
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // SUMMARY REPORT EXPORT  (Inventory Report / batchlist)
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * Product-type dropdown for the summary export form.
     * GET /api/export/summary/options
     */
    public function summaryOptions(): ResponseInterface
    {
        $reportModel = new ReportModel();
        return $this->respondSuccess([
            'productTypes' => $reportModel->orderedProductTypes($this->userOfficeId()),
        ], 'Export options retrieved');
    }

    /**
     * POST /api/export/summary   { format: pdf|csv|word, month_from, month_to, paper_size?, type_id?, search? }
     */
    public function summaryDownload()
    {
        $input     = $this->input();
        $format    = trim((string) ($input['format']     ?? ''));
        $monthFrom = trim((string) ($input['month_from'] ?? ''));
        $monthTo   = trim((string) ($input['month_to']   ?? ''));
        $paperSize = $input['paper_size'] ?? 'long';
        $typeId    = (int) ($input['type_id'] ?? 0);
        $search    = trim((string) ($input['search'] ?? ''));

        $paperMap = [
            'a4'    => ['dompdf' => 'A4',     'css' => 'A4'],
            'long'  => ['dompdf' => 'folio',  'css' => '8.5in 13in'],
            'short' => ['dompdf' => 'letter', 'css' => 'letter'],
        ];
        $paper = $paperMap[$paperSize] ?? $paperMap['long'];

        if (! in_array($format, ['pdf', 'csv', 'word'], true)) {
            return $this->respondError('Please select a download format.');
        }
        if ($monthFrom === '' || $monthTo === '') {
            return $this->respondError('Please select a date range.');
        }
        // YYYY-MM only: the months also become part of the download's file name
        if (! $this->isMonth($monthFrom) || ! $this->isMonth($monthTo)) {
            return $this->respondError('Please select a valid date range.');
        }

        $dateFrom = $monthFrom . '-01';
        $dateTo   = date('Y-m-d', strtotime($monthTo . '-01 +1 month'));

        if ($dateFrom > $dateTo) {
            return $this->respondError('From Month must be before To Month.');
        }

        $reportModel = new ReportModel();

        // Fetch multi-month summarized data (one row per product per month)
        $multiMonth = $reportModel->batchLedgerMultiMonth($monthFrom, $monthTo, $search, $typeId, $this->userOfficeId());

        // Resolve a human-readable report title
        if ($typeId > 0) {
            $allTypes    = $reportModel->orderedProductTypes($this->userOfficeId());
            $typeName    = '';
            foreach ($allTypes as $pt) {
                if ((int) $pt['type_id'] === $typeId) {
                    $typeName = $pt['type'];
                    break;
                }
            }
            $reportTitle = ($typeName !== '' ? $typeName . ' ' : '') . 'Inventory';
        } else {
            $reportTitle = 'Inventory Report';
        }

        $filename = 'SummaryReport_' . $monthFrom . '_to_' . $monthTo;

        // Build flat rows for CSV and grouped structure for PDF/Word
        $allRows = [];
        $multiGrouped = []; // monthLabel => [ typeName => rows ]
        foreach ($multiMonth as $monthLabel => $report) {
            foreach (($report['rows'] ?? []) as $row) {
                $row['month_label'] = $monthLabel;
                $allRows[] = $row;
            }
            $multiGrouped[$monthLabel] = $report['groupedRows'] ?? [];
        }

        return match ($format) {
            'csv'  => $this->downloadSummaryCsv($allRows, $filename, $reportTitle),
            'word' => $this->downloadSummaryWord($multiGrouped, $filename, $reportTitle, $paper),
            'pdf'  => $this->downloadSummaryPdf($multiGrouped, $filename, $reportTitle, $paper),
        };
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Summary CSV
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadSummaryCsv(array $rows, string $filename, string $title): \CodeIgniter\HTTP\Response
    {
        ob_start();
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM

        $this->putCsv($fp, [strtoupper($title)]);
        $this->putCsv($fp, []);

        $csvHeader = [
            'No.', 'Items/Products',
            'Beg. Qty', 'Unit', 'Beg. Unit Cost', 'Beg. Amount',
            'Purch. Qty', 'Purch. Unit Cost', 'Purch. Amount',
            'Used Qty',  'Used Unit Cost',  'Used Amount',
            'Breakage Qty', 'Breakage Unit Cost', 'Breakage Amount',
            'End. Qty', 'End. Unit Cost', 'End. Amount',
        ];

        // Group rows by month_label
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['month_label'] ?? 'Unknown'][] = $row;
        }

        foreach ($byMonth as $monthLabel => $monthRows) {
            // Month header
            $this->putCsv($fp, [strtoupper($monthLabel)]);
            $this->putCsv($fp, $csvHeader);

            foreach ($monthRows as $row) {
                $this->putCsv($fp, [
                    $row['counter'],
                    $row['item'],
                    $row['begin_qty'],
                    $row['unit_name'],
                    number_format($row['begin_cost'],    2, '.', ''),
                    number_format($row['begin_qty']  * $row['begin_cost'],    2, '.', ''),
                    $row['purchase_qty'],
                    number_format($row['purchase_cost'], 2, '.', ''),
                    number_format($row['purchase_total'],2, '.', ''),
                    $row['used_qty'],
                    number_format($row['used_cost'],     2, '.', ''),
                    number_format($row['used_total'],    2, '.', ''),
                    $row['spoiled_qty'],
                    number_format($row['spoiled_cost'],  2, '.', ''),
                    number_format($row['spoiled_total'], 2, '.', ''),
                    $row['ending_qty'],
                    number_format($row['ending_cost'],   2, '.', ''),
                    number_format($row['ending_qty'] * $row['ending_cost'], 2, '.', ''),
                ]);
            }

            // Month totals row
            $totals = array_reduce($monthRows, function ($carry, $r) {
                $carry['begin_amt']   += $r['begin_qty']   * $r['begin_cost'];
                $carry['purch_total'] += $r['purchase_total'];
                $carry['used_total']  += $r['used_total'];
                $carry['spoil_total'] += $r['spoiled_total'];
                $carry['end_amt']     += $r['ending_qty']  * $r['ending_cost'];
                return $carry;
            }, ['begin_amt' => 0, 'purch_total' => 0, 'used_total' => 0, 'spoil_total' => 0, 'end_amt' => 0]);

            $this->putCsv($fp, [
                '', 'TOTAL', '', '', '', number_format($totals['begin_amt'],   2, '.', ''),
                '', '', number_format($totals['purch_total'], 2, '.', ''),
                '', '', number_format($totals['used_total'],  2, '.', ''),
                '', '', number_format($totals['spoil_total'], 2, '.', ''),
                '', '', number_format($totals['end_amt'],     2, '.', ''),
            ]);

            $this->putCsv($fp, []); // blank line between months
        }

        fclose($fp);
        $csv = ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($csv);
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Summary Word
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadSummaryWord(array $groupedRows, string $filename, string $title, array $paper): \CodeIgniter\HTTP\Response
    {
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.ms-word')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.doc"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($this->buildSummaryHtml($groupedRows, $title, $paper['css'], wordMode: true));
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Summary PDF
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadSummaryPdf(array $groupedRows, string $filename, string $title, array $paper): \CodeIgniter\HTTP\Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildSummaryHtml($groupedRows, $title, $paper['css']), 'UTF-8');
        $dompdf->setPaper($paper['dompdf'], 'landscape');
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.pdf"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($dompdf->output());
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // HTML template for summary report
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function buildSummaryHtml(array $multiGrouped, string $title, string $cssPageSize = '8.5in 13in', bool $wordMode = false): string
    {
        // â”€â”€ Word-specific landscape CSS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $wordCss = $wordMode ? '
@page WordSection1 {
    size: 13.0in 8.5in;
    mso-page-orientation: landscape;
    margin: 10mm;
}
div.WordSection1 { page: WordSection1; }
body { mso-page-orientation: landscape; }
' : '';

        $wordOpen  = $wordMode ? '<div class="WordSection1">' : '';
        $wordClose = $wordMode ? '</div>' : '';
        $xmlNs     = $wordMode
            ? '<?xml version="1.0" encoding="UTF-8"?>'
              . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>'
              . '<w:Zoom>75</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->'
            : '';

        // â”€â”€ Shared table header HTML â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $tableHead = <<<THEAD
    <thead>
      <tr>
        <th rowspan="2" class="c-no">NO.</th>
        <th rowspan="2" class="c-item">ITEMS/PRODUCTS</th>
        <th colspan="4">BEGINNING INVENTORY</th>
        <th colspan="3">PURCHASES</th>
        <th colspan="3">USED</th>
        <th colspan="3">BREAKAGE</th>
        <th colspan="3">ENDING INVENTORY</th>
      </tr>
      <tr>
        <th class="c-qty">Qty</th>
        <th class="c-unit">Unit</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">Qty</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">Qty</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">Qty</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">Qty</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
      </tr>
    </thead>
THEAD;

        // â”€â”€ Build one page per product type â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $pages     = '';
        $pageIndex = 0;

        foreach ($multiGrouped as $monthLabel => $groupedRows) {
        foreach ($groupedRows as $typeName => $typeRows) {
            $pageTitle  = htmlspecialchars($typeName . ' Inventory');
            $monthTitle = htmlspecialchars(strtoupper($monthLabel));
            $breakStyle = $pageIndex > 0 ? ' style="page-break-before:always;"' : '';

            $tbody       = '';
            $typeCounter = 1;   // resets to 1 for every product type
            $secTotals   = [
                'begin_amt'   => 0,
                'purch_total' => 0,
                'used_total'  => 0,
                'spoil_total' => 0,
                'end_amt'     => 0,
            ];

            foreach ($typeRows as $row) {
                $item    = htmlspecialchars($row['item']      ?? '');
                $unit    = htmlspecialchars($row['unit_name'] ?? '');
                $counter = $typeCounter++;

                $beginQty  = (float) $row['begin_qty'];
                $beginCost = (float) $row['begin_cost'];
                $beginAmt  = $beginQty * $beginCost;

                $purchQty   = (float) $row['purchase_qty'];
                $purchCost  = (float) $row['purchase_cost'];
                $purchTotal = (float) $row['purchase_total'];

                $usedQty   = (float) $row['used_qty'];
                $usedCost  = (float) $row['used_cost'];
                $usedTotal = (float) $row['used_total'];

                $spoilQty   = (float) $row['spoiled_qty'];
                $spoilCost  = (float) $row['spoiled_cost'];
                $spoilTotal = (float) $row['spoiled_total'];

                $endQty  = (float) $row['ending_qty'];
                $endCost = (float) $row['ending_cost'];
                $endAmt  = $endQty * $endCost;

                $secTotals['begin_amt']   += $beginAmt;
                $secTotals['purch_total'] += $purchTotal;
                $secTotals['used_total']  += $usedTotal;
                $secTotals['spoil_total'] += $spoilTotal;
                $secTotals['end_amt']     += $endAmt;

                $f = fn($v) => $v != 0 ? number_format($v, 2) : '-';
                $q = fn($v) => $v != 0 ? ($v == (int) $v ? (string)(int) $v : rtrim(number_format($v, 2, '.', ''), '0')) : '-';

                $tbody .= '<tr>'
                    . "<td class='c-no'>{$counter}</td>"
                    . "<td class='c-item'>{$item}</td>"
                    . "<td class='c-qty'>{$q($beginQty)}</td>"
                    . "<td class='c-unit'>{$unit}</td>"
                    . "<td class='c-cost'>{$f($beginCost)}</td>"
                    . "<td class='c-amt'>{$f($beginAmt)}</td>"
                    . "<td class='c-qty'>{$q($purchQty)}</td>"
                    . "<td class='c-cost'>{$f($purchCost)}</td>"
                    . "<td class='c-amt'>{$f($purchTotal)}</td>"
                    . "<td class='c-qty'>{$q($usedQty)}</td>"
                    . "<td class='c-cost'>{$f($usedCost)}</td>"
                    . "<td class='c-amt'>{$f($usedTotal)}</td>"
                    . "<td class='c-qty'>{$q($spoilQty)}</td>"
                    . "<td class='c-cost'>{$f($spoilCost)}</td>"
                    . "<td class='c-amt'>{$f($spoilTotal)}</td>"
                    . "<td class='c-qty'>{$q($endQty)}</td>"
                    . "<td class='c-cost'>{$f($endCost)}</td>"
                    . "<td class='c-amt'>{$f($endAmt)}</td>"
                    . '</tr>';
            }

            // Total row for this type
            $f2 = fn($v) => number_format($v, 2);
            $tbody .= '<tr class="sec-total">'
                . "<td colspan='2' class='c-lbl'>TOTAL</td>"
                . "<td colspan='4' class='c-amt'>{$f2($secTotals['begin_amt'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['purch_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['used_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['spoil_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['end_amt'])}</td>"
                . '</tr>';

            // One self-contained page per type
            $pages .= <<<PAGE
<div class="rpt-page"{$breakStyle}>
  <div class="rpt-subtitle">{$monthTitle}</div>
  <div class="rpt-title">{$pageTitle}</div>
  <table class="rpt-tbl">
{$tableHead}
    <tbody>
      {$tbody}
    </tbody>
  </table>
</div>
PAGE;
            $pageIndex++;
        }
        }

        if ($pages === '') {
            $pages = '<div class="rpt-page"><p style="text-align:center;padding:20px;">No data found for the selected period.</p></div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
<meta charset="UTF-8">
<title>Inventory Report</title>
{$xmlNs}
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
{$wordCss}
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 7.5pt;
    color: #000;
    background: #fff;
}
.rpt-page {
    padding: 8mm 6mm 6mm 6mm;
}
.rpt-subtitle {
    text-align: center;
    font-size: 9pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 2px;
    color: #333;
}
.rpt-title {
    text-align: center;
    font-size: 12pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 6px;
    text-decoration: underline;
}
table.rpt-tbl {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 7pt;
}
.rpt-tbl th, .rpt-tbl td {
    border: 0.5pt solid #000;
    padding: 2px 2px;
    vertical-align: middle;
    text-align: center;
    height: 16px;
    overflow: hidden;
    word-break: break-word;
}
.rpt-tbl thead tr:first-child th {
    background: #fff;
    color: #000;
    font-weight: bold;
    font-size: 7pt;
    border: 1pt solid #000;
}
.rpt-tbl thead tr:nth-child(2) th {
    background: #fff;
    color: #000;
    font-size: 6.5pt;
    border: 0.5pt solid #000;
}
.c-no   { width: 3%; text-align: center; }
.c-item { width: 14%; text-align: left; padding-left: 3px; }
.c-qty  { width: 4%; }
.c-unit { width: 3.5%; }
.c-cost { width: 5.5%; }
.c-amt  { width: 6%; }
.c-lbl  { text-align: left; padding-left: 4px; font-weight: bold; }
tr.sec-total td {
    background: #f0f0f0;
    font-weight: bold;
    font-size: 7pt;
    border: 0.5pt solid #000;
    border-top: 1.5pt solid #000;
}
@page { size: {$cssPageSize} landscape; margin: 8mm; }
</style>
</head>
<body>
{$wordOpen}
{$pages}
{$wordClose}
</body>
</html>
HTML;
    }
}
