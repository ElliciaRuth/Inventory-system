<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stock-out request history: who accepted or rejected each item, when, and why it was
 * rejected; and when a request was submitted (created_at is when its draft was started).
 */
class AddStockoutDecisionDetails extends Migration
{
    public function up()
    {
        $itemColumns = [
            'decision_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'status'],
            'decided_by'      => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'decision_reason'],
            'decided_at'      => ['type' => 'DATETIME', 'null' => true, 'after' => 'decided_by'],
        ];
        foreach ($itemColumns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'temp_stockout_item')) {
                $this->forge->addColumn('temp_stockout_item', [$name => $definition]);
            }
        }

        if (! $this->db->fieldExists('submitted_at', 'temp_stockout')) {
            $this->forge->addColumn('temp_stockout', [
                'submitted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'created_at'],
            ]);
        }

        // Existing requests: best available times
        $this->db->query("UPDATE temp_stockout SET submitted_at = created_at WHERE submitted_at IS NULL AND status <> 'draft'");
        $this->db->query(
            "UPDATE temp_stockout_item tsi
             JOIN temp_stockout ts ON ts.temp_stockout_id = tsi.temp_stockout_id
             SET tsi.decided_at = COALESCE(ts.approved_at, ts.submitted_at, ts.created_at),
                 tsi.decided_by = ts.approved_by
             WHERE tsi.status IN ('approved', 'rejected') AND tsi.decided_at IS NULL"
        );
    }

    public function down()
    {
        foreach (['decision_reason', 'decided_by', 'decided_at'] as $name) {
            if ($this->db->fieldExists($name, 'temp_stockout_item')) {
                $this->forge->dropColumn('temp_stockout_item', $name);
            }
        }
        if ($this->db->fieldExists('submitted_at', 'temp_stockout')) {
            $this->forge->dropColumn('temp_stockout', 'submitted_at');
        }
    }
}
