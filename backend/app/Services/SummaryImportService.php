<?php

namespace App\Services;

use App\Libraries\SummaryReportParser;
use App\Libraries\XlsxReader;
use App\Models\BackupModel;
use App\Models\EntityModel;
use App\Models\ProductCopyModel;
use App\Models\ProductTypeModel;
use App\Models\UnitModel;
use CodeIgniter\Database\BaseConnection;
use DomainException;

/**
 * Imports a workbook of Summary Report sheets.
 *
 * preview() parses the upload and caches the plan on disk under a token;
 * commit() optionally backs up and wipes the inventory of the current office,
 * then writes products, product copies, batches and transactions to match the
 * quantities and costs in the file:
 *
 *   begin_qty  > 0  → receipt  on the last day of the previous month ("Opening Balance"),
 *                     so the report shows it as the month's beginning inventory
 *   purch_qty  > 0  → receipt  on the 15th of the month ("Purchase")
 *   used_qty   > 0  → issue    on the 20th of the month
 *   spoil_qty  > 0  → adjust_out on the 25th of the month
 *
 * Used and spoiled quantities are taken from the product's stock oldest first, across
 * every price, as the stock card does. If the file uses more than it has, nothing is
 * imported: the file is wrong, and stock is never made up to cover it.
 *
 * Every transaction carries a SHA-256 fingerprint so re-importing the same
 * file in "append" mode adds nothing.
 */
class SummaryImportService
{
    private const PLAN_TTL = 86400; // 24 hours

    private BaseConnection $db;

    /** @var array<string, int> 'office|lower(ref)' => reference_id */
    private array $referenceCache = [];

    /** @var array<int, string> user_office_id => user_office_name */
    private array $officeNames = [];

    private string $runId = '';

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    // ── Preview ──────────────────────────────────────────────────────────────

    /**
     * Parse the uploaded file and return a preview plan without writing anything.
     *
     * @return array{token: string, plan: array}
     */
    public function preview(string $path, string $fileName, int $userId, int $officeId, string $monthHint = ''): array
    {
        $sheets = (new XlsxReader($path))->sheets();
        $plan   = (new SummaryReportParser())->parse($sheets, $monthHint);

        $plan['file_name'] = $fileName;
        $plan['office_id'] = $officeId;
        $plan['existing']  = $this->inventoryCounts([$officeId]);

        $stored = [
            'user_id'   => $userId,
            'created'   => time(),
            'file_name' => $fileName,
            'office_id' => $officeId,
            'plan'      => $plan,
        ];

        $token = bin2hex(random_bytes(16));
        $this->storePlan($token, $stored);

        return ['token' => $token, 'plan' => $plan];
    }

    /**
     * Load the cached plan for the given token and user.
     */
    public function loadPlan(string $token, int $userId): ?array
    {
        return $this->loadStored($token, $userId)['plan'] ?? null;
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
     * Write the approved plan to the database.
     *
     * @param  bool  $replace  Wipe the office's inventory first.
     * @param  array $user     { id, username }
     * @return array           Import result statistics.
     */
    public function commit(array $plan, bool $replace, array $user): array
    {
        $products = array_values(array_filter($plan['products'] ?? [], static fn ($p) => $p['status'] === 'ok'));
        if ($products === []) {
            throw new DomainException('There is nothing to import in this file.');
        }

        $officeId = (int) ($plan['office_id'] ?? 0);
        if ($officeId <= 0) {
            throw new DomainException('Office could not be determined.');
        }

        $userId   = (int)    ($user['id']       ?? 0);
        $username = (string) ($user['username']  ?? '');

        $result = [
            'backups'          => [],
            'deleted'          => null,
            'products_created' => 0,
            'products_reused'  => 0,
            'receipts'         => 0,
            'issues'           => 0,
            'adjustments'      => 0,
            'already_imported' => 0,
        ];

        // Always create a backup before replacing
        if ($replace) {
            $officeName  = $this->officeName($officeId);
            $backupModel = new BackupModel();
            $backup      = $backupModel->createBackup($officeId, $userId, $officeName, $username);
            if (! ($backup['ok'] ?? false)) {
                throw new DomainException("Backup of {$officeName} failed; nothing was deleted: " . ($backup['message'] ?? 'unknown error'));
            }
            $result['backups'][] = $officeName;
        }

        $this->runId = 'SIM-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

        $this->db->transException(true)->transStart();

        if ($replace) {
            $result['deleted'] = $this->wipeInventory([$officeId]);
        }

        try {
            $this->writeProducts($products, $plan, $officeId, $userId, $result);
        } catch (\Throwable $e) {
            // A DomainException (a row the file can't balance) is not a query error,
            // so the transaction has to be rolled back here
            $this->db->transRollback();
            throw $e;
        }

        $this->db->transComplete();

        return $result;
    }

    private function writeProducts(array $products, array $plan, int $officeId, int $userId, array &$result): void
    {
        foreach ($products as $product) {
            $month     = $product['month'] ?? ($plan['month'] ?? '');
            $monthDate = $month !== '' ? $month . '-01' : date('Y-m-01');

            [$productId, $created] = $this->findOrCreateProduct($product, $officeId);
            $result[$created ? 'products_created' : 'products_reused']++;

            // Opening balance receipt (begin)
            if ($product['begin_qty'] > 0) {
                $fp = $this->fingerprint($product, 'begin', $officeId, $month);
                if (! $this->alreadyImported($officeId, $fp)) {
                    $copyId  = (int) (new ProductCopyModel())->findOrCreateCopy($productId, (float) $product['begin_cost'], $officeId)['copy_id'];
                    $this->insertReceiptRow($productId, $copyId, $officeId, $userId, [
                        'qty'  => $product['begin_qty'],
                        'cost' => $product['begin_cost'],
                        'date' => date('Y-m-d', strtotime($monthDate . ' -1 day')),
                        'ref'  => 'Opening Balance',
                    ], $fp);
                    $result['receipts']++;
                } else {
                    $result['already_imported']++;
                }
            }

            // Purchase receipt
            if ($product['purch_qty'] > 0) {
                $fp = $this->fingerprint($product, 'purch', $officeId, $month);
                if (! $this->alreadyImported($officeId, $fp)) {
                    $purchCost = (float) ($product['purch_cost'] > 0 ? $product['purch_cost'] : $product['begin_cost']);
                    $copyId    = (int) (new ProductCopyModel())->findOrCreateCopy($productId, $purchCost, $officeId)['copy_id'];
                    $purchDate = date('Y-m-d', strtotime($monthDate . ' +14 days'));
                    $this->insertReceiptRow($productId, $copyId, $officeId, $userId, [
                        'qty'  => $product['purch_qty'],
                        'cost' => $purchCost,
                        'date' => $purchDate,
                        'ref'  => 'Purchase',
                    ], $fp);
                    $result['receipts']++;
                } else {
                    $result['already_imported']++;
                }
            }

            // Used (issue)
            if ($product['used_qty'] > 0) {
                $fp = $this->fingerprint($product, 'used', $officeId, $month);
                if (! $this->alreadyImported($officeId, $fp)) {
                    $usedDate = date('Y-m-d', strtotime($monthDate . ' +19 days'));
                    $this->insertIssueRow($productId, $officeId, $userId, [
                        'qty'  => $product['used_qty'],
                        'date' => $usedDate,
                        'type' => 'issue',
                        'ref'  => 'Used',
                    ], $fp, $product);
                    $result['issues']++;
                } else {
                    $result['already_imported']++;
                }
            }

            // Spoiled / breakage (adjust_out)
            if ($product['spoil_qty'] > 0) {
                $fp = $this->fingerprint($product, 'spoil', $officeId, $month);
                if (! $this->alreadyImported($officeId, $fp)) {
                    $spoilDate = date('Y-m-d', strtotime($monthDate . ' +24 days'));
                    $this->insertIssueRow($productId, $officeId, $userId, [
                        'qty'    => $product['spoil_qty'],
                        'date'   => $spoilDate,
                        'type'   => 'adjust_out',
                        'ref'    => 'Breakage/Spoiled',
                        'reason' => $this->adjustmentReasonId('Spoiled'),
                    ], $fp, $product);
                    $result['adjustments']++;
                } else {
                    $result['already_imported']++;
                }
            }
        }
    }

    // ── Product/copy lookup & creation ───────────────────────────────────────

    /**
     * @return array{0: int, 1: bool}  [product_id, was_created]
     */
    private function findOrCreateProduct(array $product, int $officeId): array
    {
        $unit   = trim($product['unit']);
        $type   = trim($product['type']);
        $item   = trim($product['item']);

        $unitId = (new UnitModel())->firstOrCreate($unit !== '' ? $unit : 'pcs', $officeId);
        $typeId = (new ProductTypeModel())->firstOrCreate($type !== '' ? $type : 'Uncategorized', $officeId);

        // Look for an existing product: same name (case-insensitive) + unit + type + office
        $existing = $this->db->table('product_table')
            ->select('product_id, archived_at')
            ->where('user_office_id', $officeId)
            ->where('unit_id', $unitId)
            ->where('type_id', $typeId)
            ->where('LOWER(product)', mb_strtolower($item))
            ->get(1)->getRowArray();

        if ($existing) {
            // Restore from archive if needed
            if (! empty($existing['archived_at'])) {
                $this->db->table('product_table')
                    ->where('product_id', $existing['product_id'])
                    ->update(['archived_at' => null, 'archived_by' => null, 'archive_reason' => '']);
            }
            return [(int) $existing['product_id'], false];
        }

        // Insert first with empty product_no to get the auto-increment product_id
        $this->db->table('product_table')->insert([
            'product_no'            => '',  // temporary — updated below
            'product'               => mb_substr($item, 0, 255),
            'product_description'   => '',
            'product_reorder_point' => 0,
            'expiry_warning_days'   => 30,
            'expiry_danger_days'    => 7,
            'unit_id'               => $unitId,
            'type_id'               => $typeId,
            'user_office_id'        => $officeId,
            'entity_id'             => (new EntityModel())->defaultFor($officeId) ?: null,
            'stock_no'              => '',  // temporary — updated below
        ]);

        $newId   = (int) $this->db->insertID();
        $office  = $this->officeName($officeId);
        $stockNo = ($product['stock_no'] ?? '') !== ''
                       ? mb_substr((string) $product['stock_no'], 0, 100)
                       : strtoupper($office) . '-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT);

        $this->db->table('product_table')->where('product_id', $newId)->update([
            'product_no' => $stockNo,
            'stock_no'   => $stockNo,
        ]);

        return [$newId, true];
    }

    // ── Transaction writers ──────────────────────────────────────────────────

    /**
     * Insert a receipt (stock-in) transaction + its batch. Returns the new batch_id.
     */
    private function insertReceiptRow(
        int    $productId,
        int    $copyId,
        int    $officeId,
        int    $userId,
        array  $data,
        string $fingerprint
    ): int {
        $date   = $data['date'];
        $stamp  = $date . ' 08:00:00';
        $refId  = $this->referenceId($data['ref'] ?? '', $officeId);

        $officeName = $this->officeName($officeId);
        $baseBatchNo = 'B-' . strtoupper($officeName) . '-' . str_replace('-', '', $date)
            . '-' . str_pad((string) $productId, 4, '0', STR_PAD_LEFT);

        $inventoryService = new InventoryService($this->db);
        $batchNo          = $inventoryService->nextBatchNo($baseBatchNo);

        $this->db->table('batch_table')->insert([
            'batch_no'       => $batchNo,
            'product_id'     => $productId,
            'copy_id'        => $copyId,
            'user_office_id' => $officeId,
            'reference_id'   => $refId,
            'current_qty'    => round((float) $data['qty'], 4),
            'date_received'  => $date,
            'created_at'     => $stamp,
            'updated_at'     => $stamp,
        ]);
        $batchId = (int) $this->db->insertID();

        $barcodes = new BarcodeService();
        $barcode  = $barcodes->generateBatchValue($batchId);
        $barcodes->saveBatchBarcode($barcode);
        $this->db->table('batch_table')->where('batch_id', $batchId)->update(['barcode_value' => $barcode]);

        $this->db->table('transaction_table')->insert([
            'stock_import_fingerprint' => $fingerprint,
            'stock_import_batch'       => $this->runId,
            'transaction_type_id'      => $this->typeId('receipt'),
            'transaction_qty'          => round((float) $data['qty'], 4),
            'transaction_unit_cost'    => round((float) ($data['cost'] ?? 0), 4),
            'transaction_date'         => $stamp,
            'batch_id'                 => $batchId,
            'copy_id'                  => $copyId,
            'reference_id'             => $refId,
            'user_id'                  => $userId ?: null,
            'user_office_id'           => $officeId,
            'created_at'               => $stamp,
            'updated_at'               => $stamp,
        ]);

        return $batchId;
    }

    /**
     * Insert an issue or adjust_out transaction, taking the product's stock oldest first
     * (across all its prices, as the stock card does), one transaction per batch used.
     *
     * Only stock received on or before the transaction date counts. If that is not enough,
     * the row in the file doesn't add up: the import stops and nothing is written.
     */
    private function insertIssueRow(
        int    $productId,
        int    $officeId,
        int    $userId,
        array  $data,
        string $fingerprint,
        array  $product
    ): void {
        $stamp     = $data['date'] . ' 08:00:00';
        $refId     = $this->referenceId($data['ref'] ?? '', $officeId);
        $remaining = round((float) $data['qty'], 4);
        $part      = 0;

        foreach ($this->openBatches($productId, $officeId, $data['date']) as $batch) {
            if ($remaining <= 0.0001) {
                break;
            }

            $take      = min($batch['qty'], $remaining);
            $remaining = round($remaining - $take, 4);

            $this->db->table('batch_table')->where('batch_id', $batch['batch_id'])->update([
                'current_qty' => round($batch['qty'] - $take, 4),
                'updated_at'  => $stamp,
            ]);

            // Only the first part keeps the fingerprint; split parts have null
            $fp = $part++ === 0 ? $fingerprint : null;

            $this->db->table('transaction_table')->insert([
                'stock_import_fingerprint' => $fp,
                'stock_import_batch'       => $this->runId,
                'transaction_type_id'      => $this->typeId($data['type']),
                'transaction_qty'          => round($take, 4),
                'transaction_unit_cost'    => 0,
                'transaction_date'         => $stamp,
                'batch_id'                 => $batch['batch_id'],
                'copy_id'                  => $batch['copy_id'] ?: null,
                'reference_id'             => $refId,
                'adjustment_reason_id'     => $data['reason'] ?? null,
                'user_id'                  => $userId ?: null,
                'user_office_id'           => $officeId,
                'created_at'               => $stamp,
                'updated_at'               => $stamp,
            ]);
        }

        if ($remaining > 0.0001) {
            $what = $data['type'] === 'issue' ? 'Used/Sold' : 'Breakage';
            throw new DomainException(sprintf(
                '%s (sheet "%s", row %d): the %s quantity is %s more than the stock on hand. Nothing was imported; correct the row and try again.',
                $product['item'],
                $product['sheet'] ?? '',
                (int) ($product['row'] ?? 0),
                $what,
                rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.')
            ));
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The product's batches that still have stock on the given date, oldest received first.
     */
    private function openBatches(int $productId, int $officeId, string $onDate): array
    {
        $rows = $this->db->table('batch_table')
            ->select('batch_id, copy_id, current_qty')
            ->where('product_id', $productId)
            ->where('user_office_id', $officeId)
            ->where('current_qty >', 0)
            ->where('date_received <=', $onDate)
            ->orderBy('date_received', 'ASC')
            ->orderBy('batch_id', 'ASC')
            ->get()->getResultArray();

        return array_map(
            static fn ($r) => ['batch_id' => (int) $r['batch_id'], 'copy_id' => (int) $r['copy_id'], 'qty' => (float) $r['current_qty']],
            $rows
        );
    }

    private function adjustmentReasonId(string $name): ?int
    {
        $row = $this->db->table('adjustment_reason')
            ->select('adjustment_reason_id')
            ->where('adjustment_reason', $name)
            ->get(1)->getRowArray();

        return $row ? (int) $row['adjustment_reason_id'] : null;
    }

    private function referenceId(string $reference, int $officeId): ?int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        $key = $officeId . '|' . mb_strtolower($reference);
        if (! isset($this->referenceCache[$key])) {
            $row = $this->db->table('reference_table')
                ->select('reference_id')
                ->where('reference', $reference)
                ->where('user_office_id', $officeId)
                ->get(1)->getRowArray();
            if (! $row) {
                $this->db->table('reference_table')->insert([
                    'reference'      => mb_substr($reference, 0, 255),
                    'user_office_id' => $officeId,
                ]);
            }
            $this->referenceCache[$key] = $row ? (int) $row['reference_id'] : (int) $this->db->insertID();
        }
        return $this->referenceCache[$key];
    }

    private function officeName(int $officeId): string
    {
        return $this->officeNames[$officeId] ??= (string) (
            $this->db->table('user_office_table')
                ->where('user_office_id', $officeId)
                ->get(1)->getRowArray()['user_office_name'] ?? "Office#{$officeId}"
        );
    }

    private function typeId(string $name): int
    {
        static $ids = [];
        if (! isset($ids[$name])) {
            $row = $this->db->table('transaction_type_table')
                ->select('transaction_type_id')
                ->where('transaction_type', $name)
                ->get(1)->getRowArray();
            if (! $row) {
                throw new DomainException("Transaction type '{$name}' not found in the database.");
            }
            $ids[$name] = (int) $row['transaction_type_id'];
        }
        return $ids[$name];
    }

    private function fingerprint(array $product, string $entryType, int $officeId, string $month): string
    {
        return hash('sha256', json_encode([
            $officeId,
            mb_strtolower(trim($product['item'])),
            mb_strtolower(trim($product['unit'])),
            mb_strtolower(trim($product['type'])),
            $entryType,
            $month,
        ]));
    }

    private function alreadyImported(int $officeId, string $fingerprint): bool
    {
        return $this->db->table('transaction_table')
            ->where('user_office_id', $officeId)
            ->where('stock_import_fingerprint', $fingerprint)
            ->countAllResults() > 0;
    }

    // ── Wipe & count ────────────────────────────────────────────────────────

    private function wipeInventory(array $officeIds): array
    {
        $counts     = $this->inventoryCounts($officeIds);
        $productIds = array_map('intval', array_column(
            $this->db->table('product_table')
                ->select('product_id')
                ->whereIn('user_office_id', $officeIds)
                ->get()->getResultArray(),
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

    // ── Plan storage ─────────────────────────────────────────────────────────

    private function storePlan(string $token, array $data): void
    {
        $dir = WRITEPATH . 'imports' . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            throw new DomainException('Cannot create ' . $dir);
        }
        // Clean up expired plans
        foreach (glob($dir . 'sum_*.json') ?: [] as $old) {
            if (time() - filemtime($old) > self::PLAN_TTL) {
                @unlink($old);
            }
        }
        $json = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($dir . 'sum_' . $token . '.json', $json) === false) {
            throw new DomainException('Could not save the import preview.');
        }
    }

    private function loadStored(string $token, int $userId): ?array
    {
        $path = $this->planPath($token);
        if ($path === null || ! is_file($path)) {
            return null;
        }
        $stored = json_decode((string) file_get_contents($path), true);
        if (! is_array($stored)
            || (int) $stored['user_id'] !== $userId
            || time() - (int) $stored['created'] > self::PLAN_TTL
        ) {
            return null;
        }
        return $stored;
    }

    private function planPath(string $token): ?string
    {
        return preg_match('/^[a-f0-9]{32}$/', $token)
            ? WRITEPATH . 'imports' . DIRECTORY_SEPARATOR . 'sum_' . $token . '.json'
            : null;
    }
}
