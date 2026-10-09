<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Columns that tag transactions created by the Excel stock card import.
 *
 * Some databases already have them from an earlier import attempt whose migration
 * files are gone, so every column and index is only added when missing.
 */
class EnsureStockImportColumns extends Migration
{
    private const COLUMNS = [
        // Hash of the source row; unique per office so re-importing a file adds nothing twice
        'stock_import_fingerprint' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'transaction_unit_cost'],
        // Id of the import run
        'stock_import_batch'       => ['type' => 'CHAR', 'constraint' => 48, 'null' => true, 'after' => 'stock_import_fingerprint'],
        // Order of the entry within the run
        'stock_import_order'       => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'stock_import_batch'],
        // Excel row number and the date exactly as written there
        'stock_import_source_row'  => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'stock_import_order'],
        'stock_import_raw_date'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'stock_import_source_row'],
    ];

    public function up()
    {
        foreach (self::COLUMNS as $name => $definition) {
            if (! $this->db->fieldExists($name, 'transaction_table')) {
                $this->forge->addColumn('transaction_table', [$name => $definition]);
            }
        }

        if (! $this->indexExists('uq_stock_import_fingerprint')) {
            $this->db->query('ALTER TABLE transaction_table ADD UNIQUE KEY uq_stock_import_fingerprint (user_office_id, stock_import_fingerprint)');
        }
        if (! $this->indexExists('idx_transaction_stock_import_order')) {
            $this->db->query('ALTER TABLE transaction_table ADD KEY idx_transaction_stock_import_order (stock_import_batch, stock_import_order)');
        }
    }

    public function down()
    {
        foreach (['uq_stock_import_fingerprint', 'idx_transaction_stock_import_order'] as $index) {
            if ($this->indexExists($index)) {
                $this->db->query("ALTER TABLE transaction_table DROP INDEX {$index}");
            }
        }

        foreach (array_reverse(array_keys(self::COLUMNS)) as $name) {
            if ($this->db->fieldExists($name, 'transaction_table')) {
                $this->forge->dropColumn('transaction_table', $name);
            }
        }
    }

    private function indexExists(string $name): bool
    {
        return $this->db->query('SHOW INDEX FROM transaction_table WHERE Key_name = ?', [$name])->getNumRows() > 0;
    }
}
