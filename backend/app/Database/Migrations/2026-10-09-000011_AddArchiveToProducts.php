<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Archiving: a product that is no longer used is hidden from lists, pickers and alerts
 * but keeps its batches, ledger and reports. archived_at NULL = active.
 */
class AddArchiveToProducts extends Migration
{
    public function up()
    {
        $fields = [];
        if (! $this->db->fieldExists('archived_at', 'product_table')) {
            $fields['archived_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! $this->db->fieldExists('archived_by', 'product_table')) {
            $fields['archived_by'] = ['type' => 'INT', 'constraint' => 11, 'null' => true];
        }
        if (! $this->db->fieldExists('archive_reason', 'product_table')) {
            $fields['archive_reason'] = ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''];
        }
        if ($fields !== []) {
            $this->forge->addColumn('product_table', $fields);
        }

        if ($this->db->query("SHOW INDEX FROM product_table WHERE Key_name = 'idx_product_archived'")->getResultArray() === []) {
            $this->db->query('ALTER TABLE product_table ADD KEY idx_product_archived (user_office_id, archived_at)');
        }
    }

    public function down()
    {
        if ($this->db->query("SHOW INDEX FROM product_table WHERE Key_name = 'idx_product_archived'")->getResultArray() !== []) {
            $this->db->query('ALTER TABLE product_table DROP INDEX idx_product_archived');
        }
        foreach (['archive_reason', 'archived_by', 'archived_at'] as $field) {
            if ($this->db->fieldExists($field, 'product_table')) {
                $this->forge->dropColumn('product_table', $field);
            }
        }
    }
}
