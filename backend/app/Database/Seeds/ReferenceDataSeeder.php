<?php

namespace App\Database\Seeds;

use App\Models\AdjustmentReasonModel;
use App\Models\EntityModel;
use CodeIgniter\Database\Seeder;

/**
 * The fixed lists the system needs to work: access levels, account statuses, transaction
 * types, adjustment reasons, the user offices and their defaults. No accounts and no
 * inventory, so it is safe on a production server (the Windows installer runs it); every
 * step only adds what is missing.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run()
    {
        // ── adjustment_reason ── (each missing name; migrations may already have added some)
        $reasons = array_unique([...AdjustmentReasonModel::ADJUST_OUT, ...AdjustmentReasonModel::ADJUST_IN]);
        foreach ($reasons as $name) {
            if ($this->db->table('adjustment_reason')->where('adjustment_reason', $name)->countAllResults() === 0) {
                $this->db->table('adjustment_reason')->insert(['adjustment_reason' => $name]);
            }
        }

        // ── transaction_type_table ──
        if ($this->db->table('transaction_type_table')
                ->whereIn('transaction_type', ['receipt', 'issue', 'adjust_out'])
                ->countAllResults() === 0
        ) {
            $this->db->table('transaction_type_table')->insertBatch([
                ['transaction_type' => 'receipt'],
                ['transaction_type' => 'issue'],
                ['transaction_type' => 'adjust_out'],
            ]);
        }

        // ── level_of_access (lvl_of_access_id, role, lvl_of_access) ──
        if ($this->db->table('level_of_access')->countAllResults() === 0) {
            $this->db->table('level_of_access')->insertBatch([
                ['role' => 'Staff',           'lvl_of_access' => 1],
                ['role' => 'Custodian',       'lvl_of_access' => 2],
                ['role' => 'Manager',         'lvl_of_access' => 3],
                ['role' => 'Technical Staff', 'lvl_of_access' => 4],
            ]);
        }

        // ── user_activity_table ──
        if ($this->db->table('user_activity_table')->countAllResults() === 0) {
            $this->db->table('user_activity_table')->insertBatch([
                ['user_activity' => 'Active'],
                ['user_activity' => 'Deactivated'],
                ['user_activity' => 'Pending'],
            ]);
        }

        // ── user_office_table ──
        if ($this->db->table('user_office_table')->countAllResults() === 0) {
            $this->db->table('user_office_table')->insertBatch([
                ['user_office_name' => 'BAKERY'],
                ['user_office_name' => 'FPC'],
            ]);
        }

        // ── entity_table: each office's default "Benguet State University" (fund cluster 06) ──
        $entities = new EntityModel();
        foreach ($this->db->table('user_office_table')->select('user_office_id')->get()->getResultArray() as $office) {
            $entities->defaultFor((int) $office['user_office_id']);
        }

        // ── type_of_product ──
        if ($this->db->table('type_of_product')->countAllResults() === 0) {
            $this->db->table('type_of_product')->insertBatch([
                ['type' => 'Finished Product', 'user_office_id' => 1], // BAKERY
                ['type' => 'Finished Product', 'user_office_id' => 2], // FPC
            ]);
        }
    }
}
