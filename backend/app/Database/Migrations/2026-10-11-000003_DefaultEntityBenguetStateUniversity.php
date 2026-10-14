<?php

namespace App\Database\Migrations;

use App\Models\EntityModel;
use CodeIgniter\Database\Migration;

/**
 * Every office gets the default entity "Benguet State University" with fund cluster 06.
 * An existing one keeps a fund cluster someone set; a blank, "N/A" or "-" one becomes 06.
 * Products with no entity (they can't take stock) are given the default.
 */
class DefaultEntityBenguetStateUniversity extends Migration
{
    public function up()
    {
        $db = $this->db;
        if (! $db->tableExists('entity_table') || ! $db->tableExists('user_office_table')) {
            return;
        }

        $entities = new EntityModel();
        foreach ($db->table('user_office_table')->select('user_office_id')->get()->getResultArray() as $office) {
            $officeId = (int) $office['user_office_id'];
            $entityId = $entities->defaultFor($officeId);

            $db->table('entity_table')
                ->where('entity_id', $entityId)
                ->groupStart()
                    ->where('fund_cluster', '')
                    ->orWhere('fund_cluster IS NULL', null, false)
                    ->orWhereIn('fund_cluster', ['N/A', 'NA', '-'])
                ->groupEnd()
                ->update(['fund_cluster' => EntityModel::DEFAULT_FUND_CLUSTER]);

            if ($db->tableExists('product_table')) {
                $db->table('product_table')
                    ->where('user_office_id', $officeId)
                    ->groupStart()->where('entity_id IS NULL', null, false)->orWhere('entity_id', 0)->groupEnd()
                    ->update(['entity_id' => $entityId]);
            }
        }
    }

    public function down()
    {
        // The entity may already be used by products and stock cards; it stays
    }
}
