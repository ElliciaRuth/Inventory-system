<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the standard product categories to every office's Category / Type list.
 * Existing names (any letter case) are left alone, so it is safe to re-run.
 */
class AddStandardProductCategories extends Migration
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
        if (! $db->tableExists('type_of_product') || ! $db->tableExists('user_office_table')) {
            return;
        }

        $offices = $db->table('user_office_table')->select('user_office_id')->get()->getResultArray();

        foreach ($offices as $office) {
            $officeId = (int) $office['user_office_id'];

            $existing = array_map(
                static fn ($row) => mb_strtolower(trim((string) $row['type'])),
                $db->table('type_of_product')->select('type')->where('user_office_id', $officeId)->get()->getResultArray()
            );

            $rows = [];
            foreach (self::CATEGORIES as $name) {
                if (! in_array(mb_strtolower($name), $existing, true)) {
                    $rows[] = ['type' => $name, 'user_office_id' => $officeId];
                }
            }

            if ($rows) {
                $db->table('type_of_product')->insertBatch($rows);
            }
        }
    }

    public function down()
    {
        // Products cascade-delete with their type, so only remove categories nothing uses
        $db = db_connect();
        $db->table('type_of_product')
            ->whereIn('type', self::CATEGORIES)
            ->where('type_id NOT IN (SELECT DISTINCT type_id FROM product_table WHERE type_id IS NOT NULL)', null, false)
            ->delete();
    }
}
