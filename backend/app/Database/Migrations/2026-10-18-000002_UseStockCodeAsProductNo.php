<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ChangeProductNoToVarchar made product_no the product's stock code, but existing products
 * kept their old internal numbers (1, 2, 3…) there and their real codes ("FPC RM 02A") in
 * stock_no. Copy the real code over, so the screens, reports and edit form all show it.
 * A product without a stock code gets its number as the code.
 */
class UseStockCodeAsProductNo extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('product_table')) {
            return;
        }

        $this->db->query(
            "UPDATE product_table SET product_no = stock_no
             WHERE stock_no IS NOT NULL AND stock_no <> '' AND product_no <> stock_no"
        );
        $this->db->query(
            "UPDATE product_table SET stock_no = product_no
             WHERE (stock_no IS NULL OR stock_no = '') AND product_no <> ''"
        );
    }

    public function down(): void
    {
        // The old numbers are not kept; nothing to undo
    }
}
