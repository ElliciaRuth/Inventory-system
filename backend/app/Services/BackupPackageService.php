<?php

namespace App\Services;

use App\Libraries\BackupCrypto;
use App\Libraries\ZipFile;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;

/**
 * Backup packages: one .zip per office that people can open and check without IT help.
 *
 *   README.txt      what the package is and how to restore it, in plain words
 *   manifest.json   office, date, sections, row counts and a SHA-256 checksum of every file
 *   schema.sql      CREATE TABLE IF NOT EXISTS for every table, so it restores onto an empty server
 *   data.json       the records, table by table
 *   spreadsheets/   CSV copies that open in Excel, plus an "Inventory Summary"
 *   barcodes/       the batch barcode images
 *
 * With a password the whole zip is AES-256 encrypted (BackupCrypto). Everything runs
 * on this computer; nothing needs the internet.
 */
class BackupPackageService
{
    public const FORMAT  = 'bsu-inventory-backup';
    public const VERSION = 1;

    /** What a backup can contain; "requires" sections are always taken along */
    public const SECTIONS = [
        'setup' => [
            'title'       => 'Office Setup',
            'description' => 'Entities, units, product types, references and destination offices.',
            'tables'      => ['entity_table', 'unit_table', 'type_of_product', 'reference_table', 'office_table'],
            'requires'    => [],
        ],
        'inventory' => [
            'title'       => 'Inventory Records',
            'description' => 'Products, sub-products, stock batches, all transactions, stock-out requests, borrow records and batch barcode images.',
            'tables'      => ['product_table', 'product_copy_table', 'batch_table', 'borrow_table', 'transaction_table', 'temp_stockout', 'temp_stockout_item'],
            'requires'    => ['setup'],
        ],
        'users' => [
            'title'       => 'Users & Accounts',
            'description' => "This office's user accounts, roles and sign-in details (passwords stay encrypted).",
            'tables'      => ['user_table'],
            'requires'    => [],
        ],
        'settings' => [
            'title'       => 'System Settings',
            'description' => 'Expiry alert defaults and other system-wide settings.',
            'tables'      => ['system_settings'],
            'requires'    => [],
        ],
    ];

    /** Fixed lists every package carries, so it can restore onto an empty server */
    private const CORE_TABLES = ['user_office_table', 'level_of_access', 'user_activity_table', 'adjustment_reason', 'transaction_type_table'];

    /** Names for the spreadsheet copies */
    private const SHEET_NAMES = [
        'entity_table'       => 'Entities',
        'unit_table'         => 'Units',
        'type_of_product'    => 'Product Types',
        'reference_table'    => 'References',
        'office_table'       => 'Destination Offices',
        'product_table'      => 'Products',
        'product_copy_table' => 'Sub-products (price variants)',
        'batch_table'        => 'Stock Batches',
        'transaction_table'  => 'Transactions',
        'temp_stockout'      => 'Stock-out Requests',
        'temp_stockout_item' => 'Stock-out Request Items',
        'borrow_table'       => 'Borrowed Items',
        'user_table'         => 'Users',
        'system_settings'    => 'System Settings',
    ];

    /** Columns never written to the spreadsheets (still in data.json, which is needed to restore) */
    private const HIDDEN_CSV_COLUMNS = ['password', 'password_reset_token', 'password_reset_expires', 'stock_import_fingerprint'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * Build a package for one office.
     *
     * @param list<string> $sections section keys; empty means all
     * @return array{bytes: string, filename: string, manifest: array}
     */
    public function create(int $officeId, string $officeName, array $sections, ?string $password, string $createdBy): array
    {
        $sections = $this->withRequired($sections ?: array_keys(self::SECTIONS));
        $now      = date('Y-m-d H:i:s');

        $tables = self::CORE_TABLES;
        foreach ($sections as $key) {
            array_push($tables, ...self::SECTIONS[$key]['tables']);
        }

        $data = [];
        foreach ($tables as $table) {
            $data[$table] = $this->rowsFor($table, $officeId);
        }

        $zip   = new ZipFile();
        $files = [];
        $add   = static function (string $name, string $contents) use ($zip, &$files): void {
            $zip->add($name, $contents);
            $files[$name] = hash('sha256', $contents);
        };

        $add('README.txt', $this->readme($officeName, $sections, $now, $createdBy, $password !== null));
        $add('schema.sql', $this->schemaSql($tables));
        $add('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        foreach ($sections as $key) {
            foreach (self::SECTIONS[$key]['tables'] as $table) {
                $add('spreadsheets/' . self::SECTIONS[$key]['title'] . ' - ' . self::SHEET_NAMES[$table] . '.csv', $this->csv($data[$table]));
            }
        }

        $barcodes = 0;
        if (in_array('inventory', $sections, true)) {
            $add('spreadsheets/Inventory Summary.csv', $this->csv($this->inventorySummary($officeId)));
            foreach ($data['batch_table'] as $batch) {
                $file = $this->barcodeFile((string) ($batch['barcode_value'] ?? ''));
                if ($file !== null && is_file(FCPATH . 'barcodes' . DIRECTORY_SEPARATOR . $file)) {
                    $add('barcodes/' . $file, (string) file_get_contents(FCPATH . 'barcodes' . DIRECTORY_SEPARATOR . $file));
                    $barcodes++;
                }
            }
        }

        $manifest = [
            'format'     => self::FORMAT,
            'version'    => self::VERSION,
            'app'        => 'BSU Integrated Inventory Monitoring System',
            'created_at' => $now,
            'created_by' => $createdBy,
            'office'     => ['id' => $officeId, 'name' => $officeName],
            'sections'   => $sections,
            'rows'       => array_map('count', $data),
            'barcodes'   => $barcodes,
            'encrypted'  => $password !== null,
            'files'      => $files,
        ];
        $zip->add('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $bytes    = $zip->build();
        $safeName = preg_replace('/[^A-Za-z0-9]+/', '-', $officeName) ?: 'Office';
        $filename = 'BSU-Inventory_' . $safeName . '_' . date('Y-m-d_His');

        if ($password !== null) {
            return ['bytes' => BackupCrypto::encrypt($bytes, $password), 'filename' => $filename . '_protected.bsubackup', 'manifest' => $manifest];
        }

        return ['bytes' => $bytes, 'filename' => $filename . '.zip', 'manifest' => $manifest];
    }

    // ── Open & check ─────────────────────────────────────────────────────────

    /**
     * Unpack and verify a package. Throws a DomainException with code 401 when a password is needed.
     *
     * @return array{manifest: array, data: array, barcodes: array<string, string>, schema: string}
     */
    public function open(string $bytes, ?string $password): array
    {
        if (BackupCrypto::isEncrypted($bytes)) {
            if ($password === null || $password === '') {
                throw new DomainException('This backup is password-protected. Enter its password.', 401);
            }
            try {
                $bytes = BackupCrypto::decrypt($bytes, $password);
            } catch (RuntimeException $e) {
                throw new DomainException($e->getMessage(), 401);
            }
        }

        try {
            // Only known paths are read, so a crafted archive can't write elsewhere ("zip slip")
            $files = ZipFile::read($bytes, static fn (string $name) => self::isAllowedPath($name));
        } catch (RuntimeException) {
            throw new DomainException('This is not a BSU Inventory backup package (.zip or .bsubackup).');
        }

        $manifest = json_decode($files['manifest.json'] ?? '', true);
        if (! is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            throw new DomainException('This zip is not a BSU Inventory backup package (its manifest.json is missing).');
        }
        if ((int) ($manifest['version'] ?? 0) > self::VERSION) {
            throw new DomainException('This backup was made by a newer version of the system. Update the system first.');
        }

        // Every file must match the checksum recorded when the backup was made
        $damaged = [];
        foreach (($manifest['files'] ?? []) as $name => $hash) {
            if (! isset($files[$name]) || ! hash_equals((string) $hash, hash('sha256', $files[$name]))) {
                $damaged[] = $name;
            }
        }
        if ($damaged) {
            throw new DomainException('The backup is damaged or was edited; these files do not match: ' . implode(', ', array_slice($damaged, 0, 5)) . (count($damaged) > 5 ? '…' : '') . '. Nothing was restored.');
        }

        $data = json_decode($files['data.json'] ?? '', true);
        if (! is_array($data)) {
            throw new DomainException('The backup has no readable data.json.');
        }

        $barcodes = [];
        foreach ($files as $name => $contents) {
            if (str_starts_with($name, 'barcodes/')) {
                $barcodes[substr($name, 9)] = $contents;
            }
        }

        return ['manifest' => $manifest, 'data' => $data, 'barcodes' => $barcodes, 'schema' => $files['schema.sql'] ?? ''];
    }

    /**
     * What the restore screen shows before anything is changed.
     */
    public function inspect(string $bytes, ?string $password, int $officeId): array
    {
        $package  = $this->open($bytes, $password);
        $manifest = $package['manifest'];

        $sections = [];
        foreach (($manifest['sections'] ?? []) as $key) {
            if (! isset(self::SECTIONS[$key])) {
                continue;
            }
            $rows = [];
            foreach (self::SECTIONS[$key]['tables'] as $table) {
                $rows[self::SHEET_NAMES[$table]] = (int) ($manifest['rows'][$table] ?? 0);
            }
            $sections[] = [
                'key'         => $key,
                'title'       => self::SECTIONS[$key]['title'],
                'description' => self::SECTIONS[$key]['description'],
                'requires'    => self::SECTIONS[$key]['requires'],
                'rows'        => $rows,
            ];
        }

        return [
            'created_at'   => $manifest['created_at'] ?? '',
            'created_by'   => $manifest['created_by'] ?? '',
            'office'       => $manifest['office'] ?? [],
            'office_match' => (int) ($manifest['office']['id'] ?? 0) === $officeId,
            'encrypted'    => (bool) ($manifest['encrypted'] ?? false),
            'barcodes'     => (int) ($manifest['barcodes'] ?? 0),
            'files'        => count($manifest['files'] ?? []),
            'sections'     => $sections,
        ];
    }

    // ── Restore ──────────────────────────────────────────────────────────────

    /**
     * Restore chosen sections of a package into this office.
     *  - Office Setup, Users and Settings are merged: rows are updated or added, never deleted.
     *  - Inventory Records replace the office's products, stock, transactions and requests.
     *
     * @param list<string> $sections
     * @return array<string, int> rows restored per table
     */
    public function restore(string $bytes, ?string $password, array $sections, int $officeId, int $currentUserId, int $currentLevel = 3): array
    {
        $package  = $this->open($bytes, $password);
        $manifest = $package['manifest'];
        $data     = $package['data'];

        if ((int) ($manifest['office']['id'] ?? 0) !== $officeId) {
            throw new DomainException('This backup belongs to ' . ($manifest['office']['name'] ?? 'another office') . '. A backup can only be restored into its own office.');
        }

        $available = $manifest['sections'] ?? [];
        $sections  = array_values(array_intersect($this->withRequired($sections), $available));
        if ($currentLevel < 3) {
            // System settings are changed by managers only (Others Management)
            $sections = array_values(array_diff($sections, ['settings']));
        }
        if ($sections === []) {
            throw new DomainException('Choose at least one section that is in this backup.');
        }

        // The checksums in manifest.json only show the file wasn't damaged: whoever has the
        // file can recompute them. So every row is treated as untrusted input from here on.
        $schema = $this->safeSchemaStatements($package['schema']);

        // Foreign keys are checked again at the end; tables reference each other in any order
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // Missing tables are created first (DDL can't run inside a transaction in MySQL)
        foreach ($schema as $statement) {
            $this->db->query($statement);
        }

        $counts = [];
        $this->db->transBegin();

        try {
            foreach (self::CORE_TABLES as $table) {
                $counts[$table] = $this->insertMissing($table, $this->safeCoreRows($table, $data[$table] ?? []));
            }
            $data = $this->remapLookupIds($data);

            foreach ($sections as $key) {
                if ($key === 'inventory') {
                    $this->deleteInventory($officeId);
                    $kept = [];
                    foreach (self::SECTIONS['inventory']['tables'] as $table) {
                        $rows = $this->ownInventoryRows($table, $data[$table] ?? [], $officeId, $kept);
                        if ($table === 'batch_table') {
                            $rows = $this->uniqueBatchNumbers($rows);
                        }
                        $counts[$table] = $this->insertRows($table, $rows);
                    }
                    $this->regenerateBarcodes($data['batch_table'] ?? [], $officeId);
                    continue;
                }

                foreach (self::SECTIONS[$key]['tables'] as $table) {
                    $rows = $data[$table] ?? [];
                    if ($table === 'user_table') {
                        $rows = $this->restorableUsers($rows, $officeId, $currentUserId, $currentLevel);
                    } elseif ($key === 'setup') {
                        $rows = $this->ownSetupRows($table, $rows, $officeId);
                    }
                    $counts[$table] = $this->upsertRows($table, $rows);
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            // All or nothing: a failed restore leaves the data as it was
            $this->db->transRollback();
            throw $e;
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $counts;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<string> section keys in a stable order, including what they need
     */
    public function withRequired(array $sections): array
    {
        $wanted = [];
        foreach ($sections as $key) {
            if (isset(self::SECTIONS[$key])) {
                $wanted[$key] = true;
                foreach (self::SECTIONS[$key]['requires'] as $required) {
                    $wanted[$required] = true;
                }
            }
        }

        return array_values(array_filter(array_keys(self::SECTIONS), static fn ($k) => isset($wanted[$k])));
    }

    /**
     * Rows of one table that belong to the office.
     */
    private function rowsFor(string $table, int $officeId): array
    {
        $builder = $this->db->table($table);

        if (in_array($table, self::CORE_TABLES, true) || $table === 'system_settings') {
            return $builder->get()->getResultArray();
        }
        if ($table === 'temp_stockout_item') {
            $ids = $this->idsFor('temp_stockout', 'temp_stockout_id', $officeId);
            return $ids ? $builder->whereIn('temp_stockout_id', $ids)->get()->getResultArray() : [];
        }
        if ($table === 'product_copy_table') {
            $ids = $this->idsFor('product_table', 'product_id', $officeId);
            return $ids ? $builder->whereIn('product_id', $ids)->get()->getResultArray() : [];
        }

        return $builder->where('user_office_id', $officeId)->get()->getResultArray();
    }

    private function idsFor(string $table, string $pk, int $officeId): array
    {
        return array_map('intval', array_column(
            $this->db->table($table)->select($pk)->where('user_office_id', $officeId)->get()->getResultArray(),
            $pk
        ));
    }

    private function deleteInventory(int $officeId): void
    {
        $requestIds = $this->idsFor('temp_stockout', 'temp_stockout_id', $officeId);
        $productIds = $this->idsFor('product_table', 'product_id', $officeId);

        if ($requestIds) {
            $this->db->table('temp_stockout_item')->whereIn('temp_stockout_id', $requestIds)->delete();
        }
        $this->db->table('temp_stockout')->where('user_office_id', $officeId)->delete();
        $this->db->table('transaction_table')->where('user_office_id', $officeId)->delete();
        if ($this->db->tableExists('borrow_table')) {
            $this->db->table('borrow_table')->where('user_office_id', $officeId)->delete();
        }
        $this->db->table('batch_table')->where('user_office_id', $officeId)->delete();
        if ($productIds) {
            $this->db->table('product_copy_table')->whereIn('product_id', $productIds)->delete();
        }
        $this->db->table('product_table')->where('user_office_id', $officeId)->delete();
    }

    private function insertRows(string $table, array $rows): int
    {
        $rows = $this->fitColumns($table, $rows);
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->db->table($table)->insertBatch($chunk);
        }

        return count($rows);
    }

    private function insertMissing(string $table, array $rows): int
    {
        return $this->writeRows($table, $rows, 'INSERT IGNORE');
    }

    private function upsertRows(string $table, array $rows): int
    {
        return $this->writeRows($table, $rows, 'UPSERT');
    }

    /**
     * INSERT IGNORE (keep what exists) or INSERT … ON DUPLICATE KEY UPDATE (take the backup's values).
     */
    private function writeRows(string $table, array $rows, string $mode): int
    {
        $rows = $this->fitColumns($table, $rows);
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $colSql  = '`' . implode('`, `', $columns) . '`';
        $update  = implode(', ', array_map(static fn ($c) => "`{$c}` = VALUES(`{$c}`)", $columns));

        foreach (array_chunk($rows, 200) as $chunk) {
            $values = implode(', ', array_map(fn ($row) => '(' . implode(', ', array_map(
                fn ($v) => $v === null ? 'NULL' : $this->db->escape($v),
                array_values($row)
            )) . ')', $chunk));

            $sql = ($mode === 'INSERT IGNORE' ? 'INSERT IGNORE' : 'INSERT') . " INTO `{$table}` ({$colSql}) VALUES {$values}"
                . ($mode === 'UPSERT' ? " ON DUPLICATE KEY UPDATE {$update}" : '');
            $this->db->query($sql);
        }

        return count($rows);
    }

    /**
     * Keep only columns the table has now, so a backup from an older or newer version still restores.
     */
    private function fitColumns(string $table, array $rows): array
    {
        // Not the cached table list: schema.sql may have just created the table
        if ($rows === [] || ! $this->db->tableExists($table, false)) {
            return [];
        }

        $known = array_flip($this->db->getFieldNames($table));

        return array_map(static fn ($row) => array_intersect_key((array) $row, $known), $rows);
    }

    /**
     * Older backups may use duplicate role / status / reason rows that no longer exist.
     * Point their users and ledger entries at today's row with the same name.
     */
    private function remapLookupIds(array $data): array
    {
        $lookups = [
            // table => [pk, name column, [table => column referencing it]]
            'level_of_access'     => ['lvl_of_access_id', 'lvl_of_access', ['user_table' => 'lvl_of_access_id']],
            'user_activity_table' => ['user_activity_id', 'user_activity', ['user_table' => 'user_activity_id']],
            'adjustment_reason'   => ['adjustment_reason_id', 'adjustment_reason', ['transaction_table' => 'adjustment_reason_id']],
        ];

        foreach ($lookups as $table => [$pk, $nameColumn, $references]) {
            if (empty($data[$table]) || ! $this->db->tableExists($table)) {
                continue;
            }
            $current = [];
            foreach ($this->db->table($table)->get()->getResultArray() as $row) {
                $current[mb_strtolower(trim((string) $row[$nameColumn]))] ??= (int) $row[$pk];
            }
            $map = [];
            foreach ($data[$table] as $row) {
                $id = (int) ($row[$pk] ?? 0);
                $to = $current[mb_strtolower(trim((string) ($row[$nameColumn] ?? '')))] ?? null;
                if ($id > 0 && $to !== null && $to !== $id) {
                    $map[$id] = $to;
                }
            }
            if ($map === []) {
                continue;
            }
            foreach ($references as $refTable => $column) {
                foreach ($data[$refTable] ?? [] as $i => $row) {
                    $old = (int) ($row[$column] ?? 0);
                    if (isset($map[$old])) {
                        $data[$refTable][$i][$column] = $map[$old];
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Backups from before batch numbers were unique can repeat one; later copies get -02, -03…
     */
    private function uniqueBatchNumbers(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) ($row['batch_no'] ?? '')] = ($seen[(string) ($row['batch_no'] ?? '')] ?? 0) + 1;
        }
        $taken = array_fill_keys(array_keys($seen), true);
        $used  = [];

        foreach ($rows as &$row) {
            $no = (string) ($row['batch_no'] ?? '');
            if ($no === '') {
                $no = 'B-' . ($row['batch_id'] ?? count($used) + 1);
            }
            if (! isset($used[$no])) {
                $used[$no]      = true;
                $row['batch_no'] = $no;
                continue;
            }
            $seq = 1;
            do {
                $seq++;
                $candidate = $no . '-' . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
            } while (isset($taken[$candidate]) || isset($used[$candidate]));
            $used[$candidate] = true;
            $row['batch_no']  = $candidate;
        }
        unset($row);

        return $rows;
    }

    // ── Restore safety: a package is a file anyone could have edited ────────────

    /** Primary keys of the tables a restore writes to */
    private const PRIMARY_KEYS = [
        'entity_table' => 'entity_id', 'unit_table' => 'unit_id', 'type_of_product' => 'type_id',
        'reference_table' => 'reference_id', 'office_table' => 'office_id',
        'product_table' => 'product_id', 'product_copy_table' => 'copy_id', 'batch_table' => 'batch_id',
        'borrow_table' => 'borrow_id', 'transaction_table' => 'transaction_id',
        'temp_stockout' => 'temp_stockout_id', 'temp_stockout_item' => 'temp_stockout_item_id',
    ];

    /**
     * The CREATE TABLE statements of schema.sql that may run: only tables a package carries,
     * and none of the options that reach outside the database (files in other folders, links
     * to other servers, tables filled from queries).
     *
     * @return list<string>
     */
    private function safeSchemaStatements(string $schema): array
    {
        $known = self::CORE_TABLES;
        foreach (self::SECTIONS as $section) {
            array_push($known, ...$section['tables']);
        }

        $safe = [];
        foreach ($this->statements($schema) as $statement) {
            if (! preg_match('/^CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)` \(/i', $statement, $m)) {
                continue;
            }
            if (! in_array($m[1], $known, true)) {
                continue;
            }
            if (preg_match('/\b(DATA|INDEX)\s+DIRECTORY\b|\bCONNECTION\s*=|\bENGINE\s*=\s*(?!InnoDB\b|MyISAM\b|Aria\b)|\bSELECT\b|\bUNION\b|\bLIKE\b\s*`|;/i', $statement)) {
                throw new DomainException('The table definitions in this backup were changed (they contain options a BSU Inventory backup never uses). Nothing was restored.');
            }
            if (! $this->db->tableExists($m[1], false)) {
                $safe[] = $statement;
            }
        }

        return $safe;
    }

    /** Lookup rows for the core tables; access levels only 1–4. */
    private function safeCoreRows(string $table, array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if ($table === 'level_of_access') {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array((int) ($r['lvl_of_access'] ?? 0), [1, 2, 3, 4], true)));
        }

        return $rows;
    }

    /**
     * Office Setup rows: this office's only, and never an id that another office's record has
     * (an upsert would otherwise rewrite that record and move it into this office).
     */
    private function ownSetupRows(string $table, array $rows, int $officeId): array
    {
        $pk   = self::PRIMARY_KEYS[$table];
        $rows = array_values(array_filter($rows, static fn ($r) => is_array($r) && (int) ($r['user_office_id'] ?? 0) === $officeId));

        $foreign = $this->existingIds($table, $pk, array_column($rows, $pk), static fn ($b) => $b->where('user_office_id !=', $officeId));

        return array_values(array_filter($rows, static fn ($r) => ! isset($foreign[(int) ($r[$pk] ?? 0)])));
    }

    /**
     * Inventory rows to insert after this office's inventory was deleted: rows of this office whose
     * ids are free (an id still in use belongs to another office). Request lines must belong to a
     * request that was kept. $kept collects the ids kept per table.
     */
    private function ownInventoryRows(string $table, array $rows, int $officeId, array &$kept): array
    {
        $pk   = self::PRIMARY_KEYS[$table];
        $rows = array_values(array_filter($rows, 'is_array'));

        if ($table === 'temp_stockout_item') {
            $requests = array_flip($kept['temp_stockout'] ?? []);
            $rows     = array_values(array_filter($rows, static fn ($r) => isset($requests[(int) ($r['temp_stockout_id'] ?? 0)])));
        } else {
            $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['user_office_id'] ?? 0) === $officeId));
        }

        $taken = $this->existingIds($table, $pk, array_column($rows, $pk));
        $rows  = array_values(array_filter($rows, static fn ($r) => ! isset($taken[(int) ($r[$pk] ?? 0)])));

        $kept[$table] = array_map('intval', array_column($rows, $pk));

        return $rows;
    }

    /**
     * Accounts a restore may write: this office's accounts at or below the restoring user's own
     * level (never Technical Staff), not the restoring user, and never one that would land on
     * another person's account (same id, username or email in another office or a higher role).
     */
    private function restorableUsers(array $rows, int $officeId, int $currentUserId, int $currentLevel): array
    {
        $maxLevel = min($currentLevel, 3);
        $levels   = array_column($this->db->table('level_of_access')->get()->getResultArray(), 'lvl_of_access', 'lvl_of_access_id');

        $existing = [];
        $byName   = [];
        $byEmail  = [];
        $current  = $this->db->table('user_table u')
            ->select('u.user_id, u.username, u.email, u.user_office_id, COALESCE(loa.lvl_of_access, 0) AS level_id', false)
            ->join('level_of_access loa', 'loa.lvl_of_access_id = u.lvl_of_access_id', 'left')
            ->get()->getResultArray();
        foreach ($current as $user) {
            $existing[(int) $user['user_id']]                = $user;
            $byName[mb_strtolower((string) $user['username'])] = (int) $user['user_id'];
            if ((string) $user['email'] !== '') {
                $byEmail[mb_strtolower((string) $user['email'])] = (int) $user['user_id'];
            }
        }

        $allowed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id    = (int) ($row['user_id'] ?? 0);
            $level = (int) ($levels[(int) ($row['lvl_of_access_id'] ?? 0)] ?? 0);

            if ($id <= 0 || $id === $currentUserId || (int) ($row['user_office_id'] ?? 0) !== $officeId || $level < 1 || $level > $maxLevel) {
                continue;
            }
            if (isset($existing[$id]) && ((int) $existing[$id]['user_office_id'] !== $officeId || (int) $existing[$id]['level_id'] > $maxLevel)) {
                continue;
            }
            $nameOwner  = $byName[mb_strtolower((string) ($row['username'] ?? ''))] ?? $id;
            $emailOwner = (string) ($row['email'] ?? '') !== '' ? ($byEmail[mb_strtolower((string) $row['email'])] ?? $id) : $id;
            if ($nameOwner !== $id || $emailOwner !== $id) {
                continue;
            }

            // Reset codes from the backup are never valid again
            $row['password_reset_token']   = null;
            $row['password_reset_expires'] = null;
            $allowed[]                     = $row;
        }

        return $allowed;
    }

    /**
     * Ids of $table among $ids that already exist (optionally narrowed by $scope), as a set.
     *
     * @return array<int, true>
     */
    private function existingIds(string $table, string $pk, array $ids, ?callable $scope = null): array
    {
        $ids   = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $found = [];
        if ($ids === [] || ! $this->db->tableExists($table, false)) {
            return $found;
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            $builder = $this->db->table($table)->select($pk)->whereIn($pk, $chunk);
            if ($scope !== null) {
                $scope($builder);
            }
            foreach ($builder->get()->getResultArray() as $row) {
                $found[(int) $row[$pk]] = true;
            }
        }

        return $found;
    }

    /**
     * Barcode images are drawn again from the restored batches' values. The SVGs inside the
     * package are not copied: an edited SVG with a script in it would run in the browser of
     * whoever opens it, with that person's session.
     */
    private function regenerateBarcodes(array $batches, int $officeId): void
    {
        $service = new BarcodeService();
        foreach ($batches as $batch) {
            $value = is_array($batch) ? trim((string) ($batch['barcode_value'] ?? '')) : '';
            if ($value === '' || (int) ($batch['user_office_id'] ?? 0) !== $officeId) {
                continue;
            }
            try {
                $service->saveBatchBarcode($value);
            } catch (\Throwable $e) {
                log_message('warning', "Barcode for restored batch value {$value} could not be drawn: " . $e->getMessage());
            }
        }
    }

    private function barcodeFile(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : preg_replace('/[^A-Za-z0-9\-_]/', '_', $value) . '.svg';
    }

    private static function isAllowedPath(string $name): bool
    {
        if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }

        return in_array($name, ['README.txt', 'manifest.json', 'schema.sql', 'data.json'], true)
            || preg_match('#^spreadsheets/[^/]+\.csv$#', $name)
            || preg_match('#^barcodes/[A-Za-z0-9_\-]+\.svg$#', $name);
    }

    private function schemaSql(array $tables): string
    {
        $out = "-- BSU Inventory backup: table definitions.\n-- Only creates tables that are missing; existing tables and data are not touched.\n\n";
        foreach ($tables as $table) {
            $row = $this->db->query('SHOW CREATE TABLE `' . $table . '`')->getRowArray();
            $ddl = (string) ($row['Create Table'] ?? '');
            $ddl = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $ddl);
            $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $ddl);
            $out .= $ddl . ";\n\n";
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(";\n", $sql))));
    }

    private function inventorySummary(int $officeId): array
    {
        return $this->db->query(
            'SELECT MAX(p.product_no) AS `Product No.`, MAX(p.product) AS `Product`, MAX(p.stock_no) AS `Stock No.`,
                    COALESCE(MAX(t.type), "") AS `Type`, COALESCE(MAX(u.unit), "") AS `Unit`,
                    COALESCE(MAX(p.measurement), "") AS `Measurement`,
                    ROUND(COALESCE(SUM(b.current_qty), 0), 2) AS `Stock on Hand`,
                    MAX(p.product_reorder_point) AS `Re-order Point`
             FROM product_table p
             LEFT JOIN type_of_product t ON t.type_id = p.type_id
             LEFT JOIN unit_table u ON u.unit_id = p.unit_id
             LEFT JOIN batch_table b ON b.product_id = p.product_id
             WHERE p.user_office_id = ?
             GROUP BY p.product_id
             ORDER BY MAX(p.product_no)',
            [$officeId]
        )->getResultArray();
    }

    /**
     * CSV that Excel opens correctly (UTF-8 with BOM).
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        if ($rows === []) {
            fputcsv($handle, ['(no records)'], ',', '"', '');
        } else {
            $columns = array_values(array_diff(array_keys($rows[0]), self::HIDDEN_CSV_COLUMNS));
            fputcsv($handle, $columns, ',', '"', '');
            foreach ($rows as $row) {
                // Text Excel would run as a formula (=, +, -, @ …) gets a leading apostrophe
                fputcsv($handle, array_map(static function ($c) use ($row) {
                    $value = $row[$c] ?? '';

                    return is_string($value) && $value !== '' && ! is_numeric($value) && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
                }, $columns), ',', '"', '');
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function readme(string $officeName, array $sections, string $now, string $createdBy, bool $encrypted): string
    {
        $list = implode("\n", array_map(static fn ($k) => '  - ' . self::SECTIONS[$k]['title'] . ': ' . self::SECTIONS[$k]['description'], $sections));

        return <<<TXT
BSU INTEGRATED INVENTORY - BACKUP PACKAGE
=========================================

Office     : {$officeName}
Made on    : {$now}
Made by    : {$createdBy}

WHAT IS INSIDE
{$list}

FILES
  README.txt      This note.
  manifest.json   A list of everything in the package with a checksum of each file.
                  The system uses it to make sure nothing was damaged or changed.
  schema.sql      The table layouts. Lets the backup be restored even onto a new,
                  empty computer.
  data.json       The records themselves (used when restoring).
  spreadsheets\\   The same records as CSV files you can open in Excel to look things
                  up. Editing them does not change the system.
  barcodes\\       The batch barcode pictures.

HOW TO KEEP IT SAFE
  Copy this file to a USB drive or another computer every now and then.
  Do not rename or edit the files inside; the system will refuse a changed backup.

HOW TO RESTORE
  Sign in as a Manager -> Menu -> Others Management -> Data Backup & Restore
  -> "Restore" tab -> choose this file -> pick what to restore -> Restore.
  A safety backup of the current data is made automatically first.

TXT
            . ($encrypted ? "\nThis package is password-protected. You need the password to restore it.\n" : '');
    }
}
