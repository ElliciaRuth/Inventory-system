<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SplitNameFieldsOnUserTable extends Migration
{
    public function up()
    {
        // Add the four new name columns after the existing 'name' column
        $this->forge->addColumn('user_table', [
            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => '',
                'after'      => 'name',
            ],
            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => '',
                'after'      => 'first_name',
            ],
            'middle_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => '',
                'null'       => true,
                'after'      => 'last_name',
            ],
            'suffix' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => '',
                'null'       => true,
                'after'      => 'middle_name',
            ],
        ]);

        // Migrate existing 'name' data into 'first_name' for records that have no first_name yet
        $this->db->query("
            UPDATE user_table
            SET first_name = TRIM(name)
            WHERE (first_name IS NULL OR first_name = '')
              AND name IS NOT NULL
              AND name != ''
        ");
    }

    public function down()
    {
        $this->forge->dropColumn('user_table', ['first_name', 'last_name', 'middle_name', 'suffix']);
    }
}
