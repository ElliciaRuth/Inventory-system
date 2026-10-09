<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Batches record when the stock was manufactured, next to its expiration date.
 */
class AddManufacturingDateToBatchTable extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('manufacturing_date', 'batch_table')) {
            $this->forge->addColumn('batch_table', [
                'manufacturing_date' => ['type' => 'DATE', 'null' => true, 'after' => 'copy_id'],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('manufacturing_date', 'batch_table')) {
            $this->forge->dropColumn('batch_table', 'manufacturing_date');
        }
    }
}
