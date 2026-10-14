<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * System SMTP account for OTP delivery:
 * - smtp_settings holds exactly one row: the system email address and its AES-256-GCM-
 *   encrypted app password (same scheme as BackupCrypto). The encryption key comes from
 *   Config\Encryption::key (the same .env value used everywhere in CI4).
 * - user_table gains a nullable recovery_email column. Forgot-password sends the OTP to
 *   this address (falls back to the primary email if it is not set).
 */
class AddSmtpSettingsAndRecoveryEmail extends Migration
{
    public function up()
    {
        // ── smtp_settings (one system sender account) ──────────────────────
        if (! $this->db->tableExists('smtp_settings')) {
            $this->forge->addField([
                'id'             => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
                'smtp_email'     => ['type' => 'VARCHAR', 'constraint' => 255],
                // AES-256-GCM ciphertext, base64-encoded (same format as BackupCrypto)
                'smtp_password'  => ['type' => 'TEXT'],
                'configured_by'  => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'created_at'     => ['type' => 'DATETIME', 'null' => true],
                'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('configured_by');
            $this->forge->addForeignKey('configured_by', 'user_table', 'user_id', 'SET NULL', 'CASCADE');
            $this->forge->createTable('smtp_settings', true);
        }

        // ── recovery_email on user_table ───────────────────────────────────
        $fields = $this->db->getFieldNames('user_table');
        if (! in_array('recovery_email', $fields, true)) {
            $this->forge->addColumn('user_table', [
                'recovery_email' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'email',
                ],
            ]);
        }

        // ── must_setup_smtp flag: force admin_tech to set up SMTP right after
        //    the first-login password change ────────────────────────────────
        $fields = $this->db->getFieldNames('user_table');
        if (! in_array('must_setup_smtp', $fields, true)) {
            $this->forge->addColumn('user_table', [
                'must_setup_smtp' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'after'      => 'must_change_password',
                ],
            ]);
        }

        // ── must_setup_recovery_email flag: force every user to set a
        //    recovery e-mail address after their first password change ───────
        if (! in_array('must_setup_recovery_email', $fields, true)) {
            $this->forge->addColumn('user_table', [
                'must_setup_recovery_email' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'after'      => 'must_setup_smtp',
                ],
            ]);
        }

        // Mark the existing admin_tech (level 4) account so it is asked for
        // SMTP credentials on its next login (after changing the password)
        $this->db->query(
            "UPDATE user_table u
             JOIN level_of_access loa ON u.lvl_of_access_id = loa.lvl_of_access_id
             SET u.must_setup_smtp = 1
             WHERE loa.lvl_of_access = 4"
        );
    }

    public function down()
    {
        $this->forge->dropTable('smtp_settings', true);

        foreach (['must_setup_recovery_email', 'must_setup_smtp', 'recovery_email'] as $col) {
            if (in_array($col, $this->db->getFieldNames('user_table'), true)) {
                $this->forge->dropColumn('user_table', $col);
            }
        }
    }
}

