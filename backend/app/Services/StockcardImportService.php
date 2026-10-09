<?php

namespace App\Services;

use App\Libraries\StockcardWorkbookParser;
use App\Libraries\XlsxReader;
use App\Models\BackupModel;
use App\Models\EntityModel;
use App\Models\ProductCopyModel;
use App\Models\ProductTypeModel;
use App\Models\UnitModel;
use CodeIgniter\Database\BaseConnection;
use DomainException;

/**
 * Imports a workbook of Appendix 58 stock cards.
 *
 * preview() parses the upload and keeps the plan on disk under a token;
 * commit() optionally backs up and wipes the inventory of the offices in the
 * file, then writes products, batches and transactions exactly like manual
 * stock entries (FIFO issues, a batch + barcode per receipt). Every imported
 * transaction carries a fingerprint, so importing the same rows again adds nothing.
 */
class StockcardImportService
{
    private const PLAN_TTL = 86400;

    private BaseConnection $db;

    /** @var array<string, int> lookup caches: "office|name" => id */
    private array $references = [];
    private array $destinations = [];

    /** @var array<int, string> user_office_id => name */
    private array $officeNames = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    // ── Preview ──────────────────────────────────────────────────────────────

    /** Card row fields that can be corrected in the preview */
    public const ROW_FIELDS = ['date', 'reference', 'receipt', 'issue', 'office', 'balance'];

    /** Card header fields that can be corrected (keys as the parser names them) */
    public const HEADER_FIELDS = ['item', 'stockno', 'description', 'unitofmeasurement'];

    /**
     * Read the upload and build the preview. The workbook's cells are kept with the plan so
     * corrections made in the preview can be applied and the file checked again.
     *
     * @return array{token: string, plan: array}
     */
    public function preview(string $path, string $fileName, int $userId, int $fallbackOfficeId = 0): array
    {
        $stored = [
            'user_id'   => $userId,
            'created'   => time(),
            'file_name' => $fileName,
            'fallback'  => $fallbackOfficeId,
            'sheets'    => (new XlsxReader($path))->sheets(),
            'edits'     => [],
        ];
        $stored['plan'] = $this->buildPlan($stored);
        // Card positions as read from the original file; corrections are placed with these
        $stored['layouts'] = array_column(array_map(static fn ($c) => ['label' => $c['label'], 'layout' => $c['layout']], $stored['plan']['cards']), 'layout', 'label');

        $token = bin2hex(random_bytes(16));
        $this->storePlan($token, $stored);

        return ['token' => $token, 'plan' => $stored['plan']];
    }

    /**
     * Apply corrections from the preview and check the file again.
     *
     * @param list<array{card: string, row?: int|null, field: string, value: string}> $edits
     */
    public function revise(string $token, int $userId, array $edits, ?int $fallbackOfficeId = null): ?array
    {
        $stored = $this->loadStored($token, $userId);
        if ($stored === null) {
            return null;
        }

        foreach ($edits as $edit) {
            $card  = (string) ($edit['card'] ?? '');
            $field = (string) ($edit['field'] ?? '');
            $row   = isset($edit['row']) && $edit['row'] !== null ? (int) $edit['row'] : null;
            if (! isset($stored['layouts'][$card])
                || ($row === null ? ! in_array($field, self::HEADER_FIELDS, true) : ! in_array($field, self::ROW_FIELDS, true))) {
                continue;
            }
            // A later correction of the same cell replaces the earlier one
            $stored['edits'][$card . '|' . ($row ?? 'h') . '|' . $field] = [
                'card'  => $card,
                'row'   => $row,
                'field' => $field,
                'value' => mb_substr(trim((string) ($edit['value'] ?? '')), 0, 255),
            ];
        }
        if ($fallbackOfficeId !== null) {
            $stored['fallback'] = $fallbackOfficeId;
        }

        $stored['plan'] = $this->buildPlan($stored);
        $this->storePlan($token, $stored);

        return $stored['plan'];
    }

    /**
     * One card's header and rows as they currently read (with corrections), for the editor.
     */
    public function cardRows(string $token, int $userId, string $label): ?array
    {
        $stored = $this->loadStored($token, $userId);
        $layout = $stored['layouts'][$label] ?? null;
        if ($layout === null) {
            return null;
        }

        $cells = $this->editedSheets($stored)[$layout['sheet']] ?? [];
        $cols  = $layout['columns'];

        $header = [];
        foreach (self::HEADER_FIELDS as $field) {
            $f = $layout['fields'][$field] ?? null;
            $header[$field] = $f === null ? null : $this->fieldValue($cells, $f);
        }

        $rows    = [];
        $lastRow = $layout['header_row'];
        if ($layout['header_row'] > 0) {
            foreach ($cells as $r => $row) {
                $r = (int) $r;
                if ($r <= $layout['header_row'] || $r > $layout['last_row']) {
                    continue;
                }
                $values = [];
                foreach (self::ROW_FIELDS as $field) {
                    $values[$field] = isset($cols[$field]) ? $this->displayCell($row[$cols[$field]] ?? null, $field === 'date') : null;
                }
                // Skip the "Qty." sub-header and lines with nothing in the card's columns
                if (preg_match('/^qty\.?$/i', (string) $values['receipt']) || ! array_filter($values, static fn ($v) => $v !== null && $v !== '')) {
                    continue;
                }
                $rows[]  = ['row' => $r] + $values;
                $lastRow = max($lastRow, $r);
            }
        }

        $next = $lastRow + 1;

        return [
            'label'    => $label,
            'header'   => $header,
            'columns'  => array_values(array_filter(self::ROW_FIELDS, static fn ($f) => isset($cols[$f]))),
            'rows'     => $rows,
            // Where a new line goes: below the last one, if that is still inside this card
            'next_row' => $layout['header_row'] > 0 && $next <= $layout['last_row'] ? $next : null,
            'edited'   => array_values(array_filter($stored['edits'], static fn ($e) => $e['card'] === $label)),
        ];
    }

    /**
     * The stored plan for this token, or null when it expired or belongs to someone else.
     */
    public function loadPlan(string $token, int $userId): ?array
    {
        return $this->loadStored($token, $userId)['plan'] ?? null;
    }

    private function loadStored(string $token, int $userId): ?array
    {
        $path = $this->planPath($token);
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $stored = json_decode((string) file_get_contents($path), true);
        if (! is_array($stored) || (int) $stored['user_id'] !== $userId || time() - (int) $stored['created'] > self::PLAN_TTL) {
            return null;
        }

        return $stored;
    }

    private function buildPlan(array $stored): array
    {
        $offices = array_column(
            $this->db->table('user_office_table')->orderBy('user_office_id')->get()->getResultArray(),
            'user_office_name',
            'user_office_id'
        );

        // Existing product names are the spelling reference for the item names in the file
        $knownNames = array_column($this->db->table('product_table')->select('product')->get()->getResultArray(), 'product');

        $sheets = [];
        foreach ($this->editedSheets($stored) as $name => $cells) {
            $sheets[] = ['name' => (string) $name, 'cells' => $cells];
        }

        $plan = (new StockcardWorkbookParser($offices, (int) $stored['fallback'], $knownNames))->parse($sheets, $stored['file_name']);
        $plan['file_name'] = $stored['file_name'];
        $plan['offices']   = array_map(static fn ($id, $name) => ['id' => (int) $id, 'name' => $name], array_keys($offices), $offices);
        $plan['edits']     = count($stored['edits']);

        // What "replace" would delete, so the dialog can say it before anyone confirms
        $plan['existing'] = $this->inventoryCounts($plan['summary']['office_ids']);

        return $plan;
    }

    /**
     * The workbook cells with the preview corrections written in.
     *
     * @return array<string, array> sheet name => cells
     */
    private function editedSheets(array $stored): array
    {
        $sheets = [];
        foreach ($stored['sheets'] as $sheet) {
            $sheets[$sheet['name']] = $sheet['cells'];
        }

        foreach ($stored['edits'] as $edit) {
            $layout = $stored['layouts'][$edit['card']] ?? null;
            if ($layout === null) {
                continue;
            }
            $cells = &$sheets[$layout['sheet']];
            $value = $edit['value'];

            if ($edit['row'] === null) {
                $f = $layout['fields'][$edit['field']] ?? null;
                if ($f === null) {
                    continue;
                }
                // "Item : New name" in the label cell; any separate value cells are cleared
                $cells[$f['row']][$f['col']] = ['v' => $f['label'] . ' : ' . $value, 'date' => false];
                foreach ($f['value_cols'] as $c) {
                    unset($cells[$f['row']][$c]);
                }
            } else {
                $col = $layout['columns'][$edit['field']] ?? null;
                if ($col === null) {
                    continue;
                }
                if ($value === '') {
                    unset($cells[$edit['row']][$col]);
                } else {
                    $numeric = in_array($edit['field'], ['receipt', 'issue', 'balance'], true) && is_numeric(str_replace(',', '', $value));
                    $cells[$edit['row']][$col] = ['v' => $numeric ? (float) str_replace(',', '', $value) : $value, 'date' => false];
                }
                ksort($cells); // keep rows in order for the parser
            }
            unset($cells);
        }

        return $sheets;
    }

    private function fieldValue(array $cells, array $field): string
    {
        $labelText = (string) ($cells[$field['row']][$field['col']]['v'] ?? '');
        $inline    = trim((string) preg_replace('/^[^:]*:/', '', $labelText), " \t_");
        if ($inline !== '') {
            return $inline;
        }

        $parts = [];
        foreach ($field['value_cols'] as $c) {
            $v = $cells[$field['row']][$c]['v'] ?? '';
            $parts[] = is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : trim((string) $v);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function displayCell(?array $cell, bool $isDate): ?string
    {
        if ($cell === null) {
            return null;
        }
        $v = $cell['v'];
        if (is_float($v)) {
            if ($isDate && $v >= 20000 && $v <= 80000) {
                [$y, $m, $d] = XlsxReader::serialToDate($v);
                return sprintf('%02d/%02d/%04d', $m, $d, $y);
            }
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        }

        return trim((string) $v);
    }

    public function forgetPlan(string $token): void
    {
        $path = $this->planPath($token);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    // ── Commit ───────────────────────────────────────────────────────────────

    /**
     * @param bool                  $replace delete the existing inventory of the file's offices first
     * @param array<string, string> $types   product key => product type chosen in the preview (detected type otherwise)
     */
    public function commit(array $plan, bool $replace, array $types, array $user): array
    {
        $products = array_values(array_filter($plan['products'], static fn ($p) => $p['status'] === 'ok'));
        foreach ($products as &$product) {
            $chosen          = $types[$product['key']] ?? '';
            $product['type'] = in_array($chosen, StockcardWorkbookParser::TYPES, true) ? $chosen : $product['type'];
        }
        unset($product);
        $officeIds = array_map('intval', $plan['summary']['office_ids']);

        if ($products === []) {
            throw new DomainException('There is nothing to import in this file.');
        }

        $userId   = (int) ($user['id'] ?? 0);
        $username = (string) ($user['username'] ?? '');
        $result   = [
            'backups'            => [],
            'deleted'            => null,
            'products_created'   => 0,
            'products_reused'    => 0,
            'receipts'           => 0,
            'issues'             => 0,
            'adjustments'        => 0,
            'already_imported'   => 0,
            'offices'            => $plan['summary']['offices'],
        ];

        // A backup of each office comes first; nothing is deleted without one
        if ($replace) {
            $backupModel = new BackupModel();
            foreach ($officeIds as $officeId) {
                $officeName = $this->officeName($officeId);
                $backup     = $backupModel->createBackup($officeId, $userId, $officeName, $username);
                if (! ($backup['ok'] ?? false)) {
                    throw new DomainException("Backup of {$officeName} failed, so nothing was deleted or imported: " . ($backup['message'] ?? 'unknown error'));
                }
                $result['backups'][] = $officeName;
            }
        }

        $runId = 'IMP-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $order = 0;

        $this->db->transException(true)->transStart();

        if ($replace) {
            $result['deleted'] = $this->wipeInventory($officeIds);
        }

        foreach ($products as $product) {
            $officeId = (int) $product['office_id'];
            [$productId, $created] = $this->findOrCreateProduct($product, $replace);
            $result[$created ? 'products_created' : 'products_reused']++;

            $copy    = (new ProductCopyModel())->findOrCreateCopy($productId, 0.0, $officeId);
            $copyId  = (int) $copy['copy_id'];
            $batches = $this->openBatches($copyId, $officeId);

            foreach ($product['movements'] as $movement) {
                $fingerprint = $this->fingerprint($product, $movement);
                if ($this->alreadyImported($officeId, $fingerprint)) {
                    $result['already_imported']++;
                    continue;
                }

                $meta = [
                    'stock_import_fingerprint' => $fingerprint,
                    'stock_import_batch'       => $runId,
                    'stock_import_order'       => ++$order,
                    'stock_import_source_row'  => (int) $movement['row'],
                    'stock_import_raw_date'    => mb_substr((string) $movement['raw_date'], 0, 255),
                ];

                if ($movement['type'] === 'receipt') {
                    $batches[] = $this->receive($productId, $copyId, $officeId, $userId, $movement, $meta);
                    $result['receipts']++;
                } else {
                    // An issue, or an adjustment out where a stock count is lower than the entries before it
                    $this->issue($batches, $copyId, $officeId, $userId, $movement, $meta, $product['name']);
                    $result[$movement['type'] === 'adjust_out' ? 'adjustments' : 'issues']++;
                }
            }
        }

        $this->db->transComplete();

        return $result;
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: bool} product id, whether it was created
     */
    private function findOrCreateProduct(array $product, bool $replace): array
    {
        $officeId = (int) $product['office_id'];
        $unitId   = (new UnitModel())->firstOrCreate($product['unit'], $officeId);
        $typeId   = (new ProductTypeModel())->firstOrCreate($product['type'], $officeId);

        $measurement = mb_substr($product['measurement'], 0, 100);

        if (! $replace) {
            // Same name, unit, size and type: a "Kimchi" label is a different product from "Kimchi" the
            // finished good, and a 250 g jar from a 200 g jar
            $builder = $this->db->table('product_table')
                ->select('product_id, archived_at')
                ->where('user_office_id', $officeId)
                ->where('unit_id', $unitId)
                ->where('type_id', $typeId)
                ->where('LOWER(product)', mb_strtolower($product['name']));
            $measurement === ''
                ? $builder->groupStart()->where('measurement', null)->orWhere('measurement', '')->groupEnd()
                : $builder->where('measurement', $measurement);
            $existing = $builder->get(1)->getRowArray();

            if ($existing) {
                // The file has stock for it again, so it comes back from the archive
                if (! empty($existing['archived_at'])) {
                    $this->db->table('product_table')->where('product_id', $existing['product_id'])
                        ->update(['archived_at' => null, 'archived_by' => null, 'archive_reason' => '']);
                }

                return [(int) $existing['product_id'], false];
            }
        }

        $productNo = (int) ($this->db->table('product_table')->selectMax('product_no', 'n')->where('user_office_id', $officeId)->get()->getRowArray()['n'] ?? 0) + 1;
        $office    = $this->officeName($officeId);

        $this->db->table('product_table')->insert([
            'product_no'            => $productNo,
            'product'               => mb_substr($product['name'], 0, 255),
            'product_description'   => mb_substr($product['description'], 0, 1000),
            'measurement'           => $measurement ?: null,
            'product_reorder_point' => (int) $product['reorder'],
            'expiry_warning_days'   => 30,
            'expiry_danger_days'    => 7,
            'unit_id'               => $unitId,
            'type_id'               => $typeId,
            'user_office_id'        => $officeId,
            'entity_id'             => $this->entityId($product['entity'] ?: $office, $officeId),
            'stock_no'              => $product['stock_no'] !== '' ? mb_substr($product['stock_no'], 0, 255) : strtoupper($office) . '-' . str_pad((string) $productNo, 4, '0', STR_PAD_LEFT),
        ]);

        return [(int) $this->db->insertID(), true];
    }

    /**
     * A receipt: new batch (with barcode) + receipt transaction. Returns the batch for FIFO issues.
     */
    private function receive(int $productId, int $copyId, int $officeId, int $userId, array $movement, array $meta): array
    {
        $stamp       = $movement['date'] . ' 08:00:00';
        $referenceId = $this->referenceId($movement['ref'], $officeId);
        $destination = $this->destinationId($movement['office'], $officeId);

        $this->db->table('batch_table')->insert([
            'batch_no'       => (new InventoryService($this->db))->nextBatchNo(
                'B-' . strtoupper($this->officeName($officeId)) . '-' . str_replace('-', '', $movement['date']) . '-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT)
            ),
            'product_id'     => $productId,
            'copy_id'        => $copyId,
            'user_office_id' => $officeId,
            'reference_id'   => $referenceId,
            'office_id'      => $destination,
            'current_qty'    => $movement['qty'],
            'date_received'  => $movement['date'],
            'created_at'     => $stamp,
            'updated_at'     => $stamp,
        ]);
        $batchId = (int) $this->db->insertID();

        $barcodes = new BarcodeService();
        $barcode  = $barcodes->generateBatchValue($batchId);
        $barcodes->saveBatchBarcode($barcode);
        $this->db->table('batch_table')->where('batch_id', $batchId)->update(['barcode_value' => $barcode]);

        $this->db->table('transaction_table')->insert($meta + [
            'transaction_type_id'   => $this->typeId('receipt'),
            'transaction_qty'       => $movement['qty'],
            'transaction_unit_cost' => 0,
            'transaction_date'      => $stamp,
            'batch_id'              => $batchId,
            'copy_id'               => $copyId,
            'reference_id'          => $referenceId,
            'office_id'             => $destination,
            'user_id'               => $userId ?: null,
            'user_office_id'        => $officeId,
            'created_at'            => $stamp,
            'updated_at'            => $stamp,
        ]);

        return ['batch_id' => $batchId, 'qty' => (float) $movement['qty']];
    }

    /**
     * An issue (or adjustment out): take from the oldest batches first, one transaction per batch touched.
     */
    private function issue(array &$batches, int $copyId, int $officeId, int $userId, array $movement, array $meta, string $productName): void
    {
        $stamp       = $movement['date'] . ' 08:00:00';
        $referenceId = $this->referenceId($movement['ref'], $officeId);
        $destination = $this->destinationId($movement['office'], $officeId);
        $remaining   = (float) $movement['qty'];
        $part        = 0;

        foreach ($batches as &$batch) {
            if ($remaining <= 0.0001) {
                break;
            }
            if ($batch['qty'] <= 0.0001) {
                continue;
            }

            $take          = min($batch['qty'], $remaining);
            $batch['qty'] -= $take;
            $remaining    -= $take;

            $this->db->table('batch_table')->where('batch_id', $batch['batch_id'])->update([
                'current_qty' => round($batch['qty'], 2),
                'updated_at'  => $stamp,
            ]);

            // One source row may split over several batches; only the first part keeps the fingerprint
            $rowMeta = $part++ === 0 ? $meta : array_merge($meta, ['stock_import_fingerprint' => null]);

            $this->db->table('transaction_table')->insert($rowMeta + [
                'transaction_type_id'   => $this->typeId($movement['type']), // issue or adjust_out
                'transaction_qty'       => round($take, 2),
                'transaction_unit_cost' => 0,
                'transaction_date'      => $stamp,
                'batch_id'              => $batch['batch_id'],
                'copy_id'               => $copyId,
                'reference_id'          => $referenceId,
                'office_id'             => $destination,
                'user_id'               => $userId ?: null,
                'user_office_id'        => $officeId,
                'created_at'            => $stamp,
                'updated_at'            => $stamp,
            ]);
        }
        unset($batch);

        if ($remaining > 0.0001) {
            throw new DomainException("{$productName}: an issue on {$movement['date']} is larger than the stock available. Nothing was imported.");
        }
    }

    /**
     * Batches of this sub-product that still have stock, oldest first.
     */
    private function openBatches(int $copyId, int $officeId): array
    {
        $rows = $this->db->table('batch_table')
            ->select('batch_id, current_qty')
            ->where('copy_id', $copyId)
            ->where('user_office_id', $officeId)
            ->where('current_qty >', 0)
            ->orderBy('date_received', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn ($r) => ['batch_id' => (int) $r['batch_id'], 'qty' => (float) $r['current_qty']], $rows);
    }

    /**
     * Delete products, sub-products, batches, transactions and stock-out requests of these offices.
     * Users, offices, units, references, entities, product types and settings stay.
     *
     * @return array<string, int> rows deleted per kind
     */
    private function wipeInventory(array $officeIds): array
    {
        $counts     = $this->inventoryCounts($officeIds);
        $productIds = array_map('intval', array_column(
            $this->db->table('product_table')->select('product_id')->whereIn('user_office_id', $officeIds)->get()->getResultArray(),
            'product_id'
        ));

        $this->db->table('transaction_table')->whereIn('user_office_id', $officeIds)->delete();
        if ($this->db->tableExists('borrow_table')) {
            $this->db->table('borrow_table')->whereIn('user_office_id', $officeIds)->delete();
        }
        if ($productIds) {
            $this->db->table('temp_stockout_item')->whereIn('product_id', $productIds)->delete();
        }
        $this->db->table('temp_stockout')->whereIn('user_office_id', $officeIds)->delete();
        $this->db->table('batch_table')->whereIn('user_office_id', $officeIds)->delete();
        if ($productIds) {
            $this->db->table('batch_table')->whereIn('product_id', $productIds)->delete();
            $this->db->table('product_copy_table')->whereIn('product_id', $productIds)->delete();
        }
        $this->db->table('product_table')->whereIn('user_office_id', $officeIds)->delete();

        return $counts;
    }

    /**
     * @return array{products: int, batches: int, transactions: int, requests: int}
     */
    private function inventoryCounts(array $officeIds): array
    {
        if ($officeIds === []) {
            return ['products' => 0, 'batches' => 0, 'transactions' => 0, 'requests' => 0];
        }

        $count = fn (string $table) => $this->db->table($table)->whereIn('user_office_id', $officeIds)->countAllResults();

        return [
            'products'     => $count('product_table'),
            'batches'      => $count('batch_table'),
            'transactions' => $count('transaction_table'),
            'requests'     => $count('temp_stockout'),
        ];
    }

    // ── Lookups ──────────────────────────────────────────────────────────────

    private function referenceId(string $reference, int $officeId): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $key = $officeId . '|' . mb_strtolower($reference);
        if (! isset($this->references[$key])) {
            $row = $this->db->table('reference_table')->select('reference_id')
                ->where('reference', $reference)->where('user_office_id', $officeId)->get(1)->getRowArray();
            if (! $row) {
                $this->db->table('reference_table')->insert(['reference' => mb_substr($reference, 0, 255), 'user_office_id' => $officeId]);
            }
            $this->references[$key] = $row ? (int) $row['reference_id'] : (int) $this->db->insertID();
        }

        return $this->references[$key];
    }

    private function destinationId(string $office, int $officeId): ?int
    {
        $office = trim($office);
        if ($office === '') {
            return null;
        }

        $key = $officeId . '|' . mb_strtolower($office);
        if (! isset($this->destinations[$key])) {
            $row = $this->db->table('office_table')->select('office_id')
                ->where('office_name', $office)->where('user_office_id', $officeId)->get(1)->getRowArray();
            if (! $row) {
                $this->db->table('office_table')->insert(['office_name' => mb_substr($office, 0, 255), 'user_office_id' => $officeId]);
            }
            $this->destinations[$key] = $row ? (int) $row['office_id'] : (int) $this->db->insertID();
        }

        return $this->destinations[$key];
    }

    /**
     * The entity for imported products: the one named on the card if the office has it,
     * otherwise the office's existing entity (e.g. "Benguet State University" set up in
     * Others Management). A new entity is only created when the office has none.
     */
    private function entityId(string $cardEntity, int $officeId): ?int
    {
        $existing = $this->db->table('entity_table')
            ->select('entity_id')
            ->where('user_office_id', $officeId)
            ->where('entity', trim($cardEntity))
            ->get(1)->getRowArray()
            ?? $this->db->table('entity_table')
                ->select('entity_id')
                ->where('user_office_id', $officeId)
                ->orderBy('entity_id', 'ASC')
                ->get(1)->getRowArray();

        if ($existing) {
            return (int) $existing['entity_id'];
        }

        return (new EntityModel())->firstOrCreate($cardEntity, $officeId) ?: null;
    }

    private function officeName(int $officeId): string
    {
        return $this->officeNames[$officeId] ??= (string) (
            $this->db->table('user_office_table')->where('user_office_id', $officeId)->get(1)->getRowArray()['user_office_name'] ?? "Office #{$officeId}"
        );
    }

    private function typeId(string $name): int
    {
        static $ids = [];
        if (! isset($ids[$name])) {
            $row = $this->db->table('transaction_type_table')->select('transaction_type_id')->where('transaction_type', $name)->get(1)->getRowArray();
            if (! $row) {
                throw new DomainException("Transaction type '{$name}' is missing from the database.");
            }
            $ids[$name] = (int) $row['transaction_type_id'];
        }

        return $ids[$name];
    }

    private function fingerprint(array $product, array $movement): string
    {
        return hash('sha256', json_encode([
            (int) $product['office_id'],
            mb_strtolower($product['name']),
            mb_strtolower($product['unit']),
            $movement['card'],
            (int) $movement['row'],
            $movement['type'],
            $movement['date'],
            round((float) $movement['qty'], 2),
            mb_strtolower(trim($movement['ref'])),
        ]));
    }

    private function alreadyImported(int $officeId, string $fingerprint): bool
    {
        return $this->db->table('transaction_table')
            ->where('user_office_id', $officeId)
            ->where('stock_import_fingerprint', $fingerprint)
            ->countAllResults() > 0;
    }

    // ── Plan storage ─────────────────────────────────────────────────────────

    private function storePlan(string $token, array $data): void
    {
        $dir = WRITEPATH . 'imports' . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            throw new DomainException('Cannot create ' . $dir);
        }

        // Drop plans nobody committed
        foreach (glob($dir . '*.json') ?: [] as $old) {
            if (time() - filemtime($old) > self::PLAN_TTL) {
                @unlink($old);
            }
        }

        // Keep 12.0 a float (the parser tells numbers and dates from text by type)
        $json = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($dir . $token . '.json', $json) === false) {
            throw new DomainException('Could not save the import preview.');
        }
    }

    private function planPath(string $token): ?string
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) ? WRITEPATH . 'imports' . DIRECTORY_SEPARATOR . $token . '.json' : null;
    }
}
