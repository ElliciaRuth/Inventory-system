<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Backups became zip packages; the log records which kind each file is,
 * what it contains and whether it is password-protected. Older rows stay "sql".
 */
class AddPackageInfoToBackupLog extends Migration
{
    private const COLUMNS = [
        'backup_format' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'sql', 'after' => 'backup_filepath_2'],
        'sections'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'backup_format'],
        'encrypted'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'sections'],
    ];

    public function up()
    {
        foreach (self::COLUMNS as $name => $definition) {
            if (! $this->db->fieldExists($name, 'backup_log')) {
                $this->forge->addColumn('backup_log', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            if ($this->db->fieldExists($name, 'backup_log')) {
                $this->forge->dropColumn('backup_log', $name);
            }
        }
    }
}
