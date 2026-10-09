<?php

namespace App\Database\Migrations;

use App\Libraries\StockcardWorkbookParser;
use CodeIgniter\Database\Migration;

/**
 * Merges units that are the same unit spelled differently ("pieces" and "pcs",
 * "kls"/"kilos"/"kilo", "bags"/"bag" …) into one unit per office.
 *
 * Products are moved to the kept unit before the duplicates are deleted (deleting
 * a unit would cascade to its products). Descriptive units such as
 * "12 bottles in one case" are left alone.
 */
class MergeDuplicateUnits extends Migration
{
    public function up()
    {
        $units  = $this->db->table('unit_table')->orderBy('unit_id')->get()->getResultArray();
        $groups = [];

        foreach ($units as $unit) {
            $canonical = StockcardWorkbookParser::UNIT_SYNONYMS[strtolower(trim($unit['unit']))] ?? null;
            if ($canonical !== null) {
                $groups[(int) $unit['user_office_id'] . '|' . $canonical][] = $unit;
            }
        }

        $this->db->transStart();

        foreach ($groups as $key => $rows) {
            [, $canonical] = explode('|', $key, 2);

            // Keep the row already spelled the standard way, else the oldest one renamed
            $keep = null;
            foreach ($rows as $row) {
                if ($row['unit'] === $canonical) {
                    $keep = $row;
                    break;
                }
            }
            $keep ??= $rows[0];
            if ($keep['unit'] !== $canonical) {
                $this->db->table('unit_table')->where('unit_id', $keep['unit_id'])->update(['unit' => $canonical]);
            }

            foreach ($rows as $row) {
                if ($row['unit_id'] === $keep['unit_id']) {
                    continue;
                }
                $this->db->table('product_table')->where('unit_id', $row['unit_id'])->update(['unit_id' => $keep['unit_id']]);
                $this->db->table('unit_table')->where('unit_id', $row['unit_id'])->delete();
            }
        }

        $this->db->transComplete();
    }

    public function down()
    {
        // Merged units can't be told apart again
    }
}
