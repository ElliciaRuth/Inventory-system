<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMeasurementToProductTable extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('product_table', [
            'measurement' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'default'    => '',
                'after'      => 'product_description',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('product_table', 'measurement');
    }
}
