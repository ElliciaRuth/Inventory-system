<?php

namespace App\Models;

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

    public function getConfig(): array
    {
        $path = $this->getConfigPath();
        if (is_file($path)) {
            $decoded = json_decode(file_get_contents($path), true);
            if (is_array($decoded)) {
                // Relative directories are relative to the backend root, so the
                // same config works on Windows (XAMPP) and on the Linux server.
                foreach (['backup_dir', 'backup_dir_2'] as $key) {
                    $dir = trim((string) ($decoded[$key] ?? ''));
                    if ($dir !== '' && ! str_contains($dir, ':') && ! str_starts_with($dir, '/')) {
                        $decoded[$key] = ROOTPATH . ltrim($dir, '/\\');
                    }
                }
                if (trim((string) ($decoded['backup_dir'] ?? '')) === '') {
                    $decoded['backup_dir'] = WRITEPATH . 'backups/';
                }

                return $decoded;
            }
        }
        return [
            'backup_dir'            => WRITEPATH . 'backups/',
            'backup_dir_2'          => '',
            'backup_interval_hours' => 24,
            'backup_time'           => '00:00',
        ];
    }

    public function saveConfig(array $config): void
    {
        $path = $this->getConfigPath();
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));
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
        $config = $this->getConfig();
        return $this->officeSubDir($config['backup_dir'] ?? WRITEPATH . 'backups/', $officeId);
    }

    public function getBackupDir2(int $officeId): string
    {
        $config = $this->getConfig();
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
     * Create a new backup for the given office.
     * Writes to Drive 1 (required) and Drive 2 (optional mirror).
     * Cumulative: new file = previous file content + new SQL dump.
     */
    public function createBackup(int $officeId, int $userId, string $officeName, string $createdByName): array
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
        $newest  = $this->getNewest($officeId);
        $newSlot = $count + 1;

        // Generate fresh SQL dump
        try {
            $newDump = $this->generateDump($officeId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Dump failed: ' . $e->getMessage()];
        }

        // Cumulative: prepend previous backup content
        $previousContent = '';
        if ($newest && is_file($newest['backup_filepath'])) {
            $previousContent = file_get_contents($newest['backup_filepath']);
        } elseif ($newest && $hasDr2 && ! empty($newest['backup_filepath_2']) && is_file($newest['backup_filepath_2'])) {
            // Fallback to drive 2 if drive 1 file is missing
            $previousContent = file_get_contents($newest['backup_filepath_2']);
        }

        $timestamp   = date('Ymd_His');
        $filename    = "backup_{$timestamp}_office{$officeId}.sql";
        $separator   = $previousContent
            ? "\n\n-- ==================================================\n"
              . "-- Backup appended: {$timestamp}\n"
              . "-- ==================================================\n\n"
            : '';
        $fullContent = $previousContent . $separator . $newDump;

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
            'ok'       => true,
            'message'  => 'Backup created successfully.' . $driveMsg,
            'filename' => $filename,
            'slot'     => $newSlot,
            'drive2'   => $drive2ok,
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  SQL dump generator (pure PHP, office-scoped)
    // ─────────────────────────────────────────────────────────

    private function generateDump(int $officeId): string
    {
        $lines = [];
        $lines[] = '-- ================================================';
        $lines[] = '-- BSU Inventory Backup';
        $lines[] = '-- Office ID : ' . $officeId;
        $lines[] = '-- Generated : ' . date('Y-m-d H:i:s');
        $lines[] = '-- ================================================';
        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
        $lines[] = 'SET time_zone = "+00:00";';
        $lines[] = '';

        // Shared / reference tables (full dump, no office filter)
        $sharedTables = [
            'user_office_table'      => 'user_office_id',
            'level_of_access'        => 'lvl_of_access_id',
            'user_activity_table'    => 'user_activity_id',
            'adjustment_reason'      => 'adjustment_reason_id',
            'transaction_type_table' => 'transaction_type_id',
        ];

        foreach ($sharedTables as $table => $pk) {
            $lines[] = $this->dumpTable($table, $pk, null);
        }

        // Office-scoped tables
        $officeTables = [
            'entity_table'       => 'entity_id',
            'unit_table'         => 'unit_id',
            'type_of_product'    => 'type_id',
            'reference_table'    => 'reference_id',
            'office_table'       => 'office_id',
            'user_table'         => 'user_id',
            'product_table'      => 'product_id',
            'batch_table'        => 'batch_id',
            'transaction_table'  => 'transaction_id',
            'temp_stockout'      => 'temp_stockout_id',
            'temp_stockout_item' => 'temp_stockout_item_id',
        ];

        foreach ($officeTables as $table => $pk) {
            $lines[] = $this->dumpTable($table, $pk, $officeId);
        }

        $lines[] = '';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function dumpTable(string $table, string $pk, ?int $officeId): string
    {
        $lines   = [];
        $lines[] = "-- -- Table: `{$table}` --";
        $lines[] = "DELETE FROM `{$table}`" . ($officeId ? " WHERE `user_office_id` = {$officeId}" : '') . ';';

        $builder = $this->db->table($table)->orderBy($pk, 'ASC');
        if ($officeId !== null && $table !== 'temp_stockout_item') {
            $builder->where('user_office_id', $officeId);
        }

        if ($table === 'temp_stockout_item' && $officeId !== null) {
            $subIds = $this->db->table('temp_stockout')
                ->select('temp_stockout_id')
                ->where('user_office_id', $officeId)
                ->get()
                ->getResultArray();
            $ids = array_column($subIds, 'temp_stockout_id');
            if (empty($ids)) {
                $lines[] = '-- (no rows)';
                $lines[] = '';
                return implode("\n", $lines);
            }
            $builder = $this->db->table($table)
                ->whereIn('temp_stockout_id', $ids)
                ->orderBy($pk, 'ASC');
        }

        $rows = $builder->get()->getResultArray();

        if (empty($rows)) {
            $lines[] = '-- (no rows)';
            $lines[] = '';
            return implode("\n", $lines);
        }

        $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';

        foreach ($rows as $row) {
            $values = array_map(function ($v) {
                if ($v === null) return 'NULL';
                return "'" . $this->db->escapeString((string) $v) . "'";
            }, array_values($row));

            $lines[] = "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode(', ', $values) . ') ON DUPLICATE KEY UPDATE ' . $this->buildUpdateClause(array_keys($rows[0])) . ';';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    private function buildUpdateClause(array $columns): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $parts[] = "`{$col}` = VALUES(`{$col}`)";
        }
        return implode(', ', $parts);
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

    private function executeSql(string $sql): array
    {
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== '' && ! str_starts_with($s, '--')
        );

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($statements as $statement) {
                if (trim($statement) === '') continue;
                $this->db->query($statement);
            }
        } catch (\Throwable $e) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            return ['ok' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        return ['ok' => true, 'message' => 'Restore completed successfully.'];
    }

    // ─────────────────────────────────────────────────────────
    //  Auto-backup check
    // ─────────────────────────────────────────────────────────

    public function needsAutoBackup(int $officeId): bool
    {
        $today = date('Y-m-d');
        $count = (int) $this->db->table('backup_log')
            ->where('user_office_id', $officeId)
            ->like('created_at', $today, 'after')
            ->countAllResults();
        return $count === 0;
    }
}
