<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSmtpAndResetFields extends Migration
{
    public function up()
    {
        // ── Add columns to user_table ──
        $this->forge->addColumn('user_table', [
            'must_change_password' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'user_activity_id',
            ],
            'password_reset_token' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'must_change_password',
            ],
            'password_reset_expires' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'password_reset_token',
            ],
        ]);

        // Set must_change_password = 1 for existing admin_tech (level 4)
        $this->db->query(
            "UPDATE user_table u
             JOIN level_of_access loa ON u.lvl_of_access_id = loa.lvl_of_access_id
             SET u.must_change_password = 1
             WHERE loa.lvl_of_access = 4"
        );

        // ── Create smtp_settings table ──
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'smtp_email'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'smtp_password' => ['type' => 'TEXT'],  // encrypted at rest
            'configured_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('configured_by');
        $this->forge->addForeignKey('configured_by', 'user_table', 'user_id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('smtp_settings', true);
    }

    public function down()
    {
        $this->forge->dropTable('smtp_settings', true);

        $this->forge->dropColumn('user_table', 'must_change_password');
        $this->forge->dropColumn('user_table', 'password_reset_token');
        $this->forge->dropColumn('user_table', 'password_reset_expires');
    }
}
