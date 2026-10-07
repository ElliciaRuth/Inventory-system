<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemSettings extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'setting_key'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'setting_value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('setting_key');
        $this->forge->createTable('system_settings', true);

        // Seed defaults
        $this->db->table('system_settings')->insertBatch([
            ['setting_key' => 'expiry_warning_days', 'setting_value' => '30'],
            ['setting_key' => 'expiry_danger_days',  'setting_value' => '7'],
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('system_settings', true);
    }
}

