<?php

namespace App\Models;

use CodeIgniter\Model;

class ReportModel extends Model
{
    protected $table = 'transaction_table';

    public function batchLedger(string $search, int $year, string $month, int $typeId, int $userOfficeId = 0): array
    {
        $monthStart = sprintf('%04d-%02d-01', $year, (int) $month);
        $nextMonth  = date('Y-m-d', strtotime($monthStart . ' +1 month'));

        $openingRows = $this->historyRowsBefore($monthStart, $userOfficeId);
        $periodRows  = $this->historyRowsWithin($monthStart, $nextMonth, $search, $typeId, $userOfficeId);

        $batchMemory  = [];
        $runningQty   = [];
        $runningValue = [];

        foreach ($openingRows as $row) {
            $this->applyRowToRunningBalance($row, $batchMemory, $runningQty, $runningValue);
        }

        $ledgerRows = [];
        $counter    = 1;

        foreach ($periodRows as $row) {
            $productId = (int) $row['product_id'];
            $copyId    = (int) ($row['copy_id'] ?? 0);
            $key       = $copyId > 0 ? $productId . '_' . $copyId : (string) $productId;

            $batchMemory[$key]  ??= [];
            $runningQty[$key]   ??= 0.0;
            $runningValue[$key] ??= 0.0;

            $beginQty  = $runningQty[$key];
            $beginCost = $beginQty > 0 ? $runningValue[$key] / $beginQty : 0.0;

            [$purchaseQty, $purchaseCost, $purchaseTotal] = $this->purchaseValues($row, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);
            [$usedQty, $usedCost, $usedTotal]             = $this->issueValues($row, 0, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);
            [$spoiledQty, $spoiledCost, $spoiledTotal]    = $this->issueValues($row, 3, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);

            $endingQty  = $runningQty[$key];
            $endingCost = $endingQty > 0 ? $runningValue[$key] / $endingQty : 0.0;

            $ledgerRows[] = [
                'counter'           => $counter++,
                'product_id'        => $productId,
                'copy_id'           => $copyId,
                'copy_unit_cost'    => (float) ($row['copy_unit_cost'] ?? 0),
                'copy_label'        => $row['copy_label'] ?? '',
                'transaction_id'    => (int) ($row['transaction_id'] ?? 0),
                'transaction_type'  => (int) ($row['transaction_type_id'] ?? 0),
                'product_type'      => $row['product_type'] ?: 'Uncategorized',
                'stock_no'          => $row['stock_no'],
                'item'              => $row['product'],
                'unit_name'         => $row['unit_name'],
                'begin_qty'         => $beginQty,
                'begin_cost'        => $beginCost,
                'purchase_qty'      => $purchaseQty,
                'purchase_cost'     => $purchaseCost,
                'purchase_total'    => $purchaseTotal,
                'used_qty'          => $usedQty,
                'used_cost'         => $usedCost,
                'used_total'        => $usedTotal,
                'spoiled_qty'       => $spoiledQty,
                'spoiled_cost'      => $spoiledCost,
                'spoiled_total'     => $spoiledTotal,
                'ending_qty'        => $endingQty,
                'ending_cost'       => $endingCost,
            ];
        }

        $groupedRows = [];
        foreach ($ledgerRows as $row) {
            $groupedRows[$row['product_type']][] = $row;
        }

        return [
            'rows'        => $ledgerRows,
            'groupedRows' => $groupedRows,
        ];
    }

    /**
     * Summarized version of batchLedger — one row per product per copy (sub-product).
     * Each copy (price variant) gets its own line. This applies to both the web view and exported reports.
     */
    public function batchLedgerSummarized(string $search, int $year, string $month, int $typeId, int $userOfficeId = 0): array
    {
        $report = $this->batchLedger($search, $year, $month, $typeId, $userOfficeId);
        $rows   = $report['rows'] ?? [];

        // Aggregate per product_id + copy_id.
        // Each copy (sub-product / price variant) gets its own summary row.
        $products = [];  // key => aggregated row

        foreach ($rows as $row) {
            $pid    = (int) $row['product_id'];
            $copyId = (int) ($row['copy_id'] ?? 0);

            $key = $pid . '-' . $copyId;

            if (! isset($products[$key])) {
                // First occurrence — seed with beginning values
                $products[$key] = [
                    'product_id'     => $pid,
                    'copy_id'        => $copyId,
                    'copy_unit_cost' => (float) ($row['copy_unit_cost'] ?? 0),
                    'copy_label'     => $row['copy_label'] ?? '',
                    'product_type'   => $row['product_type'],
                    'stock_no'       => $row['stock_no'],
                    'item'           => $row['item'],
                    'unit_name'      => $row['unit_name'],
                    'begin_qty'      => $row['begin_qty'],
                    'begin_cost'     => $row['begin_cost'],
                    'purchase_qty'   => 0,
                    'purchase_total' => 0.0,
                    'used_qty'       => 0,
                    'used_total'     => 0.0,
                    'spoiled_qty'    => 0,
                    'spoiled_total'  => 0.0,
                    'ending_qty'     => $row['ending_qty'],
                    'ending_cost'    => $row['ending_cost'],
                ];
            }

            // Accumulate transaction totals
            $products[$key]['purchase_qty']   += (float) $row['purchase_qty'];
            $products[$key]['purchase_total'] += (float) $row['purchase_total'];
            $products[$key]['used_qty']       += (float) $row['used_qty'];
            $products[$key]['used_total']     += (float) $row['used_total'];
            $products[$key]['spoiled_qty']    += (float) $row['spoiled_qty'];
            $products[$key]['spoiled_total']  += (float) $row['spoiled_total'];

            // Always update ending to the last row's ending values
            $products[$key]['ending_qty']  = $row['ending_qty'];
            $products[$key]['ending_cost'] = $row['ending_cost'];
        }

        // Compute derived cost-per-unit and renumber
        $summarized  = [];
        $counter     = 1;
        foreach ($products as $p) {
            $p['counter']       = $counter++;
            $p['purchase_cost'] = $p['purchase_qty'] > 0 ? $p['purchase_total'] / $p['purchase_qty'] : 0.0;
            $p['used_cost']     = $p['used_qty']     > 0 ? $p['used_total']     / $p['used_qty']     : 0.0;
            $p['spoiled_cost']  = $p['spoiled_qty']  > 0 ? $p['spoiled_total']  / $p['spoiled_qty']  : 0.0;
            $summarized[] = $p;
        }

        // Group by product type
        $groupedRows = [];
        foreach ($summarized as $row) {
            $groupedRows[$row['product_type']][] = $row;
        }

        return [
            'rows'        => $summarized,
            'groupedRows' => $groupedRows,
        ];
    }

    /**
     * Build summarized ledger data for a range of months.
     * Returns: [ 'September 2026' => [ 'groupedRows' => [...], 'rows' => [...] ], ... ]
     */
    public function batchLedgerMultiMonth(
        string $monthFrom,
        string $monthTo,
        string $search,
        int $typeId,
        int $userOfficeId = 0
    ): array {
        $results = [];
        $current = $monthFrom . '-01';
        $end     = $monthTo   . '-01';

        while ($current <= $end) {
            $year  = (int) date('Y', strtotime($current));
            $month = date('m', strtotime($current));
            $label = date('F Y', strtotime($current)); // e.g. "September 2026"

            $report = $this->batchLedgerSummarized($search, $year, $month, $typeId, $userOfficeId);

            if (! empty($report['rows'])) {
                $results[$label] = $report;
            }

            $current = date('Y-m-d', strtotime($current . ' +1 month'));
        }

        return $results;
    }

    public function orderedProductTypes(int $userOfficeId = 0): array
    {
        $builder = $this->db->table('type_of_product')->orderBy('type', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return $builder->get()->getResultArray();
    }

    private function historyRowsBefore(string $monthStart, int $userOfficeId = 0): array
    {
        $officeFilter = $userOfficeId > 0 ? ' AND t.user_office_id = ' . (int) $userOfficeId : '';

        return $this->db->query(
            "SELECT b.product_id, t.transaction_id, t.transaction_qty, t.transaction_unit_cost,
                    t.transaction_type_id, tt.transaction_type,
                    COALESCE(t.copy_id, b.copy_id) AS copy_id
             FROM transaction_table t
             INNER JOIN batch_table b ON t.batch_id = b.batch_id
             INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
             WHERE t.transaction_date < ?{$officeFilter}
             ORDER BY b.product_id ASC, t.transaction_date ASC, t.transaction_id ASC",
            [$monthStart]
        )->getResultArray();
    }

    private function historyRowsWithin(string $monthStart, string $nextMonth, string $search, int $typeId, int $userOfficeId = 0): array
    {
        $builder = $this->db->table('transaction_table t');
        $builder->select([
            'b.product_id',
            'p.product',
            'p.stock_no',
            'COALESCE(pt.type, "Uncategorized") AS product_type',
            'COALESCE(ut.unit, "Deleted Unit") AS unit_name',
            't.transaction_id',
            't.transaction_qty',
            't.transaction_unit_cost',
            't.transaction_type_id',
            'tt.transaction_type',
            'COALESCE(t.copy_id, b.copy_id) AS copy_id',
            'COALESCE(pc.unit_cost, 0) AS copy_unit_cost',
            'COALESCE(pc.label, "") AS copy_label',
        ]);
        $builder->join('batch_table b', 't.batch_id = b.batch_id');
        $builder->join('product_table p', 'b.product_id = p.product_id');
        $builder->join('transaction_type_table tt', 't.transaction_type_id = tt.transaction_type_id');
        $builder->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left');
        $builder->join('type_of_product pt', 'p.type_id = pt.type_id', 'left');
        $builder->join('product_copy_table pc', 'COALESCE(t.copy_id, b.copy_id) = pc.copy_id', 'left');
        $builder->where('t.transaction_date >=', $monthStart);
        $builder->where('t.transaction_date <', $nextMonth);
        $builder->whereIn('tt.transaction_type', ['receipt', 'issue', 'adjust_out', 'borrow', 'return']);

        if ($userOfficeId > 0) {
            $builder->where('t.user_office_id', $userOfficeId);
        }
        if ($search !== '') {
            $builder->like('p.product', $search);
        }
        if ($typeId > 0) {
            $builder->where('p.type_id', $typeId);
        }

        return $builder
            ->orderBy('pt.type', 'ASC')
            ->orderBy('b.product_id', 'ASC')
            ->orderBy('t.transaction_date', 'ASC')
            ->orderBy('t.transaction_id', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function applyRowToRunningBalance(array $row, array &$batchMemory, array &$runningQty, array &$runningValue): void
    {
        // Use copy_id-based key for per-copy tracking when available
        $copyId    = (int) ($row['copy_id'] ?? 0);
        $productId = (int) $row['product_id'];
        $key       = $copyId > 0 ? $productId . '_' . $copyId : (string) $productId;

        $batchMemory[$key]  ??= [];
        $runningQty[$key]   ??= 0.0;
        $runningValue[$key] ??= 0.0;

        $typeName = strtolower($row['transaction_type'] ?? '');

        if ($typeName === 'receipt') {
            $unitCost              = (float) ($row['transaction_unit_cost'] ?? 0);
            $qty                   = (float) ($row['transaction_qty'] ?? 0);
            $batchMemory[$key][] = ['qty' => $qty, 'cost' => $unitCost];
            $runningQty[$key]   += $qty;
            $runningValue[$key] += $qty * $unitCost;
        }

        if (in_array($typeName, ['issue', 'adjust_out', 'borrow'], true)) {
            $qty        = (float) ($row['transaction_qty'] ?? 0);
            $issuedCost = $this->fifoIssue($batchMemory[$key], $qty);
            $runningQty[$key]   -= $qty;
            $runningValue[$key] -= $issuedCost;
        }

        // Return: adds stock back
        if ($typeName === 'return') {
            $unitCost = (float) ($row['transaction_unit_cost'] ?? 0);
            $qty      = (float) ($row['transaction_qty'] ?? 0);
            // Return adds stock + value back (shown under purchase in reports)
            $batchMemory[$key][] = ['qty' => $qty, 'cost' => $unitCost];
            $runningQty[$key]   += $qty;
            $runningValue[$key] += $qty * $unitCost;
        }
    }

    private function purchaseValues(array $row, array &$batches, float &$runningQty, float &$runningValue): array
    {
        $typeName = strtolower($row['transaction_type'] ?? '');
        if (! in_array($typeName, ['receipt', 'return'], true)) {
            return [0, 0.0, 0.0];
        }

        $purchaseQty   = (float) ($row['transaction_qty'] ?? 0);
        $purchaseCost  = (float) ($row['transaction_unit_cost'] ?? 0);
        $purchaseTotal = $purchaseQty * $purchaseCost;

        $batches[]     = ['qty' => $purchaseQty, 'cost' => $purchaseCost];
        $runningQty   += $purchaseQty;
        $runningValue += $purchaseTotal;

        return [$purchaseQty, $purchaseCost, $purchaseTotal];
    }

    /**
     * Calculate issue (used) or spoiled values for a row.
     * $spoiledTypeId 3 => counts only adjust_out as "spoiled"
     * $spoiledTypeId 0 => counts issue + borrow as "used"
     * Return is NOT counted here — it's under purchase.
     */
    private function issueValues(
        array $row,
        int $spoiledTypeId,
        array &$batches,
        float &$runningQty,
        float &$runningValue
    ): array {
        $typeName = strtolower($row['transaction_type'] ?? '');
        $issueQty = (float) ($row['transaction_qty'] ?? 0);

        if ($issueQty <= 0) {
            return [0, 0.0, 0.0];
        }

        // $spoiledTypeId 3 => adjust_out only; 0 => used (issue + borrow)
        if ($spoiledTypeId === 3) {
            if ($typeName !== 'adjust_out') {
                return [0, 0.0, 0.0];
            }
        } else {
            // Used column: only issue and borrow (return is under purchase)
            if (!in_array($typeName, ['issue', 'borrow'], true)) {
                return [0, 0.0, 0.0];
            }
        }

        $storedUnitCost = (float) ($row['transaction_unit_cost'] ?? 0);

        if ($storedUnitCost > 0) {
            // Manually entered cost — use it directly and drain FIFO silently
            $issueTotal = $storedUnitCost * $issueQty;
            $this->fifoIssue($batches, $issueQty); // drain FIFO so running balance stays consistent
        } else {
            // No manual override — fall back to FIFO
            $issueTotal = $this->fifoIssue($batches, $issueQty);
        }

        $issueCost = $issueQty > 0 ? $issueTotal / $issueQty : 0.0;

        $runningQty   -= $issueQty;
        $runningValue -= $issueTotal;

        return [$issueQty, $issueCost, $issueTotal];
    }

    private function fifoIssue(array &$batches, float $qty): float
    {
        $totalCost = 0.0;

        foreach ($batches as &$batch) {
            if ($qty <= 0) {
                break;
            }
            if ($batch['qty'] <= 0) {
                continue;
            }
            $take       = min($batch['qty'], $qty);
            $totalCost += $take * $batch['cost'];
            $batch['qty'] -= $take;
            $qty -= $take;
        }

        return $totalCost;
    }
    /**
     * Build stockcard ledger rows for an arbitrary date range and optional product filter.
     * Used by the export controller.
     *
     * @param  string $dateFrom     'Y-m-d' — first day of range (inclusive)
     * @param  string $dateTo       'Y-m-d' — first day AFTER range end (exclusive)
     * @param  int    $productId    0 = all products
     * @param  int    $userOfficeId 0 = all offices
     */
    public function stockcardForExport(
        string $dateFrom,
        string $dateTo,
        int $productId = 0,
        int $userOfficeId = 0
    ): array {
        $openingRows = $this->historyRowsBeforeFiltered($dateFrom, $userOfficeId, $productId);
        $periodRows  = $this->historyRowsRange($dateFrom, $dateTo, $userOfficeId, $productId);

        $batchMemory  = [];
        $runningQty   = [];
        $runningValue = [];

        foreach ($openingRows as $row) {
            $this->applyRowToRunningBalance($row, $batchMemory, $runningQty, $runningValue);
        }

        $ledgerRows = [];
        $counter    = 1;

        foreach ($periodRows as $row) {
            $pid    = (int) $row['product_id'];
            $copyId = (int) ($row['copy_id'] ?? 0);
            $key    = $copyId > 0 ? $pid . '_' . $copyId : (string) $pid;

            $batchMemory[$key]  ??= [];
            $runningQty[$key]   ??= 0.0;
            $runningValue[$key] ??= 0.0;

            $beginQty  = $runningQty[$key];
            $beginCost = $beginQty > 0 ? $runningValue[$key] / $beginQty : 0.0;

            [$purchaseQty, $purchaseCost, $purchaseTotal] = $this->purchaseValues($row, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);
            [$usedQty, $usedCost, $usedTotal]             = $this->issueValues($row, 0, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);
            [$spoiledQty, $spoiledCost, $spoiledTotal]    = $this->issueValues($row, 3, $batchMemory[$key], $runningQty[$key], $runningValue[$key]);

            $endingQty  = $runningQty[$key];
            $endingCost = $endingQty > 0 ? $runningValue[$key] / $endingQty : 0.0;

            $ledgerRows[] = [
                'counter'        => $counter++,
                'product_id'     => $pid,
                'copy_id'        => $copyId,
                'copy_unit_cost' => (float) ($row['copy_unit_cost'] ?? 0),
                'copy_label'     => $row['copy_label'] ?? '',
                'product_type'   => $row['product_type'] ?: 'Uncategorized',
                'stock_no'       => $row['stock_no'],
                'item'           => $row['product'],
                'unit_name'      => $row['unit_name'],
                'begin_qty'      => $beginQty,
                'begin_cost'     => $beginCost,
                'purchase_qty'   => $purchaseQty,
                'purchase_cost'  => $purchaseCost,
                'purchase_total' => $purchaseTotal,
                'used_qty'       => $usedQty,
                'used_cost'      => $usedCost,
                'used_total'     => $usedTotal,
                'spoiled_qty'    => $spoiledQty,
                'spoiled_cost'   => $spoiledCost,
                'spoiled_total'  => $spoiledTotal,
                'ending_qty'     => $endingQty,
                'ending_cost'    => $endingCost,
            ];
        }

        $groupedRows = [];
        foreach ($ledgerRows as $row) {
            $groupedRows[$row['product_type']][] = $row;
        }

        return [
            'rows'        => $ledgerRows,
            'groupedRows' => $groupedRows,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];
    }

    private function historyRowsBeforeFiltered(string $before, int $userOfficeId = 0, int $productId = 0): array
    {
        $officeFilter  = $userOfficeId > 0 ? ' AND t.user_office_id = ' . (int) $userOfficeId : '';
        $productFilter = $productId > 0    ? ' AND b.product_id = ' . (int) $productId         : '';

        return $this->db->query(
            "SELECT b.product_id, t.transaction_qty, t.transaction_unit_cost,
                    t.transaction_type_id, tt.transaction_type,
                    COALESCE(t.copy_id, b.copy_id) AS copy_id
             FROM transaction_table t
             INNER JOIN batch_table b ON t.batch_id = b.batch_id
             INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
             WHERE t.transaction_date < ?{$officeFilter}{$productFilter}
             ORDER BY b.product_id ASC, t.transaction_date ASC, t.transaction_id ASC",
            [$before]
        )->getResultArray();
    }

    private function historyRowsRange(
        string $dateFrom,
        string $dateTo,
        int $userOfficeId = 0,
        int $productId = 0
    ): array {
        $builder = $this->db->table('transaction_table t');
        $builder->select([
            'b.product_id',
            'p.product',
            'p.stock_no',
            'COALESCE(pt.type, "Uncategorized") AS product_type',
            'COALESCE(ut.unit, "Deleted Unit") AS unit_name',
            't.transaction_qty',
            't.transaction_unit_cost',
            't.transaction_type_id',
            'tt.transaction_type',
            'COALESCE(t.copy_id, b.copy_id) AS copy_id',
            'COALESCE(pc.unit_cost, 0) AS copy_unit_cost',
            'COALESCE(pc.label, "") AS copy_label',
        ]);
        $builder->join('batch_table b', 't.batch_id = b.batch_id');
        $builder->join('product_table p', 'b.product_id = p.product_id');
        $builder->join('transaction_type_table tt', 't.transaction_type_id = tt.transaction_type_id');
        $builder->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left');
        $builder->join('type_of_product pt', 'p.type_id = pt.type_id', 'left');
        $builder->join('product_copy_table pc', 'COALESCE(t.copy_id, b.copy_id) = pc.copy_id', 'left');
        $builder->where('t.transaction_date >=', $dateFrom);
        $builder->where('t.transaction_date <', $dateTo);
        $builder->whereIn('tt.transaction_type', ['receipt', 'issue', 'adjust_out', 'borrow', 'return']);

        if ($userOfficeId > 0) {
            $builder->where('t.user_office_id', $userOfficeId);
        }
        if ($productId > 0) {
            $builder->where('b.product_id', $productId);
        }

        return $builder
            ->orderBy('pt.type', 'ASC')
            ->orderBy('b.product_id', 'ASC')
            ->orderBy('t.transaction_date', 'ASC')
            ->orderBy('t.transaction_id', 'ASC')
            ->get()
            ->getResultArray();
    }
}



