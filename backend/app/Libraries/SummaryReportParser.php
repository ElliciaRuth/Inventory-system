<?php

namespace App\Libraries;

/**
 * Turns a workbook of Summary Report sheets into an import plan.
 *
 * Expected format (Benguet State University Bakery Project style):
 *
 *   Row 1–3  : Institution header (Republic of the Philippines, etc.)
 *   Row 5    : Office name
 *   Row 7    : Project name
 *   Row 8    : "Inventory for the month of SEPTEMBER 2026"
 *   Row 9    : Type title (spaced letters, e.g. "O F F I C E  S U P P L I E S")
 *   Row 10   : Group headers — NO. | CODE | ITEMS/PRODUCTS | BEGINNING INVENTORY | PRODUCED/PURCHASES | USED/SOLD | BREAKAGE | ENDING INVENTORY
 *   Row 11   : Sub-headers  — QTY | Unit | Unit Cost | Amount | QTY | Unit Cost | Amount | … (same pattern × 4 more groups)
 *   Row 12+  : Data rows
 *   Last row : "T O T A L" summary row (skipped)
 *
 * Column indices (0-based, as returned by XlsxReader):
 *   1=No  2=Code  3=Item  4=Begin Qty  5=Begin Unit  6=Begin Cost  7=Begin Amt
 *   8=Purch Qty  9=Purch Cost  10=Purch Amt
 *   11=Used Qty  12=Used Cost  13=Used Amt
 *   14=Break Qty  15=Break Cost  16=Break Amt
 *   17=End Qty  18=End Cost  19=End Amt
 */
class SummaryReportParser
{
    private const MONTHS = [
        'january' => '01', 'february' => '02', 'march' => '03',    'april'    => '04',
        'may'     => '05', 'june'     => '06', 'july'  => '07',    'august'   => '08',
        'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12',
    ];

    /**
     * @param list<array{name: string, cells: array}> $sheets  from XlsxReader::sheets()
     * @param string $monthHint  YYYY-MM fallback when no date is found in the file
     */
    public function parse(array $sheets, string $monthHint = ''): array
    {
        $notes    = [];
        $products = [];

        foreach ($sheets as $sheet) {
            $result = $this->parseSheet($sheet, $monthHint, $notes);
            foreach ($result as $row) {
                $products[] = $row;
            }
        }

        $notes = array_values(array_unique($notes));

        $detectedMonth = $monthHint;
        foreach ($products as $p) {
            if (($p['month'] ?? '') !== '') {
                $detectedMonth = $p['month'];
                break;
            }
        }

        return [
            'month'    => $detectedMonth,
            'notes'    => $notes,
            'products' => $products,
            'summary'  => $this->summary($products),
        ];
    }

    // ── Sheet parsing ─────────────────────────────────────────────────────────

    private function parseSheet(array $sheet, string $monthHint, array &$notes): array
    {
        $cells = $sheet['cells'] ?? [];
        if (empty($cells)) {
            return [];
        }

        // ── 1. Find the group-header row (contains "BEGINNING" somewhere) ────
        $groupHeaderRow = null;
        foreach ($cells as $rowIdx => $rowCells) {
            foreach ($rowCells as $cell) {
                $val = strtoupper(trim((string) ($cell['v'] ?? '')));
                if (str_contains($val, 'BEGINNING')) {
                    $groupHeaderRow = (int) $rowIdx;
                    break 2;
                }
            }
        }

        if ($groupHeaderRow === null) {
            // No column headers found — skip silently (e.g. cover/summary sheet)
            return [];
        }

        // ── 2. Extract the product type from the row just before the headers ─
        // It is written as spaced-out uppercase letters: "O F F I C E  S U P P L I E S"
        $typeName   = '';
        $monthFound = $monthHint;

        foreach ($cells as $rowIdx => $rowCells) {
            if ((int) $rowIdx >= $groupHeaderRow) {
                break;
            }
            foreach ($rowCells as $cell) {
                $raw = trim((string) ($cell['v'] ?? ''));
                if ($raw === '') {
                    continue;
                }

                // Month line: "Inventory for the month of SEPTEMBER 2026"
                $detected = $this->extractMonth($raw);
                if ($detected !== '') {
                    $monthFound = $detected;
                }

                // Type title: a string that looks spaced-out (e.g. "O F F I C E  S U P P L I E S")
                // Heuristic: the row just before the group-header row, ignoring institution lines
                $compressed = $this->compressSpacedTitle($raw);
                if (
                    $compressed !== ''
                    && (int) $rowIdx === $groupHeaderRow - 1
                    && ! str_contains(strtolower($raw), 'inventory for the month')
                    && ! str_contains(strtolower($raw), 'republic')
                    && ! str_contains(strtolower($raw), 'benguet')
                    && ! str_contains(strtolower($raw), 'university')
                    && ! str_contains(strtolower($raw), 'business')
                    && ! str_contains(strtolower($raw), 'bakery project')
                    && ! str_contains(strtolower($raw), 'project')
                ) {
                    $typeName = $compressed;
                }
            }
        }

        // Fallback: use the sheet name, stripping trailing " Inventory"
        if ($typeName === '') {
            $typeName = trim((string) preg_replace('/\s+inventory\s*$/i', '', trim($sheet['name'])));
            if ($typeName === '') {
                $typeName = $sheet['name'];
            }
        }

        // ── 3. Map column indices from the group-header row ──────────────────
        $cols = $this->detectColumns($cells, $groupHeaderRow);
        if ($cols === null) {
            $notes[] = "Sheet \"{$sheet['name']}\": cannot map required column groups. Sheet skipped.";
            return [];
        }

        // ── 4. Find the sub-header row (first row after group-header with "QTY" or "Unit") ─
        $subHeaderRow = null;
        foreach ($cells as $rowIdx => $rowCells) {
            if ((int) $rowIdx <= $groupHeaderRow) {
                continue;
            }
            foreach ($rowCells as $cell) {
                $val = strtolower(trim((string) ($cell['v'] ?? '')));
                if ($val === 'qty' || $val === 'quantity' || $val === 'unit') {
                    $subHeaderRow = (int) $rowIdx;
                    break 2;
                }
            }
        }

        $dataStart = ($subHeaderRow ?? $groupHeaderRow) + 1;

        // ── 5. Read data rows ─────────────────────────────────────────────────
        $products = [];
        foreach ($cells as $rowIdx => $rowCells) {
            if ((int) $rowIdx < $dataStart) {
                continue;
            }

            // Get the item name from column 3
            $item = trim((string) ($rowCells[$cols['item']]['v'] ?? ''));
            if ($item === '') {
                continue;
            }

            // Skip TOTAL rows (written as "T O T A L") and repeated header rows
            $normalizedItem = strtoupper($this->collapseSpaces($item));
            if (
                $normalizedItem === 'TOTAL'
                || $normalizedItem === 'NO.'
                || $normalizedItem === 'ITEMS/PRODUCTS'
                || $normalizedItem === 'ITEMS'
                || $normalizedItem === 'PRODUCTS'
                || str_starts_with($normalizedItem, 'PREPARED BY')
                || str_starts_with($normalizedItem, 'VERIFIED BY')
                || str_starts_with($normalizedItem, 'NOTED BY')
            ) {
                continue;
            }

            // Also skip rows where the first cell looks like a signature line
            if (isset($rowCells[$cols['item'] - 1])) {
                $firstCell = strtolower(trim((string) ($rowCells[$cols['item'] - 1]['v'] ?? '')));
                if (str_contains($firstCell, 'prepared') || str_contains($firstCell, 'verified') || str_contains($firstCell, 'noted')) {
                    continue;
                }
            }

            $stockNo    = isset($cols['code']) ? trim((string) ($rowCells[$cols['code']]['v'] ?? '')) : '';
            $beginQty   = $this->cellNum($rowCells, $cols['begin_qty']);
            $unit       = isset($cols['begin_unit']) ? trim((string) ($rowCells[$cols['begin_unit']]['v'] ?? '')) : '';
            $beginCost  = $this->cellNum($rowCells, $cols['begin_cost']);
            $purchQty   = $this->cellNum($rowCells, $cols['purch_qty']);
            $purchCost  = $this->cellNum($rowCells, $cols['purch_cost']);
            $purchTotal = $this->cellNum($rowCells, $cols['purch_total']);
            $usedQty    = $this->cellNum($rowCells, $cols['used_qty']);
            $usedCost   = $this->cellNum($rowCells, $cols['used_cost']);
            $usedTotal  = $this->cellNum($rowCells, $cols['used_total']);
            $spoilQty   = $this->cellNum($rowCells, $cols['spoil_qty']);
            $spoilCost  = $this->cellNum($rowCells, $cols['spoil_cost']);
            $spoilTotal = $this->cellNum($rowCells, $cols['spoil_total']);
            $endQty     = $this->cellNum($rowCells, $cols['end_qty']);
            $endCost    = $this->cellNum($rowCells, $cols['end_cost']);

            // Derive totals when only qty × cost are filled
            if ($purchTotal <= 0 && $purchQty > 0 && $purchCost > 0) {
                $purchTotal = round($purchQty * $purchCost, 4);
            }
            if ($usedTotal <= 0 && $usedQty > 0 && $usedCost > 0) {
                $usedTotal = round($usedQty * $usedCost, 4);
            }
            if ($spoilTotal <= 0 && $spoilQty > 0 && $spoilCost > 0) {
                $spoilTotal = round($spoilQty * $spoilCost, 4);
            }

            // Skip rows with no quantity at all
            if ($beginQty == 0 && $purchQty == 0 && $usedQty == 0 && $spoilQty == 0 && $endQty == 0) {
                continue;
            }

            $warnings = [];
            $status   = 'ok';

            if ($unit === '') {
                $unit       = 'pcs';
                $warnings[] = 'Unit was blank; defaulted to "pcs".';
            }

            if (mb_strlen($item) > 255) {
                $item       = mb_substr($item, 0, 255);
                $warnings[] = 'Product name truncated to 255 characters.';
            }

            $products[] = [
                'key'          => $sheet['name'] . '|' . $rowIdx,
                'sheet'        => $sheet['name'],
                'row'          => (int) $rowIdx,
                'type'         => $typeName,
                'month'        => $monthFound,
                'stock_no'     => $stockNo,
                'item'         => $item,
                'unit'         => $unit,
                'begin_qty'    => round($beginQty, 4),
                'begin_cost'   => round($beginCost, 4),
                'purch_qty'    => round($purchQty, 4),
                'purch_cost'   => round($purchCost, 4),
                'purch_total'  => round($purchTotal, 4),
                'used_qty'     => round($usedQty, 4),
                'used_cost'    => round($usedCost, 4),
                'used_total'   => round($usedTotal, 4),
                'spoil_qty'    => round($spoilQty, 4),
                'spoil_cost'   => round($spoilCost, 4),
                'spoil_total'  => round($spoilTotal, 4),
                'end_qty'      => round($endQty, 4),
                'end_cost'     => round($endCost, 4),
                'status'       => $status,
                'warnings'     => $warnings,
            ];
        }

        return $products;
    }

    // ── Column layout detection ───────────────────────────────────────────────

    /**
     * Scan the group-header row for known keywords and derive column indices.
     * Handles the fixed BSU Bakery Project layout:
     *   C1=No  C2=Code  C3=Item  C4=BEGINNING(×4)  C8=PURCHASES(×3)  C11=USED(×3)  C14=BREAKAGE(×3)  C17=ENDING(×3)
     */
    private function detectColumns(array $cells, int $headerRow): ?array
    {
        $rowCells = $cells[$headerRow] ?? [];
        ksort($rowCells);

        $groups = []; // keyword => first colIndex
        foreach ($rowCells as $colIdx => $cell) {
            $val = strtoupper(trim((string) ($cell['v'] ?? '')));
            if ($val === '' || $val === '-' || is_numeric($val)) {
                continue;
            }
            // Normalize: collapse internal spaces so "BEGINNING INVENTORY" works as expected
            $val = $this->collapseSpaces($val);

            if (str_contains($val, 'BEGINNING')) {
                $groups['begin'] ??= (int) $colIdx;
            } elseif (str_contains($val, 'PURCHASE') || str_contains($val, 'PRODUCED')) {
                $groups['purch'] ??= (int) $colIdx;
            } elseif (str_contains($val, 'USED') || str_contains($val, 'SOLD')) {
                $groups['used'] ??= (int) $colIdx;
            } elseif (str_contains($val, 'BREAKAGE') || str_contains($val, 'SPOIL')) {
                $groups['spoil'] ??= (int) $colIdx;
            } elseif (str_contains($val, 'ENDING') || $val === 'END INVENTORY') {
                $groups['end'] ??= (int) $colIdx;
            } elseif (str_contains($val, 'ITEM') || str_contains($val, 'PRODUCT')) {
                $groups['item_col'] ??= (int) $colIdx;
            } elseif ($val === 'CODE' || str_contains($val, 'STOCK NO')) {
                $groups['code_col'] ??= (int) $colIdx;
            }
        }

        if (! isset($groups['begin'], $groups['purch'], $groups['used'], $groups['spoil'], $groups['end'])) {
            return null;
        }

        $b = $groups['begin'];
        $p = $groups['purch'];

        // BEGINNING has 4 sub-cols (Qty, Unit, Unit Cost, Amount) when gap ≥ 4
        $hasUnit = ($p - $b) >= 4;

        // Item column: default to the col just left of BEGINNING (col 3 in this format)
        $itemCol = $groups['item_col'] ?? max(0, $b - 1);

        $cols = [
            'item'        => $itemCol,
            'begin_qty'   => $b,
            'begin_cost'  => $hasUnit ? $b + 2 : $b + 1,
            'purch_qty'   => $p,
            'purch_cost'  => $p + 1,
            'purch_total' => $p + 2,
        ];

        if ($hasUnit) {
            $cols['begin_unit'] = $b + 1;
        }
        if (isset($groups['code_col'])) {
            $cols['code'] = $groups['code_col'];
        }

        $u = $groups['used'];
        $s = $groups['spoil'];
        $e = $groups['end'];

        $cols['used_qty']    = $u;
        $cols['used_cost']   = $u + 1;
        $cols['used_total']  = $u + 2;
        $cols['spoil_qty']   = $s;
        $cols['spoil_cost']  = $s + 1;
        $cols['spoil_total'] = $s + 2;
        $cols['end_qty']     = $e;
        $cols['end_cost']    = $e + 1;

        return $cols;
    }

    // ── String helpers ────────────────────────────────────────────────────────

    private function cellNum(array $rowCells, int $colIdx): float
    {
        $v = $rowCells[$colIdx]['v'] ?? null;
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
            if ($v === '-' || $v === '') {
                return 0.0;
            }
        }
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /**
     * Collapse all internal whitespace to a single space.
     * Turns "O F F I C E  S U P P L I E S" → "O F F I C E S U P P L I E S" first
     * then a second pass turns single-character words back into a word.
     */
    private function collapseSpaces(string $str): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $str));
    }

    /**
     * Convert a spaced-out title like "O F F I C E  S U P P L I E S" to "Office Supplies".
     *
     * The format used in the Excel file is:
     *   - Each letter is separated from adjacent letters by a single space.
     *   - Words within the title are separated from each other by TWO or more spaces.
     *
     * Examples:
     *   "O F F I C E  S U P P L I E S"          → "Office Supplies"
     *   "R A W   M A T E R I A L S   I N V …"   → "Raw Materials" (after stripping Inventory)
     *   "W O R K - I N - P R O C E S S"          → "Work-In-Process"
     *   "J A N I T O R I A L  S U P P L I E S"  → "Janitorial Supplies"
     */
    private function compressSpacedTitle(string $str): string
    {
        $str = trim($str);
        if ($str === '') {
            return '';
        }

        // Detect whether this looks like a spaced-out title at all.
        // Heuristic: more than half of all space-delimited tokens are single characters.
        $tokens = preg_split('/\s+/', $str);
        if ($tokens === false || count($tokens) < 3) {
            // Too short to be spaced-out; just title-case and return
            return mb_convert_case($str, MB_CASE_TITLE, 'UTF-8');
        }
        $singleCount = count(array_filter($tokens, static fn ($t) => mb_strlen($t) === 1));
        if ($singleCount / count($tokens) < 0.5) {
            // Not spaced-out; return as-is, title-cased
            return mb_convert_case($str, MB_CASE_TITLE, 'UTF-8');
        }

        // Split on 2+ consecutive spaces → each segment = one "word" in spaced-out form
        // e.g. "O F F I C E  S U P P L I E S" → ["O F F I C E", "S U P P L I E S"]
        $wordGroups = preg_split('/\s{2,}/', $str);
        if ($wordGroups === false || $wordGroups === []) {
            return $str;
        }

        $words = [];
        foreach ($wordGroups as $group) {
            $group = trim($group);
            if ($group === '') {
                continue;
            }
            // Join single-letter tokens within this group (removes the intra-word spaces)
            $letters = preg_split('/\s+/', $group);
            $word    = implode('', $letters ?? []);
            if ($word !== '') {
                $words[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            }
        }

        $result = implode(' ', $words);

        // Strip a trailing "Inventory" word (e.g. "Raw Materials Inventory" → "Raw Materials")
        $result = trim((string) preg_replace('/\s+inventory\s*$/i', '', $result));

        return $result !== '' ? $result : $str;
    }

    /**
     * Extract "YYYY-MM" from a string like "Inventory for the month of SEPTEMBER 2026".
     */
    private function extractMonth(string $str): string
    {
        // Already in YYYY-MM form
        if (preg_match('/\b(20\d{2})-(0[1-9]|1[0-2])\b/', $str, $m)) {
            return $m[1] . '-' . $m[2];
        }
        $lower = strtolower(trim($str));
        foreach (self::MONTHS as $name => $num) {
            if (str_contains($lower, $name) && preg_match('/\b(20\d{2})\b/', $str, $m)) {
                return $m[1] . '-' . $num;
            }
        }
        return '';
    }

    private function summary(array $products): array
    {
        $ok      = array_values(array_filter($products, static fn ($p) => $p['status'] === 'ok'));
        $skipped = array_values(array_filter($products, static fn ($p) => $p['status'] !== 'ok'));
        $months  = array_values(array_unique(array_column($ok, 'month')));
        $types   = array_values(array_unique(array_column($ok, 'type')));

        return [
            'ok_count'      => count($ok),
            'skipped_count' => count($skipped),
            'months'        => $months,
            'types'         => $types,
        ];
    }
}
