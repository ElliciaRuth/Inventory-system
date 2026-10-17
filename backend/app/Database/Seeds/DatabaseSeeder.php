<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // The fixed lists (levels, statuses, offices…); then the demo accounts below
        $this->call('ReferenceDataSeeder');

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
                'user_office_id'       => null,
                'email'                => 'admin_tech@gmail.com',
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
