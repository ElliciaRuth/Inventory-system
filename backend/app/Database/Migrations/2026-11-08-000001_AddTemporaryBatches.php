<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Temporary stock: a batch added with a stock card import that is taken out before the other
 * batches, while its movements stay off the stock card and the summary report
 * (see TransactionModel::ON_STOCK_CARD_SQL and InventoryService::planDepletion).
 */
class AddTemporaryBatches extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('batch_table') || $this->db->fieldExists('is_temporary', 'batch_table')) {
            return;
        }

        $this->forge->addColumn('batch_table', [
            'is_temporary' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
        ]);
    }

    public function down(): void
    {
        if ($this->db->tableExists('batch_table') && $this->db->fieldExists('is_temporary', 'batch_table')) {
            $this->forge->dropColumn('batch_table', 'is_temporary');
        }
    }
}
