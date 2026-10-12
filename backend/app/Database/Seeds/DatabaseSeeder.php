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
                'email'                => 'admin_tech@bsu.edu.ph',
                'user_office_id'       => null,
                'lvl_of_access_id'     => 4,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'tech',
                'password'             => password_hash('Tech123', PASSWORD_DEFAULT),
                'first_name'           => 'Technical',
                'last_name'            => 'Staff',
                'name'                 => 'Technical Staff',
                'email'                => 'tech@bsu.edu.ph',
                'user_office_id'       => null,
                'lvl_of_access_id'     => 4,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],

            // ── Staff (Level 1) ──
            [
                'username'             => 'staff',
                'password'             => password_hash('Staff123', PASSWORD_DEFAULT),
                'first_name'           => 'General',
                'last_name'            => 'Staff',
                'name'                 => 'General Staff',
                'email'                => 'staff@bsu.edu.ph',
                'user_office_id'       => 1, // BAKERY
                'lvl_of_access_id'     => 1,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'staff_bakery',
                'password'             => password_hash('Staff123', PASSWORD_DEFAULT),
                'first_name'           => 'Bakery',
                'last_name'            => 'Staff',
                'name'                 => 'Bakery Staff',
                'email'                => 'staff_bakery@bsu.edu.ph',
                'user_office_id'       => 1, // BAKERY
                'lvl_of_access_id'     => 1,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'staff_fpc',
                'password'             => password_hash('Staff123', PASSWORD_DEFAULT),
                'first_name'           => 'FPC',
                'last_name'            => 'Staff',
                'name'                 => 'FPC Staff',
                'email'                => 'staff_fpc@bsu.edu.ph',
                'user_office_id'       => 2, // FPC
                'lvl_of_access_id'     => 1,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],

            // ── Custodian (Level 2) ──
            [
                'username'             => 'custodian',
                'password'             => password_hash('Custodian123', PASSWORD_DEFAULT),
                'first_name'           => 'General',
                'last_name'            => 'Custodian',
                'name'                 => 'General Custodian',
                'email'                => 'custodian@bsu.edu.ph',
                'user_office_id'       => 2, // FPC
                'lvl_of_access_id'     => 2,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'custodian_bakery',
                'password'             => password_hash('Custodian123', PASSWORD_DEFAULT),
                'first_name'           => 'Bakery',
                'last_name'            => 'Custodian',
                'name'                 => 'Bakery Custodian',
                'email'                => 'custodian_bakery@bsu.edu.ph',
                'user_office_id'       => 1, // BAKERY
                'lvl_of_access_id'     => 2,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'custodian_fpc',
                'password'             => password_hash('Custodian123', PASSWORD_DEFAULT),
                'first_name'           => 'FPC',
                'last_name'            => 'Custodian',
                'name'                 => 'FPC Custodian',
                'email'                => 'custodian_fpc@bsu.edu.ph',
                'user_office_id'       => 2, // FPC
                'lvl_of_access_id'     => 2,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],

            // ── Manager (Level 3) ──
            [
                'username'             => 'manager',
                'password'             => password_hash('Manager123', PASSWORD_DEFAULT),
                'first_name'           => 'General',
                'last_name'            => 'Manager',
                'name'                 => 'General Manager',
                'email'                => 'manager@bsu.edu.ph',
                'user_office_id'       => 1, // BAKERY
                'lvl_of_access_id'     => 3,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'manager_bakery',
                'password'             => password_hash('Manager123', PASSWORD_DEFAULT),
                'first_name'           => 'Bakery',
                'last_name'            => 'Manager',
                'name'                 => 'Bakery Manager',
                'email'                => 'manager_bakery@bsu.edu.ph',
                'user_office_id'       => 1, // BAKERY
                'lvl_of_access_id'     => 3,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
            ],
            [
                'username'             => 'manager_fpc',
                'password'             => password_hash('Manager123', PASSWORD_DEFAULT),
                'first_name'           => 'FPC',
                'last_name'            => 'Manager',
                'name'                 => 'FPC Manager',
                'email'                => 'manager_fpc@bsu.edu.ph',
                'user_office_id'       => 2, // FPC
                'lvl_of_access_id'     => 3,
                'user_activity_id'     => 1,
                'must_change_password' => 1, // the password above is public
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
