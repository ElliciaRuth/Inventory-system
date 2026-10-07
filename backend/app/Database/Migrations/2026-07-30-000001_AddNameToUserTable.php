<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNameToUserTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('user_table', [
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'default'    => '',
                'after'      => 'user_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('user_table', 'name');
    }
}
