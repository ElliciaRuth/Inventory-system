<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * - "adjust_in" transaction type (count corrections that add stock)
 * - adjustment reasons: duplicates merged, unique by name, "Spilled" and "Physical Count" added
 * - borrow_table: who borrowed what, how much is back, and whether it is settled
 * - transaction_table.borrow_id links borrow and return ledger entries to their borrow record
 */
class StockMovementUpgrades extends Migration
{
    private const NEW_REASONS = ['Spilled', 'Physical Count'];

    public function up()
    {
        $db = $this->db;

        // ── adjust_in ──
        if ($db->table('transaction_type_table')->where('transaction_type', 'adjust_in')->countAllResults() === 0) {
            $db->table('transaction_type_table')->insert(['transaction_type' => 'adjust_in']);
        }

        // ── Adjustment reasons: one row per name (the lowest id), references moved over ──
        $reasons   = $db->table('adjustment_reason')->orderBy('adjustment_reason_id', 'ASC')->get()->getResultArray();
        $canonical = [];
        foreach ($reasons as $row) {
            $key = mb_strtolower(trim((string) $row['adjustment_reason']));
            if (! isset($canonical[$key])) {
                $canonical[$key] = (int) $row['adjustment_reason_id'];
                continue;
            }
            $db->table('transaction_table')
                ->where('adjustment_reason_id', (int) $row['adjustment_reason_id'])
                ->update(['adjustment_reason_id' => $canonical[$key]]);
            $db->table('adjustment_reason')->where('adjustment_reason_id', (int) $row['adjustment_reason_id'])->delete();
        }
        foreach (self::NEW_REASONS as $name) {
            if (! isset($canonical[mb_strtolower($name)])) {
                $db->table('adjustment_reason')->insert(['adjustment_reason' => $name]);
            }
        }
        if ($db->query("SHOW INDEX FROM adjustment_reason WHERE Key_name = 'uniq_adjustment_reason'")->getResultArray() === []) {
            $db->query('ALTER TABLE adjustment_reason ADD UNIQUE KEY uniq_adjustment_reason (adjustment_reason)');
        }

        // ── Borrowing between units ──
        if (! $db->tableExists('borrow_table')) {
            $this->forge->addField([
                'borrow_id'                => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'user_office_id'           => ['type' => 'INT', 'constraint' => 11],
                'product_id'               => ['type' => 'INT', 'constraint' => 11],
                'copy_id'                  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'borrower_name'            => ['type' => 'VARCHAR', 'constraint' => 150],
                'borrower_user_office_id'  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'borrower_unit'            => ['type' => 'VARCHAR', 'constraint' => 150, 'default' => ''],
                'quantity'                 => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'returned_qty'             => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
                'status'                   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'outstanding'],
                'due_date'                 => ['type' => 'DATE', 'null' => true],
                'notes'                    => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
                'borrowed_by_user_id'      => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'borrowed_at'              => ['type' => 'DATETIME'],
                'returned_at'              => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('borrow_id');
            $this->forge->addKey('user_office_id');
            $this->forge->addKey('product_id');
            $this->forge->addKey('status');
            $this->forge->createTable('borrow_table', true);
        }

        if (! $db->fieldExists('borrow_id', 'transaction_table')) {
            $this->forge->addColumn('transaction_table', [
                'borrow_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'adjustment_reason_id'],
            ]);
            $db->query('ALTER TABLE transaction_table ADD KEY idx_borrow_id (borrow_id)');
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('borrow_id', 'transaction_table')) {
            $this->forge->dropColumn('transaction_table', 'borrow_id');
        }
        $this->forge->dropTable('borrow_table', true);
        if ($this->db->query("SHOW INDEX FROM adjustment_reason WHERE Key_name = 'uniq_adjustment_reason'")->getResultArray() !== []) {
            $this->db->query('ALTER TABLE adjustment_reason DROP INDEX uniq_adjustment_reason');
        }
        // adjust_in rows and the new reasons stay: ledger entries may point at them
    }
}
