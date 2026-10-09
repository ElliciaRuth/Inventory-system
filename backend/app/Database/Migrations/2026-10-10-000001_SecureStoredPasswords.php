<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Passwords at rest:
 *  - accounts from the old system may still hold a plain-text password (they were only
 *    re-hashed at their next login); hash them now, so a database copy reveals none;
 *  - accounts still using one of the demo passwords published in the README and the seeder
 *    must choose their own at the next login.
 * Nothing changes for anyone's ability to log in with their current password.
 */
class SecureStoredPasswords extends Migration
{
    /** The demo passwords from DatabaseSeeder / README */
    private const PUBLISHED_PASSWORDS = ['admin123', 'Tech123', 'Staff123', 'Custodian123', 'Manager123'];

    public function up()
    {
        $users = $this->db->table('user_table')->select('user_id, password, must_change_password')->get()->getResultArray();

        foreach ($users as $user) {
            $stored = (string) ($user['password'] ?? '');
            if ($stored === '') {
                continue;
            }

            $update = [];
            if (! password_get_info($stored)['algo']) {
                $update['password'] = password_hash($stored, PASSWORD_DEFAULT);
                $isPublished        = in_array($stored, self::PUBLISHED_PASSWORDS, true);
            } else {
                $isPublished = false;
                foreach (self::PUBLISHED_PASSWORDS as $published) {
                    if (password_verify($published, $stored)) {
                        $isPublished = true;
                        break;
                    }
                }
            }

            if ($isPublished && (int) ($user['must_change_password'] ?? 0) !== 1) {
                $update['must_change_password'] = 1;
            }
            if ($update !== []) {
                $this->db->table('user_table')->where('user_id', (int) $user['user_id'])->update($update);
            }
        }
    }

    public function down()
    {
        // Hashing can't be undone, and the forced password change should stay
    }
}
