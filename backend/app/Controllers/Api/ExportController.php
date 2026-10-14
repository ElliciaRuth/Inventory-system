<?php

namespace App\Controllers\Api;

use App\Libraries\ReportSignatories;
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

    /** Edited summary reports can carry pasted pictures (as data: URIs), so allow a few MB */
    private const MAX_EDITED_REPORT_BYTES = 8 * 1024 * 1024;

    private const REPORT_TAGS = [
        'div', 'p', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img',
        'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    private const REPORT_ATTRIBUTES = [
        'class', 'style', 'colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'border',
        'cellpadding', 'cellspacing', 'src', 'alt', 'href', 'title', 'span',
    ];

    /**
     * Report HTML edited in the browser is rendered by Dompdf, so keep only formatting:
     * no scripts or styles blocks, no event handlers, and no file or remote references
     * (pictures must be inline data: images).
     */
    private function sanitizeReportHtml(string $html): string
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="report-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('report-root');
        if ($root === null) {
            return '';
        }

        $this->sanitizeReportNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function sanitizeReportNode(\DOMNode $node): void
    {
        // Copy first: removing children while walking a live list skips nodes
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);

                if (! in_array($tag, self::REPORT_TAGS, true)) {
                    // Unknown wrappers keep their text; dangerous ones go entirely
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math', 'template', 'noscript', 'form', 'base'], true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    $this->sanitizeReportNode($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name  = strtolower($attr->name);
                    $value = trim($attr->value);

                    $keep = in_array($name, self::REPORT_ATTRIBUTES, true)
                        && ! ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|@import|behavior\s*:/i', $value))
                        && ! ($name === 'src' && ! preg_match('#^data:image/(png|jpe?g|gif);base64,[a-z0-9+/=\s]+$#i', $value))
                        && ! ($name === 'href' && ! preg_match('#^(https?:|mailto:|\#)#i', $value));

                    if (! $keep) {
                        $child->removeAttribute($attr->name);
                    }
                }

                // A picture without an inline source would make Dompdf look for a file
                if ($tag === 'img' && ! $child->hasAttribute('src')) {
                    $node->removeChild($child);
                    continue;
                }

                $this->sanitizeReportNode($child);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
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
     * The report's styles for the editor, as a stylesheet: the editor links it instead of
     * injecting a <style> block, which the server's Content-Security-Policy (style-src 'self')
     * blocks. Adds what only the editor shows: page padding and a dashed line between pages.
     * GET /api/export/summary/editor-style
     */
    public function summaryEditorStyle(): ResponseInterface
    {
        $css = $this->summaryCss()
            . "\nhtml { background: #fff; } body { padding: 12px; }"
            . "\n.rpt-page + .rpt-page { border-top: 2px dashed #9ca3af; margin-top: 12px; }\n";

        return $this->response
            ->setHeader('Content-Type', 'text/css; charset=UTF-8')
            ->setHeader('Cache-Control', 'private, no-cache')
            ->setBody($css);
    }

    /**
     * POST /api/export/summary   { format: pdf|word|preview, month_from, month_to, paper_size?, type_id?, search?, html? }
     *
     * "preview" returns the report pages as JSON for the editor; pdf/word given `html` (the edited
     * pages) print that instead of building the report again.
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
        $html      = is_string($input['html'] ?? null) ? $input['html'] : '';

        $paperMap = [
            'a4'    => ['dompdf' => 'A4',     'css' => 'A4'],
            'long'  => ['dompdf' => 'folio',  'css' => '8.5in 13in'],
            'short' => ['dompdf' => 'letter', 'css' => 'letter'],
        ];
        $paper = $paperMap[$paperSize] ?? $paperMap['long'];

        if (! in_array($format, ['pdf', 'word', 'preview'], true)) {
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

        $filename = 'SummaryReport_' . $monthFrom . '_to_' . $monthTo;

        // Edited in the browser: print what the user sees
        if ($format !== 'preview' && trim($html) !== '') {
            if (strlen($html) > self::MAX_EDITED_REPORT_BYTES) {
                return $this->respondError('The edited report is too large to export. Remove some pictures and try again.');
            }

            $pages = $this->sanitizeReportHtml($html);

            return $format === 'word'
                ? $this->downloadSummaryWord($pages, $filename, $paper)
                : $this->downloadSummaryPdf($pages, $filename, $paper);
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

        $multiGrouped = []; // monthLabel => [ typeName => rows ]
        foreach ($multiMonth as $monthLabel => $report) {
            $multiGrouped[$monthLabel] = $report['groupedRows'] ?? [];
        }

        $pages = $this->buildSummaryPages(
            $multiGrouped,
            $this->reportProjectName(),
            ReportSignatories::forOffice($this->userOfficeId())
        );

        return match ($format) {
            'preview' => $this->respondSuccess([
                'title' => $reportTitle,
                'html'  => $pages,
                'css'   => $this->summaryCss(),
            ], 'Report preview generated'),
            'word' => $this->downloadSummaryWord($pages, $filename, $paper),
            'pdf'  => $this->downloadSummaryPdf($pages, $filename, $paper),
        };
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Summary Word
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadSummaryWord(string $pages, string $filename, array $paper): \CodeIgniter\HTTP\Response
    {
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.ms-word')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '.doc"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($this->wordWithImages($this->wrapSummaryHtml($pages, $paper['css'], wordMode: true)));
    }

    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Summary PDF
    // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function downloadSummaryPdf(string $pages, string $filename, array $paper): \CodeIgniter\HTTP\Response
    {
        $dompdf = new Dompdf($this->reportPdfOptions());
        $this->registerReportFonts($dompdf);
        $dompdf->loadHtml($this->wrapSummaryHtml($pages, $paper['css']), 'UTF-8');
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

    private function buildSummaryPages(array $multiGrouped, string $projectName = 'BSU FOOD PRODUCTS', array $signatories = []): string
    {
        $signatureBlock = $this->reportSignatureBlock($signatories);

        // â”€â”€ Shared table header HTML â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $tableHead = <<<THEAD
    <thead>
      <tr>
        <th rowspan="2" class="c-no">NO.</th>
        <th rowspan="2" class="c-code">CODE</th>
        <th rowspan="2" class="c-item">ITEMS/PRODUCTS</th>
        <th colspan="4">BEGINNING INVENTORY</th>
        <th colspan="3">PURCHASES</th>
        <th colspan="3">USED</th>
        <th colspan="3">BREAKAGE</th>
        <th colspan="3">ENDING INVENTORY</th>
      </tr>
      <tr>
        <th class="c-qty">QTY</th>
        <th class="c-unit">Unit</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">QTY</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">QTY</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">QTY</th>
        <th class="c-cost">Unit Cost</th>
        <th class="c-amt">Amount</th>
        <th class="c-qty">QTY</th>
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
            $pageHeader = $this->reportHeader($projectName, (string) $monthLabel, (string) $typeName);
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
                $code    = htmlspecialchars($row['stock_no']  ?? '');
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
                    . "<td class='c-code'>{$code}</td>"
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
                . "<td colspan='3' class='c-lbl'>TOTAL</td>"
                . "<td colspan='4' class='c-amt'>{$f2($secTotals['begin_amt'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['purch_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['used_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['spoil_total'])}</td>"
                . "<td colspan='3' class='c-amt'>{$f2($secTotals['end_amt'])}</td>"
                . '</tr>';

            // One self-contained page per type
            $pages .= <<<PAGE
<div class="rpt-page"{$breakStyle}>
{$pageHeader}
  <table class="rpt-tbl">
{$tableHead}
    <tbody>
      {$tbody}
    </tbody>
  </table>
{$signatureBlock}
</div>
PAGE;
            $pageIndex++;
        }
        }

        if ($pages === '') {
            $pages = '<div class="rpt-page"><p style="text-align:center;padding:20px;">No data found for the selected period.</p></div>';
        }

        return $pages;
    }

    /** Logos of the report heading, in order, per side; a missing file is left out */
    private const REPORT_LOGOS = [
        'left'  => ['bagong-pilipinas.png' => 'Bagong Pilipinas', 'bsu-seal.png' => 'Benguet State University'],
        'right' => ['bsu-food-products.png' => 'BSU Food Products'],
    ];

    /** Fonts of the heading that the PDF takes from the PC (Microsoft Office installs them) */
    private const REPORT_FONTS = [
        'Old English Text MT' => 'OLDENGL.TTF',
        'Monotype Corsiva'    => 'MTCORSVA.TTF',
    ];

    /** The heading of each report page, as on the office's printed inventory form */
    private function reportHeader(string $projectName, string $monthLabel, string $typeName): string
    {
        $left     = $this->reportLogos('left');
        $right    = $this->reportLogos('right');
        $project  = htmlspecialchars($projectName);
        $month    = htmlspecialchars(strtoupper($monthLabel));
        $category = htmlspecialchars(strtoupper($typeName . ' Inventory'));

        return <<<HTML
  <table class="rpt-head">
    <tr>
      <td class="rpt-head-left">{$left}</td>
      <td class="rpt-head-center">
        <div class="rpt-republic">Republic of the Philippines</div>
        <div class="rpt-university">Benguet State University</div>
        <div class="rpt-address">La Trinidad, 2601 Benguet</div>
        <div class="rpt-office">University Business Affairs Office</div>
      </td>
      <td class="rpt-head-right">{$right}</td>
    </tr>
  </table>
  <div class="rpt-project">{$project}</div>
  <div class="rpt-month">Inventory for the month of {$month}</div>
  <div class="rpt-category">{$category}</div>
HTML;
    }

    /** Logos as inline images: the editor, Dompdf and the Word packager all read them from the page */
    private function reportLogos(string $side): string
    {
        static $cache = [];

        $html = '';
        foreach (self::REPORT_LOGOS[$side] as $file => $alt) {
            $path = FCPATH . 'report-logos' . DIRECTORY_SEPARATOR . $file;
            if (! isset($cache[$file])) {
                $cache[$file] = is_file($path) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : '';
            }
            if ($cache[$file] !== '') {
                $html .= '<img class="rpt-logo" src="' . $cache[$file] . '" alt="' . htmlspecialchars($alt) . '" width="72" height="72">';
            }
        }

        return $html;
    }

    /**
     * Prepared by / Verified by / Noted by under each page's table: the heading on the left of
     * each column, then the name in bold over a line and the position under it. Built from
     * tables (not centred blocks) so Word lays it out the same as the PDF.
     */
    private function reportSignatureBlock(array $signatories): string
    {
        if ($signatories === []) {
            return '';
        }

        $cells = '';
        foreach ($signatories as $signatory) {
            $label = htmlspecialchars((string) ($signatory['label'] ?? ''));
            $name  = trim((string) ($signatory['name'] ?? ''));
            $name  = $name !== '' ? htmlspecialchars(mb_strtoupper($name)) : '&nbsp;';
            $title = htmlspecialchars((string) ($signatory['title'] ?? ''));
            $cells .= <<<CELL
      <td class="rpt-sign-cell">
        <div class="rpt-sign-label">{$label}</div>
        <table class="rpt-sign-line" align="center" width="70%">
          <tr><td class="rpt-sign-gap">&nbsp;</td></tr>
          <tr><td class="rpt-sign-name">{$name}</td></tr>
          <tr><td class="rpt-sign-title">{$title}</td></tr>
        </table>
      </td>
CELL;
        }

        return <<<HTML
  <table class="rpt-sign">
    <tr>
{$cells}
    </tr>
  </table>
HTML;
    }

    /** "BAKERY PROJECT" / "FOOD PROCESSING CENTER", from the signed-in user's office */
    private function reportProjectName(): string
    {
        $officeId = $this->userOfficeId();
        $name     = '';
        if ($officeId > 0) {
            $row  = db_connect()->table('user_office_table')->select('user_office_name')->where('user_office_id', $officeId)->get(1)->getRowArray();
            $name = strtoupper(trim((string) ($row['user_office_name'] ?? '')));
        }

        return match ($name) {
            'BAKERY' => 'BAKERY PROJECT',
            'FPC'    => 'FOOD PROCESSING CENTER',
            ''       => 'BSU FOOD PRODUCTS',
            default  => $name,
        };
    }

    /**
     * Dompdf only knows its own fonts, so the heading's Old English and Corsiva fonts are
     * registered from the Windows font folder when they are there (Microsoft Office installs
     * them); otherwise the heading falls back to a serif font. Registered fonts are kept in
     * writable/, so this is done once.
     */
    private function reportPdfOptions(): Options
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $fontCache = WRITEPATH . 'dompdf-fonts';
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }
        if (is_dir($fontCache)) {
            $options->set('fontDir', $fontCache);
            $options->set('fontCache', $fontCache);
        }

        $systemFonts = rtrim((string) (getenv('WINDIR') ?: 'C:\\Windows'), '\\/') . DIRECTORY_SEPARATOR . 'Fonts';
        if (is_dir($systemFonts)) {
            $options->setChroot([...(array) $options->getChroot(), $systemFonts]);
        }

        return $options;
    }

    private function registerReportFonts(Dompdf $dompdf): void
    {
        $systemFonts = rtrim((string) (getenv('WINDIR') ?: 'C:\\Windows'), '\\/') . DIRECTORY_SEPARATOR . 'Fonts';
        $metrics     = $dompdf->getFontMetrics();

        foreach (self::REPORT_FONTS as $family => $file) {
            $path = $systemFonts . DIRECTORY_SEPARATOR . $file;
            if (! is_file($path) || $metrics->getFamily(strtolower($family)) !== null) {
                continue;
            }
            try {
                $metrics->registerFont(['family' => $family, 'weight' => 'normal', 'style' => 'normal'], $path);
            } catch (\Throwable $e) {
                log_message('warning', "Report font {$family} could not be loaded: " . $e->getMessage());
            }
        }
    }

    /**
     * Word does not show data: images in an HTML document, so the Word download is a
     * single-file web page (MHTML) with every image as its own part. Word opens it as a .doc.
     */
    private function wordWithImages(string $html): string
    {
        $boundary = '----=_BSU_Report_' . bin2hex(random_bytes(8));
        $images   = [];

        $html = preg_replace_callback(
            '#src=(["\'])data:(image/(?:png|jpe?g|gif));base64,([A-Za-z0-9+/=\s]+)\1#i',
            static function (array $m) use (&$images): string {
                $data = preg_replace('/\s+/', '', $m[3]);
                $ext  = match (strtolower($m[2])) { 'image/png' => 'png', 'image/gif' => 'gif', default => 'jpg' };
                $name = 'image_' . substr(sha1($data), 0, 16) . '.' . $ext;
                $images[$name] = [strtolower($m[2]), $data];

                return 'src=' . $m[1] . 'report_files/' . $name . $m[1];
            },
            $html
        ) ?? $html;

        $out = "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/related; boundary=\"{$boundary}\"; type=\"text/html\"\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=\"utf-8\"\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "Content-Location: file:///C:/report.htm\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n";

        foreach ($images as $name => [$type, $data]) {
            $out .= "--{$boundary}\r\n"
                . "Content-Type: {$type}\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Location: file:///C:/report_files/{$name}\r\n\r\n"
                . chunk_split($data, 76, "\r\n");
        }

        return $out . "--{$boundary}--\r\n";
    }

    /** Report styles, shared by the editor preview and the PDF / Word files */
    private function summaryCss(): string
    {
        return <<<'CSS'
* { margin: 0; padding: 0; box-sizing: border-box; }
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
.c-code { width: 6%; text-align: center; }
.c-item { width: 12.5%; text-align: left; padding-left: 3px; }
.c-qty  { width: 4%; }
.c-unit { width: 3.5%; }
.c-cost { width: 5.2%; }
.c-amt  { width: 5.8%; }
.c-lbl  { text-align: left; padding-left: 4px; font-weight: bold; }
tr.sec-total td {
    background: #f0f0f0;
    font-weight: bold;
    font-size: 7pt;
    border: 0.5pt solid #000;
    border-top: 1.5pt solid #000;
}
/* Page heading (University Business Affairs Office template) */
table.rpt-head { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
table.rpt-head td { border: 0; padding: 0; vertical-align: middle; }
td.rpt-head-left { width: 34%; text-align: right; padding-right: 12px; }
td.rpt-head-center { width: 32%; text-align: center; white-space: nowrap; }
td.rpt-head-right { width: 34%; text-align: left; padding-left: 12px; }
img.rpt-logo { width: 0.75in; height: 0.75in; margin: 0 4px; vertical-align: middle; }
.rpt-republic, .rpt-address { font-size: 8pt; line-height: 1.25; }
.rpt-university { font-family: 'Old English Text MT', 'UnifrakturMaguntia', 'Times New Roman', serif; font-size: 15pt; line-height: 1.15; }
.rpt-office { font-family: 'Monotype Corsiva', 'Lucida Calligraphy', 'Times New Roman', serif; font-size: 13.5pt; line-height: 1.2; margin-top: 5px; }
.rpt-project { text-align: center; font-size: 12pt; font-weight: bold; margin-top: 3px; }
.rpt-month { text-align: center; font-size: 9.5pt; font-weight: bold; }
.rpt-category { text-align: center; font-size: 10pt; font-weight: bold; letter-spacing: 5px; margin: 3px 0 4px; }
/* Prepared by / Verified by / Noted by */
table.rpt-sign { width: 100%; border-collapse: collapse; margin-top: 6px; page-break-inside: avoid; }
table.rpt-sign td.rpt-sign-cell { width: 33.33%; vertical-align: top; border: 0; padding: 0; }
.rpt-sign-label { font-size: 8pt; text-align: left; }
/* The spacer row (not a margin) leaves room to sign: Word ignores table margins */
table.rpt-sign-line { width: 70%; margin: 0 auto; border-collapse: collapse; }
table.rpt-sign-line td { border: 0; padding: 0; }
table.rpt-sign-line td.rpt-sign-gap { height: 12pt; font-size: 8pt; }
table.rpt-sign-line td.rpt-sign-name { text-align: center; font-size: 8.5pt; font-weight: bold; border-bottom: 0.75pt solid #000; padding-bottom: 1px; }
table.rpt-sign-line td.rpt-sign-title { text-align: center; font-size: 8pt; padding-top: 1px; }
/* Text added in the editor */
p { margin: 0 0 4pt; }
ul, ol { margin: 0 0 4pt; padding-left: 18pt; }
CSS;
    }

    private function wrapSummaryHtml(string $pages, string $cssPageSize = '8.5in 13in', bool $wordMode = false): string
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

        // Word passes a block's page-break-before on to every paragraph inside it (and skips
        // it when the block starts with a table), so the break moves to Word's own marker
        if ($wordMode) {
            $pages = preg_replace_callback(
                '#<(div|p|table)\b[^>]*page-break-before:\s*always[^>]*>#i',
                static fn(array $m): string => '<p style="page-break-before:always;margin:0;font-size:1pt;line-height:1pt">&nbsp;</p>'
                    . preg_replace('#page-break-before:\s*always;?#i', '', $m[0]),
                $pages
            ) ?? $pages;
        }
        $xmlNs     = $wordMode
            ? '<?xml version="1.0" encoding="UTF-8"?>'
              . '<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>'
              . '<w:Zoom>75</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->'
            : '';

        $css = $this->summaryCss();

        return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
<meta charset="UTF-8">
<title>Inventory Report</title>
{$xmlNs}
<style>
{$wordCss}
{$css}
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
