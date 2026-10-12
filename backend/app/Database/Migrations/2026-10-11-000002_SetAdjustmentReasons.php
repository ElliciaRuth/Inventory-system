<?php

namespace App\Database\Migrations;

use App\Models\AdjustmentReasonModel;
use CodeIgniter\Database\Migration;

/**
 * Adjust Out offers Spoiled / Damaged / Correction / Others and Adjust In offers
 * Wrong Count / Others (AdjustmentReasonModel). Missing names are added; old reasons
 * (Lost, Expired, Spilled…) are removed unless a past transaction uses them, so the
 * stock card history keeps its reasons. "Physical Count" belongs to the count and stays.
 */
class SetAdjustmentReasons extends Migration
{
    private const KEEP = ['Physical Count'];

    public function up()
    {
        $db = $this->db;
        if (! $db->tableExists('adjustment_reason')) {
            return;
        }

        $wanted   = array_values(array_unique([...AdjustmentReasonModel::ADJUST_OUT, ...AdjustmentReasonModel::ADJUST_IN]));
        $existing = array_column($db->table('adjustment_reason')->get()->getResultArray(), 'adjustment_reason');

        foreach ($wanted as $name) {
            if (! in_array($name, $existing, true)) {
                $db->table('adjustment_reason')->insert(['adjustment_reason' => $name]);
            }
        }

        $db->table('adjustment_reason')
            ->whereNotIn('adjustment_reason', [...$wanted, ...self::KEEP])
            ->where('adjustment_reason_id NOT IN (SELECT DISTINCT adjustment_reason_id FROM transaction_table WHERE adjustment_reason_id IS NOT NULL)', null, false)
            ->delete();
    }

    public function down()
    {
        // The old reasons are not brought back; past transactions kept theirs
    }
}
