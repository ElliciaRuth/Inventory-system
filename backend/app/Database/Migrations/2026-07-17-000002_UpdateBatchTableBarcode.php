<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateBatchTableBarcode extends Migration
{
    public function up()
    {
        // 1. Drop the old barcode_batch_image column
        $this->forge->dropColumn('batch_table', 'barcode_batch_image');

        // 2. Add barcode_value column right after batch_no
        $this->forge->addColumn('batch_table', [
            'barcode_value' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => null,
                'after'      => 'batch_no',
            ],
        ]);

        // 3. Unique index so two batches can never share the same barcode
        $this->db->query('ALTER TABLE batch_table ADD UNIQUE KEY uq_batch_barcode_value (barcode_value)');

        // 4. Back-fill every existing batch with a deterministic barcode string
        //    so no row is left with NULL — format: B000001 … B999999
        $this->db->query(
            'UPDATE batch_table SET barcode_value = CONCAT("B", LPAD(batch_id, 6, "0"))
             WHERE barcode_value IS NULL'
        );
    }

    public function down()
    {
        $this->forge->dropColumn('batch_table', 'barcode_value');

        // Restore the old column
        $this->forge->addColumn('batch_table', [
            'barcode_batch_image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }
}
