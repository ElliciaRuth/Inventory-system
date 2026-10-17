<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One change counter per scope (App\Libraries\SyncVersion): the app polls it to refresh
 * open pages as soon as someone else changes something.
 */
class CreateSyncVersion extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'scope'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'version'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('scope');
        $this->forge->createTable('sync_version', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('sync_version', true);
    }
}
