<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // ── adjustment_reason ──
        if ($this->db->table('adjustment_reason')->countAllResults() === 0) {
            $this->db->table('adjustment_reason')->insertBatch([
                ['adjustment_reason' => 'Correction'],
                ['adjustment_reason' => 'Spoiled'],
                ['adjustment_reason' => 'Damaged'],
                ['adjustment_reason' => 'Lost'],
                ['adjustment_reason' => 'Expired'],
            ]);
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

        // ── type_of_product ──
        if ($this->db->table('type_of_product')->countAllResults() === 0) {
            $this->db->table('type_of_product')->insertBatch([
                ['type' => 'Finished Product', 'user_office_id' => 1], // BAKERY
                ['type' => 'Finished Product', 'user_office_id' => 2], // FPC
            ]);
        }

        // ── Seed / Update User Accounts ──
        // Level 1: Staff, Level 2: Custodian, Level 3: Manager, Level 4: Technical Staff
        // Office 1: BAKERY, Office 2: FPC, NULL: Global
        $users = [
            // ── Technical Staff (Level 4 - Global Admin) ──
            [
                'username'             => 'admin_tech',
                'password'             => password_hash('admin123', PASSWORD_DEFAULT),
                'first_name'           => 'Admin',
                'last_name'            => 'Tech',
                'name'                 => 'Admin Tech',
                'email'                => 'admin_tech@bsu.edu.ph',
                'user_office_id'       => null,
                'lvl_of_access_id'     => 4,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
                'must_setup_smtp'      => 1, // admin must configure system SMTP on first login
            ],
           
        ];

        foreach ($users as $userData) {
            $existing = $this->db->table('user_table')
                ->where('username', $userData['username'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $this->db->table('user_table')
                    ->where('user_id', $existing['user_id'])
                    ->update($userData);
            } else {
                $this->db->table('user_table')->insert($userData);
            }
        }
    }
}
