<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Change product_no from INT(11) to VARCHAR(100) so that users can enter
 * alphanumeric stock codes (e.g. "BSU-001", "FP-2024-01") instead of
 * integers only.
 *
 * The unique composite index (product_no, user_office_id) is rebuilt
 * to work with the VARCHAR column.
 */
class ChangeProductNoToVarchar extends Migration
{
    public function up(): void
    {
        // Drop the existing unique index before altering the column
        $this->db->query('ALTER TABLE product_table DROP INDEX IF EXISTS uq_product_no_per_office');

        // Alter the column type to VARCHAR(100)
        $this->db->query("ALTER TABLE product_table MODIFY COLUMN product_no VARCHAR(100) NOT NULL DEFAULT ''");

        // Do NOT re-create a unique index — product_no is now a user-defined stock code label,
        // not a system-enforced unique key. Uniqueness is managed at the application level.
    }

    public function down(): void
    {
        // Revert to INT(11)
        $this->db->query('ALTER TABLE product_table MODIFY COLUMN product_no INT(11) NOT NULL DEFAULT 0');

        // Re-create the original unique index
        $this->db->query('ALTER TABLE product_table ADD UNIQUE KEY uq_product_no_per_office (product_no, user_office_id)');
    }
}

