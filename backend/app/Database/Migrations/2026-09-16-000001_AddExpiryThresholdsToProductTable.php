<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddExpiryThresholdsToProductTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('product_table', [
            'expiry_warning_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 30,
                'after'      => 'product_reorder_point',
            ],
            'expiry_danger_days' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 7,
                'after'      => 'expiry_warning_days',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('product_table', ['expiry_warning_days', 'expiry_danger_days']);
    }
}

