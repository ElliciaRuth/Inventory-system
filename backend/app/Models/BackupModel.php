<?php

namespace App\Models;

use App\Services\BackupPackageService;
use CodeIgniter\Database\BaseConnection;

class BackupModel
{
    private BaseConnection $db;
    private int $maxSlots = 10;

    public function __construct()
    {
        $this->db = db_connect();
    }

    // ─────────────────────────────────────────────────────────
    //  Config helpers
    // ─────────────────────────────────────────────────────────

    public function getConfigPath(): string
    {
        return WRITEPATH . 'backups/backup_config.json';
    }

    /**
     * The file holds each office's own settings under "offices" (keyed by office id). The
     * top-level values are the defaults for offices that haven't saved their own, including
     * everything saved before settings were per office.
     */
    private function readConfigFile(): array
    {
        $path = $this->getConfigPath();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Backup settings for one office: its own if it saved any, otherwise the shared defaults.
     */
    public function getConfig(int $officeId): array
    {
        $file    = $this->readConfigFile();
        $offices = is_array($file['offices'] ?? null) ? $file['offices'] : [];
        unset($file['offices']);
        $own = is_array($offices[(string) $officeId] ?? null) ? $offices[(string) $officeId] : [];

        $config = array_merge([
            'backup_dir'            => WRITEPATH . 'backups/',
            'backup_dir_2'          => '',
            'backup_interval_hours' => 24,
            'backup_time'           => '00:00',
        ], $file, $own);

        // Relative directories are relative to the backend root, so the
        // same config works on Windows (XAMPP) and on the Linux server.
        foreach (['backup_dir', 'backup_dir_2'] as $key) {
            $dir = trim((string) ($config[$key] ?? ''));
            if ($dir !== '' && ! str_contains($dir, ':') && ! str_starts_with($dir, '/')) {
                $config[$key] = ROOTPATH . ltrim($dir, '/\\');
            }
        }
        if (trim((string) ($config['backup_dir'] ?? '')) === '') {
            $config['backup_dir'] = WRITEPATH . 'backups/';
        }

        // A folder saved before the location rules existed (or edited by hand) that the
        // web server would publish: fall back to the protected default instead
        if (self::unsafeDirectory((string) $config['backup_dir']) !== null) {
            log_message('warning', 'Backup folder ' . $config['backup_dir'] . ' is not allowed; using writable/backups instead.');
            $config['backup_dir'] = WRITEPATH . 'backups/';
        }
        if (trim((string) ($config['backup_dir_2'] ?? '')) !== '' && self::unsafeDirectory((string) $config['backup_dir_2']) !== null) {
            log_message('warning', 'Backup folder (Drive 2) ' . $config['backup_dir_2'] . ' is not allowed; Drive 2 is skipped.');
            $config['backup_dir_2'] = '';
        }

        return $config;
    }

    /**
     * Backups hold every record of an office, including password hashes. They must not land
     * where a web server hands files out (backend/public, the built frontend, XAMPP's htdocs),
     * nor among the application files. backend/writable is fine: the web server refuses it.
     * Null when the absolute folder is acceptable, else the reason. The folder need not exist yet.
     */
    public static function unsafeDirectory(string $absolutePath): ?string
    {
        $normalize = static function (string $path): string {
            $path = rtrim(str_replace('\\', '/', $path), '/') . '/';

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        };

        // Resolve the deepest part that exists (symlinks, "..") and keep the rest as written
        $path = rtrim($absolutePath, '/\\');
        $rest = '';
        while ($path !== '' && ! is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $rest = '/' . basename($path) . $rest;
            $path = $parent;
        }
        $base = realpath($path);
        if ($base === false || str_contains($rest, '..')) {
            return 'That backup folder cannot be used.';
        }
        $real = $normalize($base . $rest);

        if (str_starts_with($real, $normalize((string) realpath(WRITEPATH)))) {
            return null;
        }

        $refused = [FCPATH, ROOTPATH, ROOTPATH . '..' . DIRECTORY_SEPARATOR . 'frontend', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')];
        foreach ($refused as $folder) {
            $folderReal = $folder !== '' ? realpath($folder) : false;
            if ($folderReal !== false && str_starts_with($real, $normalize($folderReal))) {
                return 'Backups cannot be saved inside the system\'s own folders or a folder the web server publishes. Use backend/writable/backups or a folder on another drive.';
            }
        }
        if (preg_match('#/(htdocs|public_html|wwwroot)/#', $real)) {
            return 'Backups cannot be saved inside a web server folder (htdocs, public_html, wwwroot). Use a folder on another drive.';
        }

        return null;
    }

    /**
     * Saves one office's settings; other offices' settings and the defaults are left alone.
     */
    public function saveConfig(int $officeId, array $config): void
    {
        $path = $this->getConfigPath();
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $this->readConfigFile();
        if (! is_array($file['offices'] ?? null)) {
            $file['offices'] = [];
        }
        $file['offices'][(string) $officeId] = $config;

        file_put_contents($path, json_encode($file, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Return the office subdirectory for a given base dir.
     */
    private function officeSubDir(string $base, int $officeId): string
    {
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'office_' . $officeId . DIRECTORY_SEPARATOR;
    }

    public function getBackupDir(int $officeId): string
    {
        $config = $this->getConfig($officeId);
        return $this->officeSubDir($config['backup_dir'] ?? WRITEPATH . 'backups/', $officeId);
    }

    public function getBackupDir2(int $officeId): string
    {
        $config = $this->getConfig($officeId);
        $dir2   = trim($config['backup_dir_2'] ?? '');
        if ($dir2 === '') return '';
        return $this->officeSubDir($dir2, $officeId);
    }

    // ─────────────────────────────────────────────────────────
    //  Backup list
    // ─────────────────────────────────────────────────────────

    public function getBackups(int $officeId): array
    {
        return $this->db->table('backup_log')
            ->where('user_office_id', $officeId)
            ->orderBy('backup_slot', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function countBackups(int $officeId): int
    {
        return (int) $this->db->table('backup_log')
            ->where('user_office_id', $officeId)
            ->countAllResults();
    }

    public function getOldest(int $officeId): ?array
    {
        $row = $this->db->table('backup_log')
            ->where('user_office_id', $officeId)
            ->orderBy('backup_slot', 'ASC')
            ->limit(1)
            ->get()
            ->getRowArray();
        return $row ?: null;
    }

    public function getNewest(int $officeId): ?array
    {
        $row = $this->db->table('backup_log')
            ->where('user_office_id', $officeId)
            ->orderBy('backup_slot', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();
        return $row ?: null;
    }

    public function getById(int $backupId): ?array
    {
        $row = $this->db->table('backup_log')
            ->where('backup_id', $backupId)
            ->get()
            ->getRowArray();
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────
    //  Core: create a backup (dual-drive)
    // ─────────────────────────────────────────────────────────

    /**
     * Create a new backup package for the given office (see BackupPackageService).
     * Writes to Drive 1 (required) and Drive 2 (optional mirror); keeps the newest slots.
     *
     * @param list<string> $sections empty = everything
     * @param string|null  $password password-protect the package
     */
    public function createBackup(int $officeId, int $userId, string $officeName, string $createdByName, array $sections = [], ?string $password = null): array
    {
        // ── Drive 1 directory ──
        $dir1 = $this->getBackupDir($officeId);
        if (! is_dir($dir1) && ! mkdir($dir1, 0775, true)) {
            return ['ok' => false, 'message' => 'Cannot create backup directory (Drive 1): ' . $dir1];
        }

        // ── Drive 2 directory (optional) ──
        $dir2    = $this->getBackupDir2($officeId);
        $hasDr2  = $dir2 !== '';
        if ($hasDr2 && ! is_dir($dir2) && ! mkdir($dir2, 0775, true)) {
            // Non-fatal: log the warning but continue with Drive 1 only
            $hasDr2 = false;
        }

        $count   = $this->countBackups($officeId);
        $newSlot = $count + 1;

        // A complete, self-contained package each time (no longer appended to the previous file)
        try {
            $package = (new BackupPackageService($this->db))->create($officeId, $officeName, $sections, $password, $createdByName);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
        }

        $filename    = $package['filename'];
        $fullContent = $package['bytes'];

        // ── Write to Drive 1 (required) ──
        $filepath1 = $dir1 . $filename;
        if (file_put_contents($filepath1, $fullContent) === false) {
            return ['ok' => false, 'message' => 'Cannot write backup file (Drive 1): ' . $filepath1];
        }

        // ── Write to Drive 2 (mirror, optional) ──
        $filepath2  = '';
        $drive2ok   = 0;
        if ($hasDr2) {
            $filepath2 = $dir2 . $filename;
            $drive2ok  = (file_put_contents($filepath2, $fullContent) !== false) ? 1 : 0;
        }

        // ── Slot rotation (ONLY after both writes succeed) ──
        if ($count >= $this->maxSlots) {
            $oldest = $this->getOldest($officeId);
            if ($oldest) {
                if (! empty($oldest['backup_filepath']) && is_file($oldest['backup_filepath'])) {
                    @unlink($oldest['backup_filepath']);
                }
                if (! empty($oldest['backup_filepath_2']) && is_file($oldest['backup_filepath_2'])) {
                    @unlink($oldest['backup_filepath_2']);
                }
                $this->db->table('backup_log')
                    ->where('backup_id', $oldest['backup_id'])
                    ->delete();
            }
            $this->db->query(
                'UPDATE backup_log SET backup_slot = backup_slot - 1 WHERE user_office_id = ?',
                [$officeId]
            );
            $newSlot = $this->maxSlots;
        }

        // ── Insert log record ──
        $this->db->table('backup_log')->insert([
            'backup_slot'       => $newSlot,
            'backup_filename'   => $filename,
            'backup_filepath'   => $filepath1,
            'backup_filepath_2' => $filepath2,
            'backup_format'     => 'package',
            'sections'          => implode(',', $package['manifest']['sections']),
            'encrypted'         => $password !== null ? 1 : 0,
            'drive2_ok'         => $drive2ok,
            'user_office_id'    => $officeId,
            'office_name'       => $officeName,
            'created_by'        => $userId,
            'created_by_name'   => $createdByName,
            'created_at'        => date('Y-m-d H:i:s'),
            'file_size_bytes'   => filesize($filepath1),
        ]);

        $driveMsg = $hasDr2
            ? ($drive2ok ? ' Mirrored to Drive 2.' : ' Warning: Drive 2 write failed — Drive 1 OK.')
            : '';

        return [
            'ok'        => true,
            'message'   => 'Backup created successfully.' . $driveMsg,
            'filename'  => $filename,
            'slot'      => $newSlot,
            'drive2'    => $drive2ok,
            'backup_id' => (int) $this->db->insertID(),
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  Restore
    // ─────────────────────────────────────────────────────────

    public function restoreFromFile(string $filepath): array
    {
        if (! is_file($filepath)) {
            return ['ok' => false, 'message' => 'File not found: ' . $filepath];
        }
        $sql = file_get_contents($filepath);
        if ($sql === false) {
            return ['ok' => false, 'message' => 'Cannot read file.'];
        }
        return $this->executeSql($sql);
    }

    public function restoreFromSqlString(string $sql): array
    {
        return $this->executeSql($sql);
    }

    /** Tables the older .sql backups wrote; nothing else may be touched by a restore. */
    private const LEGACY_TABLES = [
        'adjustment_reason', 'batch_table', 'entity_table', 'level_of_access', 'office_table', 'product_table',
        'reference_table', 'temp_stockout_item', 'temp_stockout', 'transaction_table', 'transaction_type_table',
        'type_of_product', 'unit_table', 'user_activity_table', 'user_office_table', 'user_table',
    ];

    /**
     * Statements of an older .sql backup, split on the semicolons outside quoted values, or a
     * string saying why the file is refused. Only the shapes the old backup writer produced are
     * accepted (its SET lines, DELETE FROM / INSERT INTO a known table with literal values), so
     * an edited file can't run other SQL (DROP, GRANT, SELECT … INTO OUTFILE, LOAD_FILE …).
     *
     * @return list<string>|string
     */
    private function legacyStatements(string $sql): array|string
    {
        $statements = [];
        $current    = '';
        $quote      = null;
        $length     = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $ch = $sql[$i];
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '-' && ($sql[$i + 1] ?? '') === '-' && trim($current) === '') {
                $end = strpos($sql, "\n", $i);
                $i   = $end === false ? $length : $end;
                continue;
            } elseif ($ch === ';') {
                $statements[] = trim($current);
                $current      = '';
                continue;
            }
            $current .= $ch;
        }
        if ($quote !== null) {
            return 'The backup file is damaged (an unfinished quoted value).';
        }
        $statements[] = trim($current);
        $statements   = array_values(array_filter($statements, static fn ($s) => $s !== ''));

        $tables = implode('|', self::LEGACY_TABLES);
        $value  = "(?:NULL|-?\\d+(?:\\.\\d+)?|'(?:[^'\\\\]|\\\\.|'')*')";
        $column = '`[A-Za-z0-9_]+`';
        $patterns = [
            '/^SET FOREIGN_KEY_CHECKS = [01]$/',
            '/^SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"$/',
            '/^SET time_zone = "\+00:00"$/',
            "/^DELETE FROM `(?:{$tables})`(?: WHERE `user_office_id` = \\d+)?$/",
            "/^INSERT INTO `(?:{$tables})` \\({$column}(?:, {$column})*\\) VALUES \\({$value}(?:, {$value})*\\)"
                . "(?: ON DUPLICATE KEY UPDATE {$column} = VALUES\\({$column}\\)(?:, {$column} = VALUES\\({$column}\\))*)?$/s",
        ];

        foreach ($statements as $n => $statement) {
            $allowed = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $allowed = true;
                    break;
                }
            }
            if (! $allowed) {
                log_message('warning', 'Legacy restore refused statement #' . ($n + 1) . ': ' . mb_substr($statement, 0, 200));

                return 'The backup file contains a statement that is not part of a BSU Inventory backup (statement ' . ($n + 1) . '), so nothing was restored.';
            }
        }

        return $statements;
    }

    private function executeSql(string $sql): array
    {
        $statements = $this->legacyStatements($sql);
        if (is_string($statements)) {
            return ['ok' => false, 'message' => $statements];
        }

        $credentials = $this->currentCredentials();

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($statements as $statement) {
                if (trim($statement) === '') continue;
                $this->db->query($statement);
            }
        } catch (\Throwable $e) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            $this->keepCurrentPasswords($credentials);
            log_message('error', 'Legacy .sql restore failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Restore failed part-way; the safety backup made first holds the data as it was. The details were written to the server log.'];
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->keepCurrentPasswords($credentials);

        return ['ok' => true, 'message' => 'Restore completed successfully. Everyone keeps their current password.'];
    }

    /**
     * Sign-in details of every account as they are now, taken before an older .sql backup
     * overwrites user_table.
     *
     * @return array<int, array<string, mixed>> by user id
     */
    private function currentCredentials(): array
    {
        if (! $this->db->tableExists('user_table')) {
            return [];
        }

        $rows = $this->db->table('user_table')
            ->select('user_id, password, must_change_password, password_reset_token, password_reset_expires')
            ->get()->getResultArray();

        return array_column($rows, null, 'user_id');
    }

    /**
     * An older .sql backup holds the password hashes from when it was made, and the file is
     * neither encrypted nor tamper-evident. After restoring one, accounts that existed before
     * get their current password back (a changed or reset password is never rolled back), and
     * accounts the backup brought back get no usable password: their owners set a new one
     * (Forgot Password, or a manager in User Management).
     */
    private function keepCurrentPasswords(array $credentials): void
    {
        if (! $this->db->tableExists('user_table')) {
            return;
        }

        $ids = array_map('intval', array_column($this->db->table('user_table')->select('user_id')->get()->getResultArray(), 'user_id'));
        foreach ($ids as $id) {
            $update = $credentials[$id] ?? [
                'password'               => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'must_change_password'   => 1,
                'password_reset_token'   => null,
                'password_reset_expires' => null,
            ];
            unset($update['user_id']);
            $this->db->table('user_table')->where('user_id', $id)->update($update);
        }
    }

    // ─────────────────────────────────────────────────────────
    //  Auto-backup check
    // ─────────────────────────────────────────────────────────

    /**
     * Whether an automatic backup is due for this office, based on the
     * configured interval: 0 = manual only; 24h or more = once per period,
     * at or after backup_time; shorter intervals = every N hours.
     */
    public function needsAutoBackup(int $officeId): bool
    {
        $config        = $this->getConfig($officeId);
        $intervalHours = (int) ($config['backup_interval_hours'] ?? 24);
        if ($intervalHours <= 0) {
            return false;
        }

        $last = $this->db->table('backup_log')
            ->selectMax('created_at')
            ->where('user_office_id', $officeId)
            ->get()
            ->getRowArray()['created_at'] ?? null;
        $lastTs = $last ? strtotime((string) $last) : 0;

        if ($intervalHours >= 24) {
            [$hour, $minute] = array_map('intval', explode(':', (string) ($config['backup_time'] ?? '00:00')) + [0, 0]);
            $scheduledToday  = mktime($hour, $minute, 0);
            if (time() < $scheduledToday) {
                return false;
            }
            $days = intdiv($intervalHours, 24);
            // Due when the last backup is older than the most recent scheduled run
            return $lastTs < $scheduledToday - ($days - 1) * 86400;
        }

        return time() - $lastTs >= $intervalHours * 3600;
    }
}
