<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UniqueEmailOnUserTable extends Migration
{
    public function up()
    {
        // Change email column default from '' to NULL so multiple users
        // with no email set don't violate the unique constraint.
        $this->forge->modifyColumn('user_table', [
            'email' => [
                'name'       => 'email',
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ]);

        // Convert existing empty-string emails to NULL
        $this->db->query("UPDATE user_table SET email = NULL WHERE email = ''");

        // Null out duplicate emails, keeping only the oldest account's email.
        // This finds every email that appears more than once and clears it
        // for all rows except the one with the lowest user_id.
        $this->db->query("
            UPDATE user_table u
            JOIN (
                SELECT email, MIN(user_id) AS keep_id
                FROM user_table
                WHERE email IS NOT NULL
                GROUP BY email
                HAVING COUNT(*) > 1
            ) dupes ON u.email = dupes.email AND u.user_id != dupes.keep_id
            SET u.email = NULL
        ");

        // Add unique index (MySQL allows multiple NULLs in a unique index)
        $this->forge->addUniqueKey('email', 'uq_user_email');
        $this->forge->processIndexes('user_table');
    }

    public function down()
    {
        // Drop the unique index
        $this->db->query('ALTER TABLE user_table DROP INDEX uq_user_email');

        // Revert email column back to NOT NULL with empty default
        $this->forge->modifyColumn('user_table', [
            'email' => [
                'name'       => 'email',
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
                'default'    => '',
            ],
        ]);

        // Restore empty string for NULLs
        $this->db->query("UPDATE user_table SET email = '' WHERE email IS NULL");
    }
}
