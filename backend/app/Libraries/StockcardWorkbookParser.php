<?php

namespace App\Libraries;

/**
 * Turns a workbook of Appendix 58 stock cards into an import plan.
 *
 * One sheet may hold several cards side by side; each card has a header (Entity
 * Name, Item, Stock No., Description, Unit of Measurement, Re-order Point) and
 * rows of Date | Reference | Receipt Qty | Issue Qty | Office | Balance Qty.
 *
 * Cards for the same item (same office, name, unit, size and product type) are
 * merged into one product, ordered by date. A continuation card's carry-forward
 * row ("FPC RM 19A", "Balance forwarded") is not imported again. Item names are
 * spell-checked against the other names in the file and the existing products,
 * units are normalised (pieces → pcs, kls → kilo) and each product's type is
 * detected. Nothing here touches the database.
 */
class StockcardWorkbookParser
{
    public const TYPES = [
        'Raw Materials', 'Product Label', 'Other Supplies', 'Finished Product',
        'Perishable Raw Materials', 'Non-Perishable Raw Materials', 'Packaging', 'Operational Supplies',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** Every spelling of a unit → the one name the system uses (also used by UnitModel and the unit clean-up migration) */
    public const UNIT_SYNONYMS = [
        'pc' => 'pcs', 'pcs' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs', 'unit' => 'pcs', 'units' => 'pcs',
        'kl' => 'kilo', 'kls' => 'kilo', 'kilo' => 'kilo', 'kilos' => 'kilo', 'kg' => 'kilo', 'kgs' => 'kilo',
        'kilogram' => 'kilo', 'kilograms' => 'kilo',
        'g' => 'gram', 'gm' => 'gram', 'gms' => 'gram', 'gram' => 'gram', 'grams' => 'gram',
        'l' => 'liter', 'liter' => 'liter', 'liters' => 'liter', 'litre' => 'liter', 'litres' => 'liter',
        'ml' => 'mL',
        'pack' => 'pack', 'packs' => 'pack', 'pk' => 'pack', 'pkg' => 'pack', 'packet' => 'pack', 'packets' => 'pack',
        'case' => 'case', 'cases' => 'case', 'cs' => 'case',
        'sack' => 'sack', 'sacks' => 'sack', 'bag' => 'bag', 'bags' => 'bag',
        'bottle' => 'bottle', 'bottles' => 'bottle', 'btl' => 'bottle', 'can' => 'can', 'cans' => 'can',
        'jar' => 'jar', 'jars' => 'jar', 'box' => 'box', 'boxes' => 'box', 'roll' => 'roll', 'rolls' => 'roll',
        'ream' => 'ream', 'reams' => 'ream', 'drum' => 'drum', 'drums' => 'drum', 'gallon' => 'gallon',
        'gallons' => 'gallon', 'gal' => 'gallon', 'tray' => 'tray', 'trays' => 'tray', 'pouch' => 'pouch',
        'pouches' => 'pouch', 'dozen' => 'dozen', 'dozens' => 'dozen', 'doz' => 'dozen', 'set' => 'set',
        'sets' => 'set', 'tub' => 'tub', 'tubs' => 'tub', 'block' => 'block', 'blocks' => 'block',
    ];

    /** Units that measure an amount rather than name a container; "JARS OF 300g" is counted in jars */
    private const MEASURE_UNITS = ['kilo', 'gram', 'liter', 'mL'];

    /** @var array<int, string> user_office_id => office name */
    private array $offices;

    private int $currentYear;

    /** @var array<string, array> sheet name => cells of the workbook being parsed */
    private array $sheetCells = [];

    /** @var list<string> notes about the whole file */
    private array $notes = [];

    /**
     * @param array<int, string> $offices    user offices the cards can belong to
     * @param list<string>       $knownNames existing product names, used as the spelling reference
     */
    public function __construct(array $offices, private readonly int $fallbackOfficeId = 0, private readonly array $knownNames = [])
    {
        $this->offices     = $offices;
        $this->currentYear = (int) date('Y');
    }

    /**
     * @param list<array{name: string, cells: array}> $sheets from XlsxReader::sheets()
     * @param string $fileName helps detect the product type ("Raw Materials.xlsx")
     */
    public function parse(array $sheets, string $fileName = ''): array
    {
        $this->notes = [];
        $this->sheetCells = [];
        $cards            = [];
        foreach ($sheets as $sheet) {
            $this->sheetCells[$sheet['name']] = $sheet['cells'];
            foreach ($this->findCards($sheet) as $card) {
                $cards[] = $card;
            }
        }

        // Every stock no. in the file, so carry-forward rows that name another card can be recognised
        $stockNos = [];
        foreach ($cards as $card) {
            if ($card['stock_no'] !== '') {
                $stockNos[$this->norm($card['stock_no'])] = true;
            }
        }

        foreach ($cards as &$card) {
            $this->readRows($card, $stockNos);
        }
        unset($card);

        $defaultYear = $this->resolveAllDates($cards);

        foreach ($cards as &$card) {
            $this->finishCard($card, $fileName);
        }
        unset($card);

        $this->fixSpelling($cards);
        $this->noteMissingUnits($cards);

        $products = $this->buildProducts($cards);

        return [
            'default_year' => $defaultYear,
            'types'        => self::TYPES,
            'notes'        => $this->notes,
            'cards'        => array_map(fn (array $c) => $this->publicCard($c), $cards),
            'products'     => $products,
            'summary'      => $this->summary($cards, $products),
        ];
    }

    // ── Locating cards and their headers ─────────────────────────────────────

    private function findCards(array $sheet): array
    {
        $cells  = $sheet['cells'];
        $starts = [];

        foreach ($cells as $r => $row) {
            foreach ($row as $c => $cell) {
                if (is_string($cell['v']) && preg_match('/^\s*stock\s*card\s*$/i', $cell['v'])) {
                    $starts[] = ['row' => $r, 'col' => $c];
                }
            }
        }

        usort($starts, static fn ($a, $b) => [$a['col'], $a['row']] <=> [$b['col'], $b['row']]);

        // Cards may sit side by side (A, I, Q…) or be stacked down one column (rows 5, 48, 88…)
        $cards = [];
        foreach ($starts as $start) {
            $lastCol = $start['col'] + 7;
            $lastRow = PHP_INT_MAX; // the bottom card runs to the end, so rows added in the preview still belong to it
            foreach ($starts as $other) {
                if ($other['col'] > $start['col']) {
                    $lastCol = min($lastCol, $other['col'] - 1);
                } elseif ($other['col'] === $start['col'] && $other['row'] > $start['row']) {
                    $lastRow = min($lastRow, $other['row'] - 1);
                }
            }

            $label = '';
            if (count($starts) > 1) {
                $stacked = count(array_filter($starts, static fn ($s) => $s['col'] === $start['col'])) > 1;
                $label   = ' (' . self::columnLetter($start['col']) . ($stacked ? $start['row'] : '') . ')';
            }

            $cards[] = $this->readHeader($sheet['name'] . $label, $sheet['name'], $cells, $start['row'], $lastRow, $start['col'], $lastCol);
        }

        return $cards;
    }

    private function readHeader(string $label, string $sheetName, array $cells, int $startRow, int $lastRow, int $firstCol, int $lastCol): array
    {
        $card = [
            'label'      => $label,
            'sheet'      => $sheetName,
            'first_col'  => $firstCol,
            'last_col'   => $lastCol,
            'last_row'   => $lastRow,
            'item'       => '',
            'stock_no'   => '',
            'description' => '',
            'unit_text'  => '',
            'entity'     => '',
            'reorder'    => 0,
            'columns'    => [],
            'fields'     => [],
            'header_row' => 0,
            'rows'       => [],
            'status'     => 'ok',
            'reason'     => '',
            'warnings'   => [],
            'seed_year'  => null,
        ];

        $maxRow = $cells ? max(array_keys($cells)) : 0;
        for ($r = $startRow; $r <= min($lastRow, $maxRow); $r++) {
            $row = $cells[$r] ?? [];

            // Column header row: "Date | Reference | Receipt | Issue | (Office) | Balance"
            $first = $this->text($row[$firstCol] ?? null);
            if (strcasecmp($first, 'date') === 0) {
                $card['header_row'] = $r;
                $card['columns']    = $this->headerColumns($cells, $r, $firstCol, $lastCol);
                break;
            }

            for ($c = $firstCol; $c <= $lastCol; $c++) {
                $text = $this->text($row[$c] ?? null);
                if ($text === '' || ! preg_match('/^(entity\s*name|item|stock\s*no\.?|description|unit\s*of\s*measurement|re-?\s*order\s*point|fund\s*cluster)\s*:\s*(.*)$/is', $text, $m)) {
                    continue;
                }

                $label     = strtolower(preg_replace('/[^a-z]/i', '', $m[1]));
                $value     = trim($m[2], " \t\n\r_");
                $valueCols = [];
                if ($value === '') {
                    // Names may continue over several cells ("CHOCOFLAKES" | "100g"); a stock no. is one cell
                    $value = $this->nextValue($row, $c, $lastCol, in_array($label, ['item', 'description', 'unitofmeasurement'], true), $valueCols);
                }

                // Where the field sits, so a correction from the preview can be written back into the cells
                $card['fields'][$label] = ['row' => $r, 'col' => $c, 'label' => trim($m[1]), 'value_cols' => $valueCols];

                match ($label) {
                    'entityname'        => $card['entity'] = $value,
                    'item'              => $card['item'] = $value,
                    'stockno'           => $card['stock_no'] = $value,
                    'description'       => $card['description'] = $value,
                    'unitofmeasurement' => $card['unit_text'] = $value,
                    'reorderpoint'      => $card['reorder'] = (int) ($this->number($value) ?? 0),
                    default             => null,
                };
            }
        }

        if ($card['header_row'] === 0 || ! isset($card['columns']['receipt'], $card['columns']['issue'], $card['columns']['balance'])) {
            $this->skip($card, 'No "Date / Reference / Receipt / Issue / Balance" header found.');
        } elseif ($card['item'] === '') {
            $this->skip($card, 'The card has no item name.');
        }

        return $card;
    }

    /**
     * Header labels → column numbers; "Office" sits on the "Qty." line below the header.
     */
    private function headerColumns(array $cells, int $headerRow, int $firstCol, int $lastCol): array
    {
        $columns = ['date' => $firstCol];

        foreach ([$headerRow, $headerRow + 1] as $r) {
            for ($c = $firstCol; $c <= $lastCol; $c++) {
                $text = strtolower($this->text($cells[$r][$c] ?? null));
                foreach (['reference', 'receipt', 'issue', 'office', 'balance'] as $key) {
                    if (! isset($columns[$key]) && str_starts_with($text, $key)) {
                        $columns[$key] = $c;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Value to the right of an empty "Label :" cell, up to the next label ("Stock No. :" sits right of "Item :").
     */
    private function nextValue(array $row, int $col, int $lastCol, bool $join, array &$cols = []): string
    {
        $parts = [];
        for ($c = $col + 1; $c <= $lastCol; $c++) {
            $text = $this->text($row[$c] ?? null);
            if ($text === '') {
                continue;
            }
            if (preg_match('/^[a-z .\-]+:/i', $text)) {
                break;
            }
            $parts[] = $text;
            $cols[]  = $c;
            if (! $join) {
                break;
            }
        }

        return trim(implode(' ', $parts));
    }

    // ── Rows ─────────────────────────────────────────────────────────────────

    private function readRows(array &$card, array $stockNos): void
    {
        if ($card['status'] !== 'ok') {
            return;
        }

        $cols    = $card['columns'];
        $cells   = $this->sheetCells[$card['sheet']] ?? [];
        $pending = null; // date/reference from a line without quantities, used by the next movement
        $seeds   = ['explicit' => null, 'rpci' => null, 'ref' => null];
        $balanceSeen = false;
        $rows    = [];

        foreach ($cells as $r => $row) {
            if ($r > $card['last_row']) {
                break; // the next card stacked below starts here
            }
            if ($r <= $card['header_row'] || $this->isQtyRow($row, $cols)) {
                continue;
            }

            $dateCell = $row[$cols['date']] ?? null;
            $ref      = isset($cols['reference']) ? $this->text($row[$cols['reference']] ?? null) : '';
            $receipt  = $this->qty($row[$cols['receipt']] ?? null);
            $issue    = $this->qty($row[$cols['issue']] ?? null);
            $office   = isset($cols['office']) ? $this->text($row[$cols['office']] ?? null) : '';
            $balance  = $this->qty($row[$cols['balance']] ?? null);
            $dateText = $dateCell === null ? '' : (is_string($dateCell['v']) ? trim($dateCell['v']) : (string) $dateCell['v']);

            if ($balance !== null) {
                $balanceSeen = true;
            }

            $hasQty = ($receipt ?? 0) > 0 || ($issue ?? 0) > 0;
            if (! $hasQty && $dateText === '' && $ref === '' && ! ($rows === [] && $balance !== null)) {
                continue; // blank or balance-only filler line
            }

            // Stock can't go below zero: such lines are leftovers copied from another card
            if ($hasQty && $balance !== null && $balance < 0) {
                $card['warnings'][] = "Row {$r} ({$dateText} {$ref}) skipped: its Excel balance is negative ({$this->fmt($balance)}).";
                continue;
            }

            $token = $dateCell === null ? null : $this->dateToken($dateCell);
            $this->collectSeeds($seeds, $token, $ref, $dateText);

            $carryText = $this->carryForwardText([$ref, $dateText], $stockNos);
            $isCount   = $balance !== null && preg_match('/\bRPCI\b|physical\s*count|inventory\s*count/i', $ref . ' ' . $dateText);

            // Lines that state how much is in stock rather than what came in or went out. They become
            // "stock is now X": nothing if the entries already add up to X, else the difference is recorded.
            //  - a carry-forward from an earlier card ("FPC RM 19A")
            //  - a physical count ("RPCI 2025, End"), anywhere on the card, whichever qty column it was typed in
            //  - a first line with only a balance, or an issue equal to the balance with nothing in stock yet
            if ($rows === [] && $carryText !== null) {
                // The balance column holds the amount carried over; fall back to a receipt qty when it's empty
                $rows[] = $this->row($r, 'carry', $balance ?? $receipt ?? 0, $token, $dateText, $ref ?: $carryText, $office, $balance);
                continue;
            }
            if ($isCount) {
                if (($receipt ?? 0) == 0 && ($issue ?? 0) > 0 && abs($issue - $balance) < 0.001) {
                    $card['warnings'][] = "Row {$r}: the count {$this->fmt($balance)} was typed under Issue; used as the stock count.";
                }
                $rows[] = $this->row($r, 'count', $balance, $token, $dateText, $ref, $office, $balance);
                continue;
            }
            if ($rows === [] && ! $hasQty && ($balance ?? 0) > 0) {
                $rows[] = $this->row($r, 'opening', $balance, $token, $dateText, $ref, $office, $balance);
                continue;
            }
            if ($rows === [] && ($receipt ?? 0) == 0 && ($issue ?? 0) > 0 && abs($issue - ($balance ?? -1)) < 0.001) {
                $card['warnings'][] = "Row {$r}: the opening stock {$this->fmt($balance)} was typed under Issue; used as opening stock.";
                $rows[] = $this->row($r, 'opening', $balance, $token, $dateText, $ref, $office, $balance);
                continue;
            }

            if (! $hasQty) {
                // A dated line without quantities (e.g. "May 05, 2025 | PO …"): its date and reference
                // apply to the next movement that has none of its own
                if ($token !== null || $ref !== '') {
                    $pending = ['token' => $token, 'raw' => $dateText, 'ref' => $ref, 'row' => $r];
                }
                continue;
            }

            if ($token === null && $dateText === '' && $pending) {
                $token    = $pending['token'];
                $dateText = $pending['raw'];
            }
            if ($ref === '' && $pending) {
                $ref = $pending['ref'];
            }
            $pending = null;

            if ($dateText !== '' && $token === null && $this->looksLikeReference($dateText)) {
                $card['warnings'][] = "Row {$r}: \"{$dateText}\" in the Date column looks like a reference; used it as the reference.";
                $ref      = $ref === '' ? $dateText : $ref;
                $dateText = ''; // the row takes the previous row's date
            }

            if ($dateText !== '' && $token === null) {
                $card['warnings'][] = "Row {$r}: date \"{$dateText}\" is not a date.";
                $this->skip($card, "Row {$r}: date \"{$dateText}\" is not a date, so this doesn't look like a standard stock card.");
                return;
            }

            if (($receipt ?? 0) > 0) {
                $rows[] = $this->row($r, 'receipt', $receipt, $token, $dateText, $ref, $office, ($issue ?? 0) > 0 ? null : $balance);
            }
            if (($issue ?? 0) > 0) {
                $rows[] = $this->row($r, 'issue', $issue, $token, $dateText, $ref, $office, $balance);
            }
        }

        $movements = array_filter($rows, static fn ($row) => in_array($row['type'], ['receipt', 'issue'], true));

        if ($rows === []) {
            $this->skip($card, 'The card has no entries.');
        } elseif ($movements !== [] && ! $balanceSeen) {
            $this->skip($card, 'No running balance is filled in, so this doesn\'t look like a standard stock card.');
        }

        $card['rows']      = $rows;
        $card['seed_year'] = $seeds['explicit'] ?? $seeds['rpci'] ?? $seeds['ref'];
        $card['rpci_year'] = $seeds['rpci'];
    }

    private function row(int $r, string $type, float $qty, ?array $token, string $raw, string $ref, string $office, ?float $balance): array
    {
        return [
            'row'     => $r,
            'type'    => $type,
            'qty'     => $qty,
            'token'   => $token,
            'raw'     => $raw,
            'ref'     => $ref,
            'office'  => $office,
            'balance' => $balance,
            'date'    => null,
        ];
    }

    private function isQtyRow(array $row, array $cols): bool
    {
        return strcasecmp(rtrim($this->text($row[$cols['receipt']] ?? null), '.'), 'qty') === 0
            || strcasecmp(rtrim($this->text($row[$cols['balance']] ?? null), '.'), 'qty') === 0;
    }

    /**
     * Text that marks a carry-forward line: another card's stock no. or "forwarded".
     */
    private function carryForwardText(array $texts, array $stockNos): ?string
    {
        foreach ($texts as $text) {
            if ($text === '') {
                continue;
            }
            if (preg_match('/forward|^bal(ance)?\.?\s*(b\/?f|fwd)/i', $text)) {
                return $text;
            }
            foreach (array_keys($stockNos) as $stockNo) {
                if ($this->sameStockNo($text, (string) $stockNo)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Stock nos. match when equal or one letter typo apart ("FPC P 32A" for "FPC FP 32A").
     * The numbers must be identical: "FPC RM 04A" and "FPC RM 01A" are different items.
     */
    private function sameStockNo(string $a, string $b): bool
    {
        $a = $this->norm($a);
        $b = $this->norm($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $digitsA = preg_replace('/\D/', '', $a);

        return min(strlen($a), strlen($b)) >= 6
            && $digitsA !== ''
            && $digitsA === preg_replace('/\D/', '', $b)
            && $this->editDistance($a, $b) <= 1;
    }

    // ── Dates ────────────────────────────────────────────────────────────────

    /**
     * Parse a date cell into parts; year/day may be missing. Null when it isn't a date.
     *
     * @return array{y: ?int, m: int, d: ?int, serial: bool, note: string}|null
     */
    private function dateToken(array $cell): ?array
    {
        $v = $cell['v'];

        if (is_float($v)) {
            if ($v >= 20000 && $v <= 80000) {
                [$y, $m, $d] = XlsxReader::serialToDate($v);
                return ['y' => $y, 'm' => $m, 'd' => $d, 'serial' => true, 'note' => ''];
            }
            if ($v >= 1 && $v <= 12 && floor($v) === $v) {
                return ['y' => null, 'm' => (int) $v, 'd' => null, 'serial' => false, 'note' => ''];
            }
            if ($v < 20000 && $v > 365) {
                // A serial with a wrong year typed in (e.g. 11079 = 1930); month and day are still usable
                [, $m, $d] = XlsxReader::serialToDate($v);
                return ['y' => null, 'm' => $m, 'd' => $d, 'serial' => true, 'note' => 'year looked wrong'];
            }
            return null;
        }

        $s = trim(trim((string) $v), "'\"`");
        $s = preg_replace('/\s+/', ' ', $s);
        $s = str_replace('\\', '/', $s);                              // "5\11"
        $s = preg_replace('#(?<=^|/)[oO](?=\d)|(?<=\d)[oO](?=/|$)#', '0', $s); // "O7/21/26": letter O for zero

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4}|\d{2})$#', $s, $m)) { // "12/30/2025", "01-13-2025", "2/28/26"
            return $this->tokenParts((int) $m[1], (int) $m[2], $this->fullYear($m[3]));
        }
        if (preg_match('#^(\d{1,2})/(\d{2})(\d{4})$#', $s, $m)) { // "02/022026"
            return $this->tokenParts((int) $m[1], (int) $m[2], (int) $m[3], 'missing "/" before the year');
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})\S+$#', $s, $m)) { // "12/30/20256/1/1930"
            return $this->tokenParts((int) $m[1], (int) $m[2], (int) $m[3], 'extra characters after the date ignored');
        }
        if (preg_match('#^(\d{2})(\d{2})/(\d{4}|\d{2})$#', $s, $m)) { // "0127/26": missing "/" between month and day
            return $this->tokenParts((int) $m[1], (int) $m[2], $this->fullYear($m[3]), 'missing "/" between month and day');
        }
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})((\s*[,&-]\s*\d{1,2})*)\s*/?$#', $s, $m)) { // "01/05", "04/06,07", "07/6/"
            return $this->tokenParts((int) $m[1], (int) $m[2], null, $m[3] !== '' ? 'several days written; used the first' : '');
        }
        if (preg_match('#^(\d{1,2})\s*/?$#', $s, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) { // "07/"
            return ['y' => null, 'm' => (int) $m[1], 'd' => null, 'serial' => false, 'note' => ''];
        }
        if (preg_match('/^([a-z]{3,9})[.,]*\s*(\d{1,2})(?:st|nd|rd|th)?\s*,?\s*(\d{4})?$/i', $s, $m)) { // "Dec. 31, 2025", "Feb., 14, 2025"
            $month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? 0;
            if ($month > 0) {
                return $this->tokenParts($month, (int) $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null);
            }
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})\d?$#', $s, $m)) { // "06/222": a stray digit
            return $this->tokenParts((int) $m[1], (int) $m[2], null, 'unclear date; read as ' . sprintf('%02d/%02d', $m[1], $m[2]));
        }

        return null;
    }

    /**
     * "PO 2026-05-BTI-201" typed in the Date column: letters and digits, but not a date.
     */
    private function looksLikeReference(string $text): bool
    {
        return preg_match_all('/[a-z]/i', $text) >= 2 && preg_match_all('/\d/', $text) >= 3;
    }

    private function tokenParts(int $m, int $d, ?int $y, string $note = ''): ?array
    {
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) {
            return null;
        }

        return ['y' => $y, 'm' => $m, 'd' => $d, 'serial' => false, 'note' => $note];
    }

    private function fullYear(string $y): int
    {
        return strlen($y) === 2 ? 2000 + (int) $y : (int) $y;
    }

    private function collectSeeds(array &$seeds, ?array $token, string $ref, string $dateText): void
    {
        if ($seeds['explicit'] === null && $token && $token['y'] && ! $token['serial'] && $this->plausibleYear($token['y'])) {
            $seeds['explicit'] = $token['y'];
        }
        foreach ([$ref, $dateText] as $text) {
            if ($seeds['rpci'] === null && preg_match('/RPCI\D*(20\d{2})/i', $text, $m)) {
                $seeds['rpci'] = (int) $m[1];
            }
            // "RIS2026-01-001", "FPC2025-11-071", "PO 2026-05-BTI-201"
            if ($seeds['ref'] === null && $text === $ref && preg_match('/[a-z]\s*-?\s*(20\d{2})(?!\d)/i', $text, $m) && $this->plausibleYear((int) $m[1])) {
                $seeds['ref'] = (int) $m[1];
            }
        }
    }

    private function plausibleYear(int $y): bool
    {
        return $y >= 2000 && $y <= $this->currentYear + 1;
    }

    /**
     * Resolve dates card by card. Cards that show their year go first; a continuation card then
     * continues from the card it carries forward; the rest use the year most entries fall in.
     *
     * @return int the year assumed for cards that never show one
     */
    private function resolveAllDates(array &$cards): int
    {
        $pending = [];
        foreach ($cards as $i => &$card) {
            if ($card['status'] !== 'ok') {
                continue;
            }
            if ($card['seed_year'] !== null) {
                $this->resolveDates($card, $card['seed_year']);
            } else {
                $pending[] = $i;
            }
        }
        unset($card);

        // Continuation cards, possibly chained ("(3)" continues "(2)")
        do {
            $progress = false;
            foreach ($pending as $k => $i) {
                $from = $this->continuedCard($cards, $cards[$i]);
                if ($from === null) {
                    continue;
                }
                $lastDate = $this->lastDate($from);
                $this->resolveDates($cards[$i], (int) substr($lastDate, 0, 4), $lastDate, false);
                unset($pending[$k]);
                $progress = true;
            }
        } while ($progress && $pending);

        $years = [];
        foreach ($cards as $card) {
            foreach ($card['rows'] as $row) {
                if ($row['date']) {
                    $years[] = (int) substr($row['date'], 0, 4);
                }
            }
        }
        $defaultYear = $this->currentYear;
        if ($years) {
            $counts = array_count_values($years);
            arsort($counts);
            $defaultYear = (int) array_key_first($counts);
        }

        foreach ($pending as $i) {
            $this->resolveDates($cards[$i], $defaultYear, null, true);
        }

        return $defaultYear;
    }

    /**
     * The already-dated card whose stock no. this card's carry-forward line names.
     */
    private function continuedCard(array $cards, array $card): ?array
    {
        $carry = $card['rows'][0] ?? null;
        if (! $carry || $carry['type'] !== 'carry') {
            return null;
        }

        $target     = $carry['ref'] !== '' ? $carry['ref'] : $carry['raw'];
        $candidates = array_filter($cards, fn ($other) => $other['status'] === 'ok'
            && $other['label'] !== $card['label']
            && $this->sameStockNo($other['stock_no'], $target)
            && $this->lastDate($other) !== null);

        // Prefer the card for the same item when several match
        foreach ($candidates as $other) {
            if ($this->norm($other['item']) === $this->norm($card['item'])) {
                return $other;
            }
        }

        return $candidates ? reset($candidates) : null;
    }

    private function lastDate(array $card): ?string
    {
        $dates = array_filter(array_column($card['rows'], 'date'));

        return $dates ? max($dates) : null;
    }

    /**
     * Give every row a full Y-m-d date, inferring missing years and days from the rows around it.
     *
     * @param string|null $startAfter last date of the card this one continues
     */
    private function resolveDates(array &$card, int $year, ?string $startAfter = null, bool $assumedYear = false): void
    {
        $startYear = $year;
        $prev      = $startAfter;
        $notes = [];

        foreach ($card['rows'] as $i => &$row) {
            $t = $row['token'];

            if ($t === null) {
                // An undated opening line of an "RPCI 2025" count is dated at that year-end
                if ($i === 0 && in_array($row['type'], ['opening', 'count'], true) && $card['rpci_year']) {
                    $row['date'] = $card['rpci_year'] . '-12-31';
                    $prev        = $row['date'];
                    $year        = $card['rpci_year'];
                }
                continue; // others are filled in below
            }

            $y = $t['y'];
            if ($y !== null && $t['serial'] && abs($y - $year) > 1) {
                $notes[] = "row {$row['row']}: year {$y} looked wrong, used {$year}";
                $y = null;
            } elseif ($t['note'] === 'year looked wrong') {
                $notes[] = "row {$row['row']}: year looked wrong, used {$year}";
            }

            if ($y === null) {
                $y = $year;
                // A month far behind the previous row (Dec → Apr) means the next year;
                // a small step back (Aug → Jun) is a slip in the card and is only reported
                $prevMonth = $prev ? (int) substr($prev, 5, 2) : 0;
                $prevYear  = $prev ? (int) substr($prev, 0, 4) : 0;
                if ($prev && $prevYear === $y && $prevMonth - $t['m'] >= 6 && $y + 1 <= $this->currentYear) {
                    $y = ++$year;
                } elseif ($prev && $prevYear > $y) {
                    $y = $year = $prevYear;
                }
            } else {
                $year = $y;
            }

            $d = $t['d'];
            if ($d === null) {
                $d = ($prev && (int) substr($prev, 0, 4) === $y && (int) substr($prev, 5, 2) === $t['m']) ? (int) substr($prev, 8, 2) : 1;
                $notes[] = "row {$row['row']}: day missing in \"{$row['raw']}\", used " . sprintf('%02d/%02d', $t['m'], $d);
            } elseif ($t['note'] !== '' && $t['note'] !== 'year looked wrong') {
                $notes[] = "row {$row['row']}: \"{$row['raw']}\" ({$t['note']})";
            }

            if (! checkdate($t['m'], $d, $y)) {
                $this->skip($card, "Row {$row['row']}: \"{$row['raw']}\" is not a valid date.");
                return;
            }

            $date = sprintf('%04d-%02d-%02d', $y, $t['m'], $d);
            // A guessed year can't put an entry in the future: "12/23" on a 2025 card is last December
            if ($t['y'] === null && $date > date('Y-m-d') && checkdate($t['m'], $d, $y - 1)) {
                $y    = $year = $y - 1;
                $date = sprintf('%04d-%02d-%02d', $y, $t['m'], $d);
                $notes[] = "row {$row['row']}: \"{$row['raw']}\" would be in the future, so it was read as {$date}";
            }
            if ($prev && $date < $prev) {
                $notes[] = "row {$row['row']}: date {$date} is earlier than the row before ({$prev})";
            }

            $row['date'] = $date;
            $prev        = $date;
        }
        unset($row);

        // Lines without a date: opening/carry lines take the RPCI year-end or the next date; movements the previous date
        $count = count($card['rows']);
        for ($i = 0; $i < $count; $i++) {
            if ($card['rows'][$i]['date'] !== null) {
                continue;
            }

            $row  = $card['rows'][$i];
            $prevDate = $i > 0 ? $card['rows'][$i - 1]['date'] : null;
            $nextDate = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($card['rows'][$j]['date'] !== null) {
                    $nextDate = $card['rows'][$j]['date'];
                    break;
                }
            }

            if (in_array($row['type'], ['opening', 'carry', 'count'], true)) {
                $date = $nextDate ?? $prevDate ?? $startAfter ?? sprintf('%04d-01-01', $year);
            } else {
                $date = $prevDate ?? $nextDate;
                if ($date === null) {
                    $this->skip($card, "Row {$row['row']}: no date, and no other row to take one from.");
                    return;
                }
                $notes[] = "row {$row['row']}: no date, used {$date} from the row " . ($prevDate ? 'before' : 'after');
            }
            $card['rows'][$i]['date'] = $date;
        }

        if ($assumedYear && $card['rows'] !== []) {
            array_unshift($notes, "the year is not written anywhere on this card; assumed {$startYear}");
        }
        foreach ($notes as $note) {
            $card['warnings'][] = ucfirst($note) . '.';
        }
    }

    private function finishCard(array &$card, string $fileName): void
    {
        $card['office_id'] = 0;
        $card['office']    = '';

        if ($card['status'] === 'ok') {
            $officeId = $this->officeFor($card['entity']);
            if ($officeId === 0) {
                $this->skip($card, $card['entity'] === ''
                    ? 'The card has no Entity Name, so its office is unknown. Choose an office for such cards and preview again.'
                    : "Entity \"{$card['entity']}\" does not match an office (" . implode(', ', $this->offices) . '). Choose an office for such cards and preview again.');
            } else {
                $card['office_id'] = $officeId;
                $card['office']    = $this->offices[$officeId];
            }
        }

        [$unit, $measurement] = $this->unitFromText($card['unit_text']);
        $card['unit']        = $unit;
        $card['measurement'] = $measurement;
        $card['name']        = $this->displayName($card['item']);
        $card['spelling']    = [];
        $card['type']        = $this->detectType($card, $fileName);
    }

    /**
     * Raw Materials / Product Label / Other Supplies / Finished Product, from the stock no.
     * ("FPC RM 01A", "FPC LABEL 05A", "FPC OS-PM 08A", "FPC FP-01A"), then the item name, then the file name.
     */
    private function detectType(array $card, string $fileName): string
    {
        $stockNo = strtoupper($card['stock_no']);
        $tokens  = preg_split('/[^A-Z]+/', $stockNo, -1, PREG_SPLIT_NO_EMPTY);

        if (in_array('LABEL', $tokens, true) || in_array('LBL', $tokens, true)) {
            return 'Product Label';
        }
        if (in_array('RM', $tokens, true)) {
            return 'Raw Materials';
        }
        if (in_array('OS', $tokens, true) || in_array('PM', $tokens, true)) {
            return 'Other Supplies';
        }
        if (in_array('FP', $tokens, true)) {
            return 'Finished Product';
        }

        // Only the item name: a description such as "with label" doesn't make the item a label
        if (preg_match('/\blabels?\b|\bstickers?\b/', strtolower($card['item']))) {
            return 'Product Label';
        }

        $file = strtolower($fileName);
        return match (true) {
            str_contains($file, 'label')                                   => 'Product Label',
            str_contains($file, 'raw')                                     => 'Raw Materials',
            str_contains($file, 'suppl') || str_contains($file, 'packag') => 'Other Supplies',
            str_contains($file, 'finished')                                => 'Finished Product',
            default                                                        => 'Raw Materials',
        };
    }

    /**
     * Correct misspelled words in item names ("SRAWBERRY" → "STRAWBERRY", "PAPAPYA" → "PAPAYA")
     * using the words of the other item names in the file and of the existing products.
     */
    private function fixSpelling(array &$cards): void
    {
        $counts = [];
        $add    = static function (string $name, int $weight) use (&$counts): void {
            foreach (preg_split('/[^a-z]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + $weight;
            }
        };
        foreach ($cards as $card) {
            if ($card['status'] === 'ok') {
                $add($card['name'], 1);
            }
        }
        // Names already in the system count double: they have been checked by people before
        foreach ($this->knownNames as $name) {
            $add($name, 2);
        }

        $fixes = [];
        foreach ($counts as $word => $count) {
            if (strlen($word) < 5 || $count > 1) {
                continue;
            }

            $best = null;
            foreach ($counts as $candidate => $candidateCount) {
                if ($candidate === $word || abs(strlen($candidate) - strlen($word)) > 1) {
                    continue;
                }
                // A word plus an ending is a different word, not a typo ("anise"/"anised", "bite"/"bites")
                if (str_starts_with($candidate, $word) || str_starts_with($word, $candidate)) {
                    continue;
                }
                // The common spelling wins; with no clear winner only an extra letter inside a word counts
                // as the typo ("papapya" → "papaya")
                $extraLetter = strlen($word) === strlen($candidate) + 1 && $this->editDistance($word, $candidate) === 1;
                if ($candidateCount < 2 && ! $extraLetter) {
                    continue;
                }
                $limit = strlen($word) >= 8 ? 2 : 1;
                if ($this->editDistance($word, $candidate) <= $limit && (! $best || $candidateCount > $counts[$best])) {
                    $best = $candidate;
                }
            }
            if ($best) {
                $fixes[$word] = $best;
            }
        }

        if (! $fixes) {
            return;
        }

        foreach ($cards as &$card) {
            if ($card['status'] !== 'ok') {
                continue;
            }
            $fixed = preg_replace_callback('/[A-Za-z]+/', function ($m) use ($fixes) {
                $lower = strtolower($m[0]);
                if (! isset($fixes[$lower])) {
                    return $m[0];
                }
                // Keep the original casing style
                $word = $fixes[$lower];
                return $m[0] === strtoupper($m[0]) ? strtoupper($word) : ($m[0][0] === strtoupper($m[0][0]) ? ucfirst($word) : $word);
            }, $card['name']);

            if ($fixed !== $card['name']) {
                $card['spelling'][] = "{$card['name']} → {$fixed}";
                $card['name']       = $fixed;
            }
        }
    }

    /**
     * Levenshtein distance that counts swapping two neighbouring letters as one edit.
     */
    private function editDistance(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        $d  = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost     = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb];
    }

    /**
     * One note for all cards without a unit, instead of one per card.
     */
    private function noteMissingUnits(array $cards): void
    {
        $labels = array_column(array_filter($cards, static fn ($c) => $c['status'] === 'ok' && trim($c['unit_text']) === ''), 'label');
        if ($labels) {
            $this->notes[] = count($labels) . ' card' . (count($labels) === 1 ? ' has' : 's have') . ' no unit of measurement, so "pcs" was used: ' . implode(', ', $labels) . '.';
        }
    }

    // ── Products ─────────────────────────────────────────────────────────────

    private function buildProducts(array &$cards): array
    {
        $groups = [];
        foreach ($cards as $i => $card) {
            if ($card['status'] !== 'ok') {
                continue;
            }
            // Same item = same office, name, unit, size ("250 g per jar" ≠ "200 g per jar") and type
            $size = preg_replace('/[^a-z0-9]/', '', strtolower($card['measurement']));
            $key  = implode('|', [$card['office_id'], $this->norm($card['name']), strtolower($card['unit']), $size, $card['type']]);
            $groups[$key][] = $i;
        }

        $products = [];
        foreach ($groups as $key => $indexes) {
            // Oldest card first
            usort($indexes, function ($a, $b) use ($cards) {
                return [$this->firstDate($cards[$a]), $a] <=> [$this->firstDate($cards[$b]), $b];
            });

            $first    = $cards[$indexes[0]];
            $product  = [
                'key'          => $key,
                'office_id'    => $first['office_id'],
                'office'       => $first['office'],
                'name'         => $first['name'],
                'unit'         => $first['unit'],
                'measurement'  => $first['measurement'],
                'description'  => $first['description'],
                // The oldest card may have none (e.g. a 2025 card before the stock no. scheme)
                'stock_no'     => current(array_filter(array_map(static fn ($i) => $cards[$i]['stock_no'], $indexes))) ?: '',
                'entity'       => $first['entity'],
                'type'         => $first['type'],
                'spelling'     => array_values(array_unique(array_merge(...array_map(static fn ($i) => $cards[$i]['spelling'], $indexes)))),
                'reorder'      => max(array_map(static fn ($i) => $cards[$i]['reorder'], $indexes)),
                'cards'        => array_map(static fn ($i) => $cards[$i]['label'], $indexes),
                'movements'    => [],
                'warnings'     => [],
                'status'       => 'ok',
                'reason'       => '',
            ];

            $balance  = 0.0;
            $lastDate = null;
            foreach ($indexes as $pos => $i) {
                $card = $cards[$i];
                $mismatchNoted = false;
                // A card that starts before the previous one ends runs alongside it (e.g. a donated lot):
                // its opening stock is added rather than taken as the new total
                $parallel = $pos > 0 && $lastDate !== null && $this->firstDate($card) < $lastDate;
                if ($parallel) {
                    $product['warnings'][] = "{$card['label']} runs alongside an earlier card for the same item, so its opening stock is added to it.";
                }
                // A parallel card's balance column only counts its own lot
                $cardOffset = $parallel ? $balance : 0.0;

                foreach ($card['rows'] as $k => $row) {
                    $type = $row['type'];
                    $qty  = $row['qty'];

                    if ($parallel && $k === 0 && in_array($type, ['count', 'opening'], true)) {
                        $type = 'receipt';
                    }

                    // Carry-forward, physical count or opening balance: "the stock is now X"
                    if (in_array($type, ['carry', 'count', 'opening'], true)) {
                        $diff = round($qty - $balance, 2);
                        if (abs($diff) < 0.001) {
                            continue; // the entries before already add up to it
                        }
                        if ($balance > 0.001) {
                            $what = match ($type) {
                                'carry'  => 'carries forward',
                                'count'  => 'counts',
                                default  => 'opens with',
                            };
                            $product['warnings'][] = "{$card['label']} row {$row['row']} {$what} {$this->fmt($qty)} but the entries before add up to {$this->fmt($balance)}; "
                                . ($diff > 0 ? 'recorded a receipt' : 'recorded an adjustment out') . " of {$this->fmt(abs($diff))} so the stock matches the card.";
                        }
                        $type = $diff > 0 ? 'receipt' : 'adjust_out';
                        $qty  = abs($diff);
                    }

                    if ($type !== 'receipt' && $qty > $balance + 0.0001) {
                        $product['status'] = 'skipped';
                        $product['reason'] = "{$card['label']} row {$row['row']}: issues {$this->fmt($row['qty'])} but only {$this->fmt($balance)} is in stock at that point.";
                        break 2;
                    }

                    $balance += $type === 'receipt' ? $qty : -$qty;
                    $lastDate = max($lastDate ?? $row['date'], $row['date']);

                    if (! $mismatchNoted && $row['balance'] !== null && abs($row['balance'] - ($balance - $cardOffset)) > 0.001) {
                        $product['warnings'][] = "{$card['label']} row {$row['row']}: Excel balance is {$this->fmt($row['balance'])}, the entries add up to {$this->fmt($balance - $cardOffset)}.";
                        $mismatchNoted = true;
                    }

                    $product['movements'][] = [
                        'sheet'   => $card['sheet'],
                        'card'    => $card['label'],
                        'row'     => $row['row'],
                        'type'    => $type,
                        'opening' => $row['type'] !== 'receipt' && $row['type'] !== 'issue',
                        'qty'     => $qty,
                        'date'    => $row['date'],
                        'raw_date' => $row['raw'],
                        'ref'     => $row['ref'],
                        'office'  => $row['office'],
                    ];
                }
            }

            $product['balance']  = round($balance, 2);
            $product['receipts']    = count(array_filter($product['movements'], static fn ($m) => $m['type'] === 'receipt'));
            $product['issues']      = count(array_filter($product['movements'], static fn ($m) => $m['type'] === 'issue'));
            $product['adjustments'] = count(array_filter($product['movements'], static fn ($m) => $m['type'] === 'adjust_out'));
            $product['first_date'] = $product['movements'][0]['date'] ?? null;
            $product['last_date']  = $product['movements'] ? end($product['movements'])['date'] : null;

            if ($product['status'] === 'ok' && $product['movements'] === []) {
                $product['status'] = 'skipped';
                $product['reason'] = 'No receipts or issues to import.';
            }

            $products[] = $product;
        }

        usort($products, static fn ($a, $b) => [$a['office'], strtolower($a['name'])] <=> [$b['office'], strtolower($b['name'])]);

        return $products;
    }

    private function firstDate(array $card): string
    {
        foreach ($card['rows'] as $row) {
            if ($row['date']) {
                return $row['date'];
            }
        }

        return '9999-12-31';
    }

    // ── Output ───────────────────────────────────────────────────────────────

    private function publicCard(array $card): array
    {
        return [
            'label'     => $card['label'],
            'sheet'     => $card['sheet'],
            'item'      => $card['item'],
            'stock_no'  => $card['stock_no'],
            'office'    => $card['office'],
            'unit'      => $card['unit'],
            'entries'   => count($card['rows']),
            'status'    => $card['status'],
            'reason'    => $card['reason'],
            'warnings'  => array_values(array_unique($card['warnings'])),
            // Where the card's fields and rows are, for corrections made in the preview
            'layout'    => [
                'sheet'      => $card['sheet'],
                'header_row' => $card['header_row'],
                'last_row'   => $card['last_row'],
                'columns'    => $card['columns'],
                'fields'     => $card['fields'],
            ],
        ];
    }

    private function summary(array $cards, array $products): array
    {
        $ok = array_filter($products, static fn ($p) => $p['status'] === 'ok');

        return [
            'sheets'            => count(array_unique(array_column($cards, 'sheet'))),
            'cards'             => count($cards),
            'cards_skipped'     => count(array_filter($cards, static fn ($c) => $c['status'] !== 'ok')),
            'products'          => count($ok),
            'products_skipped'  => count($products) - count($ok),
            'receipts'          => array_sum(array_column($ok, 'receipts')),
            'issues'            => array_sum(array_column($ok, 'issues')),
            'adjustments'       => array_sum(array_column($ok, 'adjustments')),
            'offices'           => array_values(array_unique(array_column($ok, 'office'))),
            'office_ids'        => array_values(array_unique(array_column($ok, 'office_id'))),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function officeFor(string $entity): int
    {
        $entityUpper = strtoupper($entity);
        $words       = array_filter(preg_split('/[^A-Z]+/', $entityUpper), static fn ($w) => $w !== '' && ! in_array($w, ['BSU', 'OF', 'THE', 'AND'], true));
        $acronym     = implode('', array_map(static fn ($w) => $w[0], $words));

        foreach ($this->offices as $id => $name) {
            $nameUpper = strtoupper(trim($name));
            if ($nameUpper !== '' && (preg_match('/\b' . preg_quote($nameUpper, '/') . '\b/', $entityUpper) || $acronym === $nameUpper)) {
                return $id;
            }
        }

        return isset($this->offices[$this->fallbackOfficeId]) ? $this->fallbackOfficeId : 0;
    }

    /**
     * The unit an item is counted in, plus the full text as its measurement:
     * "1 kilo per pack" → pack, "JARS OF 300g" → jar, "packs (1000pcs/pack)" → pack,
     * "12 bottles in one case" → case, "pieces" → pcs, "kls" → kilo.
     *
     * @return array{0: string, 1: string}
     */
    private function unitFromText(string $text): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text), " ;:,.");
        if ($clean === '') {
            return ['pcs', ''];
        }

        $lower = strtolower($clean);
        $plain = trim(preg_replace('/\([^)]*\)/', ' ', $lower)); // "(1000pcs/pack)" only describes the pack
        preg_match_all('/[a-z]+/', $plain, $all);
        $words = $all[0];

        $unit = null;
        // "… per jar", "…/gallon", "… in one case"
        if (preg_match('/(?:\bper|\/)\s*([a-z]+)\s*$/', $plain, $m) || preg_match('/\bin\s+(?:one|a|an|1)\s+([a-z]+)/', $plain, $m)) {
            $unit = $this->canonicalUnit($m[1]);
        }
        // Otherwise the first container ("JARS OF 300g"), then the first measure ("kilos")
        foreach ([false, true] as $measures) {
            foreach ($words as $word) {
                $candidate = $this->canonicalUnit($word);
                if ($unit === null && $candidate !== null && in_array($candidate, self::MEASURE_UNITS, true) === $measures) {
                    $unit = $candidate;
                    // A weight right after a number ("450g", "290 grams") is the size of each piece, not the unit
                    if ($measures && preg_match('/\d\s*' . preg_quote($word, '/') . '\b/', $plain)) {
                        $unit = 'pcs';
                    }
                }
            }
        }
        $unit ??= ($words ? $this->singular(end($words)) : 'pcs');

        $measurement = (preg_match('/\d/', $clean) || str_contains($lower, 'per') || str_contains($lower, ' in ') || count($words) > 1) ? $clean : '';

        return [$unit, $measurement];
    }

    private function canonicalUnit(string $word): ?string
    {
        $word = strtolower($word);

        return self::UNIT_SYNONYMS[$word] ?? self::UNIT_SYNONYMS[$this->singular($word)] ?? null;
    }

    private function singular(string $word): string
    {
        if (strlen($word) > 4 && preg_match('/(ch|sh|x)es$/', $word)) {
            return substr($word, 0, -2); // boxes → box, pouches → pouch
        }

        return strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
    }

    private function displayName(string $item): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $item), " ,.;:");
        // Glued words: "MatchaFlavored" → "Matcha Flavored"
        $name = preg_replace('/(?<=[a-z])(?=[A-Z][a-z])/', ' ', $name);

        if ($name !== '' && $name === strtoupper($name)) {
            $name = ucwords(strtolower($name));
        } else {
            // "CHOCOLATE, Brown" → "Chocolate, Brown"; short acronyms such as "PE" or "PET" stay
            $name = preg_replace_callback('/\b[A-Z]{4,}\b/', static fn ($m) => ucfirst(strtolower($m[0])), $name);
        }

        return $name;
    }

    private function skip(array &$card, string $reason): void
    {
        if ($card['status'] === 'ok') {
            $card['status'] = 'skipped';
            $card['reason'] = $reason;
        }
    }

    private function text(?array $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return is_float($cell['v']) ? $this->fmt($cell['v']) : trim($cell['v']);
    }

    private function qty(?array $cell): ?float
    {
        if ($cell === null) {
            return null;
        }

        return is_float($cell['v']) ? $cell['v'] : $this->number($cell['v']);
    }

    /**
     * Leading number of a text such as "1500 KLS"; null for "/CI 0901" or "Qty.".
     */
    private function number(string $text): ?float
    {
        return preg_match('/^\s*(\d+(?:[.,]\d+)?)/', $text, $m) ? (float) str_replace(',', '', $m[1]) : null;
    }

    private function norm(string $text): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $text)));
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function columnLetter(int $col): string
    {
        $letters = '';
        while ($col > 0) {
            $col--;
            $letters = chr(65 + $col % 26) . $letters;
            $col     = intdiv($col, 26);
        }

        return $letters;
    }
}
