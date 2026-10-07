<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the product_copy_table for price-based sub-products ("copies").
 * Adds copy_id FK columns to batch_table, transaction_table, and temp_stockout_item.
 * Backfills a default copy for every existing product.
 */
class CreateProductCopyTable extends Migration
{
    public function up()
    {
        // ── 1. Create product_copy_table ───────────────────────────────────
        $this->forge->addField([
            'copy_id'        => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'product_id'     => ['type' => 'INT', 'constraint' => 11],
            'unit_cost'      => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0.00],
            'label'          => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('copy_id');
        $this->forge->addKey('product_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('product_id', 'product_table', 'product_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('product_copy_table', true);

        // ── 2. Add copy_id to batch_table ──────────────────────────────────
        $this->forge->addColumn('batch_table', [
            'copy_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'product_id',
            ],
        ]);

        // ── 3. Add copy_id to transaction_table ────────────────────────────
        $this->forge->addColumn('transaction_table', [
            'copy_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'batch_id',
            ],
        ]);

        // ── 4. Add copy_id to temp_stockout_item ───────────────────────────
        $this->forge->addColumn('temp_stockout_item', [
            'copy_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'product_id',
            ],
        ]);

        // ── 5. Add FK constraints ──────────────────────────────────────────
        $db = db_connect();
        $db->query('ALTER TABLE batch_table ADD CONSTRAINT fk_batch_copy FOREIGN KEY (copy_id) REFERENCES product_copy_table(copy_id) ON DELETE SET NULL ON UPDATE CASCADE');
        $db->query('ALTER TABLE transaction_table ADD CONSTRAINT fk_transaction_copy FOREIGN KEY (copy_id) REFERENCES product_copy_table(copy_id) ON DELETE SET NULL ON UPDATE CASCADE');
        $db->query('ALTER TABLE temp_stockout_item ADD CONSTRAINT fk_stockout_item_copy FOREIGN KEY (copy_id) REFERENCES product_copy_table(copy_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── 6. Data migration: create default copy for existing products ───
        $this->backfillDefaultCopies($db);
    }

    public function down()
    {
        $db = db_connect();

        // Drop FK constraints first
        try { $db->query('ALTER TABLE temp_stockout_item DROP FOREIGN KEY fk_stockout_item_copy'); } catch (\Throwable $e) {}
        try { $db->query('ALTER TABLE transaction_table DROP FOREIGN KEY fk_transaction_copy'); } catch (\Throwable $e) {}
        try { $db->query('ALTER TABLE batch_table DROP FOREIGN KEY fk_batch_copy'); } catch (\Throwable $e) {}

        // Drop copy_id columns
        $this->forge->dropColumn('temp_stockout_item', 'copy_id');
        $this->forge->dropColumn('transaction_table', 'copy_id');
        $this->forge->dropColumn('batch_table', 'copy_id');

        // Drop the table
        $this->forge->dropTable('product_copy_table', true);
    }

    /**
     * For each existing product, create one default copy using the latest receipt unit cost,
     * then backfill copy_id on all related batch and transaction rows.
     */
    private function backfillDefaultCopies($db): void
    {
        $products = $db->table('product_table')->select('product_id, user_office_id')->get()->getResultArray();

        if (empty($products)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        // Receipt type ID
        $receiptRow = $db->table('transaction_type_table')
            ->select('transaction_type_id')
            ->where('transaction_type', 'receipt')
            ->get(1)->getRowArray();
        $receiptTypeId = (int) ($receiptRow['transaction_type_id'] ?? 1);

        foreach ($products as $product) {
            $productId    = (int) $product['product_id'];
            $userOfficeId = $product['user_office_id'];

            // Find latest receipt cost for this product
            $costRow = $db->query(
                'SELECT t.transaction_unit_cost
                 FROM transaction_table t
                 INNER JOIN batch_table b ON t.batch_id = b.batch_id
                 WHERE b.product_id = ? AND t.transaction_type_id = ?
                 ORDER BY t.transaction_id DESC
                 LIMIT 1',
                [$productId, $receiptTypeId]
            )->getRowArray();

            $unitCost = (float) ($costRow['transaction_unit_cost'] ?? 0);

            // Create a default copy
            $db->table('product_copy_table')->insert([
                'product_id'     => $productId,
                'unit_cost'      => $unitCost,
                'label'          => '',
                'user_office_id' => $userOfficeId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $copyId = (int) $db->insertID();

            // Backfill batch_table
            $db->table('batch_table')
                ->where('product_id', $productId)
                ->where('copy_id IS NULL')
                ->update(['copy_id' => $copyId]);

            // Backfill transaction_table via batch join
            $db->query(
                'UPDATE transaction_table t
                 INNER JOIN batch_table b ON t.batch_id = b.batch_id
                 SET t.copy_id = ?
                 WHERE b.product_id = ? AND t.copy_id IS NULL',
                [$copyId, $productId]
            );
        }
    }
}
