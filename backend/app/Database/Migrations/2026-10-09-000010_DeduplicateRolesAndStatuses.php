<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Roles (level_of_access) and account statuses (user_activity_table) were stored three times over.
 * Keep the lowest id of each, point users at it, delete the copies, and add unique keys so
 * the copies can't come back. The code relies on the low ids (levels 1–4, statuses 1–3).
 */
class DeduplicateRolesAndStatuses extends Migration
{
    public function up()
    {
        $this->merge('level_of_access', 'lvl_of_access_id', 'lvl_of_access', 'lvl_of_access_id');
        $this->merge('user_activity_table', 'user_activity_id', 'user_activity', 'user_activity_id');

        $this->addUnique('level_of_access', 'uniq_lvl_of_access', 'lvl_of_access');
        $this->addUnique('user_activity_table', 'uniq_user_activity', 'user_activity');
    }

    public function down()
    {
        foreach (['level_of_access' => 'uniq_lvl_of_access', 'user_activity_table' => 'uniq_user_activity'] as $table => $index) {
            if ($this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'")->getResultArray() !== []) {
                $this->db->query("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        }
    }

    /** Rows with the same $keyColumn value collapse into the one with the lowest id. */
    private function merge(string $table, string $pk, string $keyColumn, string $userColumn): void
    {
        $rows      = $this->db->table($table)->orderBy($pk, 'ASC')->get()->getResultArray();
        $canonical = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row[$keyColumn]));
            if (! isset($canonical[$key])) {
                $canonical[$key] = (int) $row[$pk];
                continue;
            }
            $this->db->table('user_table')->where($userColumn, (int) $row[$pk])->update([$userColumn => $canonical[$key]]);
            $this->db->table($table)->where($pk, (int) $row[$pk])->delete();
        }
    }

    private function addUnique(string $table, string $index, string $column): void
    {
        if ($this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'")->getResultArray() === []) {
            $this->db->query("ALTER TABLE {$table} ADD UNIQUE KEY {$index} ({$column})");
        }
    }
}
