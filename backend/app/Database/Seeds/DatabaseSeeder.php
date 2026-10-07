<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // ── adjustment_reason ──
        $this->db->table('adjustment_reason')->insertBatch([
            ['adjustment_reason' => 'Correction'],
            ['adjustment_reason' => 'Spoiled'],
            ['adjustment_reason' => 'Damaged'],
            ['adjustment_reason' => 'Lost'],
            ['adjustment_reason' => 'Expired'],
        ]);

        // ── transaction_type_table ──
        // Guard: the main migration already seeds these if they are missing.
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
        $this->db->table('level_of_access')->insertBatch([
            ['role' => 'Staff',           'lvl_of_access' => 1],
            ['role' => 'Custodian',       'lvl_of_access' => 2],
            ['role' => 'Manager',         'lvl_of_access' => 3],
            ['role' => 'Technical Staff', 'lvl_of_access' => 4],
        ]);

        // ── user_activity_table ──
        $this->db->table('user_activity_table')->insertBatch([
            ['user_activity' => 'Active'],
            ['user_activity' => 'Deactivated'],
            ['user_activity' => 'Pending'],
        ]);

        // ── user_office_table ──
        $this->db->table('user_office_table')->insertBatch([
            ['user_office_name' => 'BAKERY'],
            ['user_office_name' => 'FPC'],
        ]);

        // ── type_of_product ──
        $this->db->table('type_of_product')->insertBatch([
            ['type' => 'Finished Product', 'user_office_id' => 1], // BAKERY
            ['type' => 'Finished Product', 'user_office_id' => 2], // FPC
        ]);

        // ── admin_tech (Technical Staff – global admin, level 4) ──
        $this->db->table('user_table')->insert([
            'username'             => 'admin_tech',
            'password'             => password_hash('admin123', PASSWORD_DEFAULT),
            'email'                => '',
            'user_office_id'       => null,
            'lvl_of_access_id'     => 4,   // Technical Staff
            'user_activity_id'     => 1,   // Active
            'must_change_password' => 1,   // Force password change on first login
        ]);
    }
}

