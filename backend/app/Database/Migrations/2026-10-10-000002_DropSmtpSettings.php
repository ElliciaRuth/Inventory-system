<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The system email account (Settings → Email) is gone: users reset their password through
 * their own email account with an app password that is never stored. The table could still
 * hold an administrator's Gmail address and encrypted app password, which nothing uses any
 * more, so it is removed together with that credential.
 */
class DropSmtpSettings extends Migration
{
    public function up()
    {
        $this->forge->dropTable('smtp_settings', true);
    }

    public function down()
    {
        // The empty table only; the removed credential can't (and shouldn't) come back
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'auto_increment' => true],
            'smtp_email'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'smtp_password' => ['type' => 'TEXT'],
            'configured_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('configured_by');
        $this->forge->addForeignKey('configured_by', 'user_table', 'user_id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('smtp_settings', true);
    }
}
