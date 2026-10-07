<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSecondDriveToBackupLog extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('backup_log', [
            'backup_filepath_2' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'default'    => '',
                'after'      => 'backup_filepath',
            ],
            'drive2_ok' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'backup_filepath_2',
                'comment'    => '1 = second drive write succeeded',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('backup_log', ['backup_filepath_2', 'drive2_ok']);
    }
}
