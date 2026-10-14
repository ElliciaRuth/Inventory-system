<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Finished Product" is the only predefined category now. The other standard categories
 * added by AddStandardProductCategories are removed from every office's Category / Type
 * list. Products cascade-delete with their type, so a category any product (archived ones
 * included) still uses is kept.
 */
class RemoveStandardProductCategories extends Migration
{
    private const CATEGORIES = [
        'Perishable Raw Materials',
        'Non-Perishable Raw Materials',
        'Packaging',
        'Operational Supplies',
    ];

    public function up()
    {
        $db = db_connect();
        if (! $db->tableExists('type_of_product') || ! $db->tableExists('product_table')) {
            return;
        }

        $db->table('type_of_product')
            ->whereIn('type', self::CATEGORIES)
            ->where('type_id NOT IN (SELECT DISTINCT type_id FROM product_table WHERE type_id IS NOT NULL)', null, false)
            ->delete();
    }

    public function down()
    {
        // Nothing to restore: the categories were never required
    }
}
