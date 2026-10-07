<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBackupLog extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'backup_id'       => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'backup_slot'     => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'backup_filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'backup_filepath' => ['type' => 'VARCHAR', 'constraint' => 500],
            'user_office_id'  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'office_name'     => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'created_by'      => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_by_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'file_size_bytes' => ['type' => 'BIGINT', 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('backup_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addKey('backup_slot');
        $this->forge->createTable('backup_log', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('backup_log', true);
    }
}
