<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Change quantity columns from INT to DECIMAL(10,2) so the system
 * can store fractional quantities when partial usage % is applied.
 * e.g. issuing 1 unit at 50% usage → 0.50 consumed from batch.
 */
class DecimalQuantities extends Migration
{
    public function up(): void
    {
        // batch_table.current_qty: INT(11) → DECIMAL(10,2)
        $this->forge->modifyColumn('batch_table', [
            'current_qty' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
            ],
        ]);

        // transaction_table.transaction_qty: INT(11) → DECIMAL(10,2)
        $this->forge->modifyColumn('transaction_table', [
            'transaction_qty' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('batch_table', [
            'current_qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
        ]);

        $this->forge->modifyColumn('transaction_table', [
            'transaction_qty' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
            ],
        ]);
    }
}
