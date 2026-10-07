<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RestructureAllTables extends Migration
{
    public function up()
    {
        // ── Drop all old tables in FK-safe order ──
        $this->forge->dropTable('transaction_backup', true);
        $this->forge->dropTable('temp_stockout_item', true);
        $this->forge->dropTable('temp_stockout', true);
        $this->forge->dropTable('batch', true);
        $this->forge->dropTable('stockcard', true);
        $this->forge->dropTable('transaction', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('item', true);
        $this->forge->dropTable('office', true);
        $this->forge->dropTable('roles', true);
        $this->forge->dropTable('reference', true);
        $this->forge->dropTable('item_category', true);
        $this->forge->dropTable('item_type', true);
        $this->forge->dropTable('unit', true);
        $this->forge->dropTable('entity', true);
        $this->forge->dropTable('user_activity', true);
        $this->forge->dropTable('level_of_access', true);
        $this->forge->dropTable('transaction_type', true);
        $this->forge->dropTable('adjustment_reason', true);
        $this->forge->dropTable('user_office', true);

        // ══════════════════════════════════════════════
        //  NEW SCHEMA
        // ══════════════════════════════════════════════

        // ── 1. user_office_table ──
        $this->forge->addField([
            'user_office_id'   => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'user_office_name' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('user_office_id');
        $this->forge->createTable('user_office_table', true);

        // ── 2. level_of_access ──
        $this->forge->addField([
            'lvl_of_access_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'role'             => ['type' => 'VARCHAR', 'constraint' => 100],
            'lvl_of_access'    => ['type' => 'INT', 'constraint' => 11],
        ]);
        $this->forge->addPrimaryKey('lvl_of_access_id');
        $this->forge->createTable('level_of_access', true);

        // ── 3. user_activity_table ──
        $this->forge->addField([
            'user_activity_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'user_activity'    => ['type' => 'VARCHAR', 'constraint' => 50],
        ]);
        $this->forge->addPrimaryKey('user_activity_id');
        $this->forge->createTable('user_activity_table', true);

        // ── 4. adjustment_reason ──
        $this->forge->addField([
            'adjustment_reason_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'adjustment_reason'    => ['type' => 'VARCHAR', 'constraint' => 100],
        ]);
        $this->forge->addPrimaryKey('adjustment_reason_id');
        $this->forge->createTable('adjustment_reason', true);

        // ── 5. transaction_type_table ──
        $this->forge->addField([
            'transaction_type_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'transaction_type'    => ['type' => 'VARCHAR', 'constraint' => 50],
        ]);
        $this->forge->addPrimaryKey('transaction_type_id');
        $this->forge->createTable('transaction_type_table', true);

        // Seed base transaction types so they always get IDs 1/2/3
        // (receipt=1, issue=2, adjust_out=3) before the borrow/return migration runs.
        $db = db_connect();
        if ($db->table('transaction_type_table')->countAllResults() === 0) {
            $db->table('transaction_type_table')->insertBatch([
                ['transaction_type' => 'receipt'],
                ['transaction_type' => 'issue'],
                ['transaction_type' => 'adjust_out'],
            ]);
        }

        // ── 6. entity_table ──
        $this->forge->addField([
            'entity_id'      => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'entity'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'fund_cluster'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('entity_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('entity_table', true);

        // ── 7. unit_table ──
        $this->forge->addField([
            'unit_id'        => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'unit'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('unit_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('unit_table', true);

        // ── 8. type_of_product ──
        $this->forge->addField([
            'type_id'        => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'type'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('type_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('type_of_product', true);

        // ── 9. reference_table ──
        $this->forge->addField([
            'reference_id'   => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'reference'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('reference_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('reference_table', true);

        // ── 10. office_table ──
        $this->forge->addField([
            'office_id'      => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'office_name'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'user_office_id' => ['type' => 'INT', 'constraint' => 11],
        ]);
        $this->forge->addPrimaryKey('office_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('office_table', true);

        // ── 11. product_table ──
        $this->forge->addField([
            'product_id'           => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'product_no'           => ['type' => 'INT', 'constraint' => 11],
            'product'              => ['type' => 'VARCHAR', 'constraint' => 255],
            'product_description'  => ['type' => 'VARCHAR', 'constraint' => 1000, 'default' => ''],
            'product_reorder_point' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'unit_id'              => ['type' => 'INT', 'constraint' => 11],
            'type_id'              => ['type' => 'INT', 'constraint' => 11],
            'user_office_id'       => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'entity_id'            => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'stock_no'             => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
        ]);
        $this->forge->addPrimaryKey('product_id');
        $this->forge->addKey('unit_id');
        $this->forge->addKey('type_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addKey('entity_id');
        $this->forge->addForeignKey('unit_id', 'unit_table', 'unit_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('type_id', 'type_of_product', 'type_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('entity_id', 'entity_table', 'entity_id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('product_table', true);

        // ── 12. user_table ──
        $this->forge->addField([
            'user_id'           => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'user_office_id'    => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'username'          => ['type' => 'VARCHAR', 'constraint' => 50],
            'email'             => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'password'          => ['type' => 'VARCHAR', 'constraint' => 255],
            'lvl_of_access_id'  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'user_activity_id'  => ['type' => 'INT', 'constraint' => 11, 'default' => 3],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addUniqueKey('username');
        $this->forge->addKey('user_office_id');
        $this->forge->addKey('lvl_of_access_id');
        $this->forge->addKey('user_activity_id');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lvl_of_access_id', 'level_of_access', 'lvl_of_access_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('user_activity_id', 'user_activity_table', 'user_activity_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_table', true);

        // ── 13. batch_table ──
        $this->forge->addField([
            'batch_id'             => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'batch_no'             => ['type' => 'VARCHAR', 'constraint' => 50, 'default' => ''],
            'product_id'           => ['type' => 'INT', 'constraint' => 11],
            'barcode_batch_image'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'expiration_date'      => ['type' => 'DATE', 'null' => true],
            'user_office_id'       => ['type' => 'INT', 'constraint' => 11],
            'reference_id'         => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'office_id'            => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'current_qty'          => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'date_received'        => ['type' => 'DATE', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('batch_id');
        $this->forge->addKey('product_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addKey('reference_id');
        $this->forge->addKey('office_id');
        $this->forge->addForeignKey('product_id', 'product_table', 'product_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('reference_id', 'reference_table', 'reference_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('office_id', 'office_table', 'office_id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('batch_table', true);

        // ── 14. transaction_table ──
        $this->forge->addField([
            'transaction_id'       => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'transaction_type_id'  => ['type' => 'INT', 'constraint' => 11],
            'transaction_qty'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'transaction_unit_cost' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0.00],
            'transaction_date'     => ['type' => 'DATETIME', 'null' => true],
            'batch_id'             => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'reference_id'         => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'office_id'            => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'user_id'              => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'user_office_id'       => ['type' => 'INT', 'constraint' => 11],
            'adjustment_reason_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('transaction_id');
        $this->forge->addKey('transaction_type_id');
        $this->forge->addKey('batch_id');
        $this->forge->addKey('reference_id');
        $this->forge->addKey('office_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addKey('adjustment_reason_id');
        $this->forge->addForeignKey('transaction_type_id', 'transaction_type_table', 'transaction_type_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('batch_id', 'batch_table', 'batch_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('reference_id', 'reference_table', 'reference_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('office_id', 'office_table', 'office_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'user_table', 'user_id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('adjustment_reason_id', 'adjustment_reason', 'adjustment_reason_id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('transaction_table', true);

        // ── 15. temp_stockout (kept, adapted) ──
        $this->forge->addField([
            'temp_stockout_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'user_id'          => ['type' => 'INT', 'constraint' => 11],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'approved_by'      => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'approved_at'      => ['type' => 'DATETIME', 'null' => true],
            'user_office_id'   => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('temp_stockout_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('user_office_id');
        $this->forge->addForeignKey('user_id', 'user_table', 'user_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_office_id', 'user_office_table', 'user_office_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('temp_stockout', true);

        // ── 16. temp_stockout_item (kept, adapted) ──
        $this->forge->addField([
            'temp_stockout_item_id' => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'temp_stockout_id'      => ['type' => 'INT', 'constraint' => 11],
            'product_id'            => ['type' => 'INT', 'constraint' => 11],
            'quantity'              => ['type' => 'INT', 'constraint' => 11],
            'unit'                  => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'           => ['type' => 'VARCHAR', 'constraint' => 1000, 'default' => ''],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
        ]);
        $this->forge->addPrimaryKey('temp_stockout_item_id');
        $this->forge->addKey('temp_stockout_id');
        $this->forge->addKey('product_id');
        $this->forge->addForeignKey('temp_stockout_id', 'temp_stockout', 'temp_stockout_id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('product_id', 'product_table', 'product_id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('temp_stockout_item', true);
    }

    public function down()
    {
        $this->forge->dropTable('temp_stockout_item', true);
        $this->forge->dropTable('temp_stockout', true);
        $this->forge->dropTable('transaction_table', true);
        $this->forge->dropTable('batch_table', true);
        $this->forge->dropTable('user_table', true);
        $this->forge->dropTable('product_table', true);
        $this->forge->dropTable('office_table', true);
        $this->forge->dropTable('reference_table', true);
        $this->forge->dropTable('type_of_product', true);
        $this->forge->dropTable('unit_table', true);
        $this->forge->dropTable('entity_table', true);
        $this->forge->dropTable('transaction_type_table', true);
        $this->forge->dropTable('adjustment_reason', true);
        $this->forge->dropTable('user_activity_table', true);
        $this->forge->dropTable('level_of_access', true);
        $this->forge->dropTable('user_office_table', true);
    }
}
