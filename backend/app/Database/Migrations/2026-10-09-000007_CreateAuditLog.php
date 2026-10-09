<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only audit trail: logins, stock mutations, overrides and settings changes.
 * Triggers reject UPDATE and DELETE, so entries can't be changed or removed through SQL either.
 */
class CreateAuditLog extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('audit_log')) {
            $this->forge->addField([
                'audit_id'       => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'created_at'     => ['type' => 'DATETIME'],
                'user_id'        => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'username'       => ['type' => 'VARCHAR', 'constraint' => 100, 'default' => ''],
                'user_office_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
                'action'         => ['type' => 'VARCHAR', 'constraint' => 60],
                'entity'         => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => ''],
                'entity_id'      => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
                'summary'        => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => ''],
                'details'        => ['type' => 'TEXT', 'null' => true],
                'ip_address'     => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => ''],
            ]);
            $this->forge->addPrimaryKey('audit_id');
            $this->forge->addKey('created_at');
            $this->forge->addKey('user_office_id');
            $this->forge->addKey('action');
            $this->forge->createTable('audit_log', true);
        }

        $this->db->query('DROP TRIGGER IF EXISTS audit_log_no_update');
        $this->db->query('DROP TRIGGER IF EXISTS audit_log_no_delete');
        $this->db->query(
            "CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'"
        );
        $this->db->query(
            "CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only'"
        );
    }

    public function down()
    {
        $this->db->query('DROP TRIGGER IF EXISTS audit_log_no_update');
        $this->db->query('DROP TRIGGER IF EXISTS audit_log_no_delete');
        $this->forge->dropTable('audit_log', true);
    }
}
