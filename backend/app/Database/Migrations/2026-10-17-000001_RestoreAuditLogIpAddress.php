<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Databases created while CreateAuditLog briefly left out ip_address have an audit_log
 * that every write fails on (App\Libraries\AuditLog stores the address). Put it back.
 */
class RestoreAuditLogIpAddress extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('audit_log') || $this->db->fieldExists('ip_address', 'audit_log')) {
            return;
        }

        $this->forge->addColumn('audit_log', [
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'default' => '', 'after' => 'details'],
        ]);
    }

    public function down()
    {
        // The column belongs to CreateAuditLog; nothing to undo
    }
}
