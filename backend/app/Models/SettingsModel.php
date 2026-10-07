<?php

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Model;

class SettingsModel extends Model
{
    protected $table = 'user_table';

    /**
     * Return settings definitions filtered by access level.
     * Level 2: inventory settings only (no users)
     * Level 3: inventory settings + users (within office)
     * Level 4: user + user_office_table management only
     */
    public function definitions(int $levelId = 2): array
    {
        $allDefinitions = $this->allDefinitions();

        // Level 4 (Technical Staff): only user + user_office management
        if ($levelId >= 4) {
            return [
                'users'             => $allDefinitions['users'],
                'user_office_table' => $allDefinitions['user_office_table'],
            ];
        }

        $defs = [
            'entity_table'    => $allDefinitions['entity_table'],
            'unit_table'      => $allDefinitions['unit_table'],
            'reference_table' => $allDefinitions['reference_table'],
            'type_of_product' => $allDefinitions['type_of_product'],
            'office_table'    => $allDefinitions['office_table'],
        ];

        // Level 3: add user management
        if ($levelId >= 3) {
            $defs = array_merge(
                ['users' => $allDefinitions['users']],
                $defs
            );
        }

        return $defs;
    }

    private function allDefinitions(): array
    {
        return [
            'users' => [
                'table'  => 'user_table',
                'pk'     => 'user_id',
                'fields' => ['name', 'username', 'email', 'password', 'lvl_of_access_id', 'user_office_id'],
                'labels' => [
                    'name'     => 'Full Name',
                    'username' => 'Username',
                    'email'    => 'Email',
                ],
            ],
            'entity_table' => [
                'table'  => 'entity_table',
                'pk'     => 'entity_id',
                'fields' => ['entity', 'fund_cluster'],
                'labels' => ['entity' => 'Entity Name', 'fund_cluster' => 'Fund Cluster'],
            ],
            'unit_table' => [
                'table'  => 'unit_table',
                'pk'     => 'unit_id',
                'fields' => ['unit'],
                'labels' => ['unit' => 'Unit'],
            ],
            'reference_table' => [
                'table'  => 'reference_table',
                'pk'     => 'reference_id',
                'fields' => ['reference'],
                'labels' => ['reference' => 'Reference'],
            ],
            'type_of_product' => [
                'table'  => 'type_of_product',
                'pk'     => 'type_id',
                'fields' => ['type'],
                'labels' => ['type' => 'Product Type'],
            ],
            'office_table' => [
                'table'  => 'office_table',
                'pk'     => 'office_id',
                'fields' => ['office_name'],
                'labels' => ['office_name' => 'Office Name'],
            ],
            'user_office_table' => [
                'table'  => 'user_office_table',
                'pk'     => 'user_office_id',
                'fields' => ['user_office_name'],
                'labels' => ['user_office_name' => 'User Office Name'],
            ],
        ];
    }

    public function indexData(int $userOfficeId = 0, int $levelId = 2): array
    {
        $definitions = $this->definitions($levelId);

        $records = [];
        foreach (array_keys($definitions) as $type) {
            if ($type === 'users') {
                $records['users'] = $this->userRecords($userOfficeId, $levelId);
            } elseif ($type === 'user_office_table') {
                $records['user_office_table'] = $this->db->table('user_office_table')
                    ->orderBy('user_office_name', 'ASC')->get()->getResultArray();
            } else {
                $orderCol = match ($type) {
                    'entity_table'    => 'entity',
                    'unit_table'      => 'unit',
                    'reference_table' => 'reference',
                    'type_of_product' => 'type',
                    'office_table'    => 'office_name',
                    default           => $definitions[$type]['pk'],
                };
                $records[$type] = $this->orderedRecords($definitions[$type]['table'], $orderCol, $userOfficeId);
            }
        }

        // Pending users for Level 3+ approval
        $pendingUsers = [];
        if ($levelId >= 3) {
            $pendingUsers = $this->pendingUsers($userOfficeId, $levelId);
        }

        return [
            'definitions'  => $definitions,
            'records'      => $records,
            'userOffices'  => $this->db->table('user_office_table')->orderBy('user_office_name', 'ASC')->get()->getResultArray(),
            'levels'       => $this->db->table('level_of_access')->orderBy('lvl_of_access', 'ASC')->get()->getResultArray(),
            'pendingUsers' => $pendingUsers,
            'levelId'      => $levelId,
        ];
    }

    public function definition(string $type, int $levelId = 2): array
    {
        $definitions = $this->definitions($levelId);
        if (! array_key_exists($type, $definitions)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        return $definitions[$type];
    }

    public function fetchRecord(string $type, int $id): array
    {
        $allDefs = $this->allDefinitions();
        if (! array_key_exists($type, $allDefs)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $definition = $allDefs[$type];

        $row = $this->db->table($definition['table'])
            ->where($definition['pk'], $id)
            ->get()
            ->getRowArray() ?? [];

        if ($type === 'users') {
            unset($row['password']);
        }

        return $row;
    }

    public function saveRecord(string $type, int $id, array $payload, int $userOfficeId = 0): void
    {
        $allDefs = $this->allDefinitions();
        if (! array_key_exists($type, $allDefs)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $definition = $allDefs[$type];

        // Auto-inject user_office_id for tables that have it (except users, user_office_table)
        if (! in_array($type, ['users', 'user_office_table'], true) && $userOfficeId > 0) {
            $payload['user_office_id'] = $userOfficeId;
        }

        if ($id > 0) {
            $this->db->table($definition['table'])
                ->where($definition['pk'], $id)
                ->update($payload);
            return;
        }

        $this->db->table($definition['table'])->insert($payload);
    }

    public function deleteRecord(string $type, int $id): void
    {
        $allDefs = $this->allDefinitions();
        if (! array_key_exists($type, $allDefs)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $definition = $allDefs[$type];

        $this->db->transException(true)->transStart();
        $this->preserveRelationsBeforeDelete($this->db, $type, $id);
        $this->db->table($definition['table'])
            ->where($definition['pk'], $id)
            ->delete();
        $this->db->transComplete();
    }

    public function pendingUsers(int $userOfficeId = 0, int $levelId = 3): array
    {
        $builder = $this->db->table('user_table')
            ->select('user_table.*, user_table.name,
                      COALESCE(uot.user_office_name, "Global") AS user_office_name,
                      COALESCE(loa.role, "Unknown") AS role')
            ->join('user_office_table uot', 'user_table.user_office_id = uot.user_office_id', 'left')
            ->join('level_of_access loa', 'user_table.lvl_of_access_id = loa.lvl_of_access_id', 'left')
            ->where('user_table.user_activity_id', 3)
            ->orderBy('user_table.user_id', 'ASC');

        if ($levelId < 4 && $userOfficeId > 0) {
            $builder->where('user_table.user_office_id', $userOfficeId);
        }

        return $builder->get()->getResultArray();
    }

    public function activateUser(int $userId): void
    {
        $this->db->table('user_table')
            ->where('user_id', $userId)
            ->update(['user_activity_id' => 1]);
    }

    public function deactivateUser(int $userId): void
    {
        $this->db->table('user_table')
            ->where('user_id', $userId)
            ->update(['user_activity_id' => 2]);
    }

    private function orderedRecords(string $table, string $orderBy, int $userOfficeId = 0): array
    {
        $builder = $this->db->table($table)->orderBy($orderBy, 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return $builder->get()->getResultArray();
    }

    private function userRecords(int $userOfficeId = 0, int $levelId = 2): array
    {
        $sql = 'SELECT u.user_id, u.name, u.username, u.email, u.user_office_id, u.user_activity_id, u.lvl_of_access_id,
                       COALESCE(uot.user_office_name, "Global") AS user_office_name,
                       COALESCE(ua.user_activity, "Unknown") AS activity_status,
                       COALESCE(loa.role, "Unknown") AS role
                FROM user_table u
                LEFT JOIN user_office_table uot ON u.user_office_id = uot.user_office_id
                LEFT JOIN user_activity_table ua ON u.user_activity_id = ua.user_activity_id
                LEFT JOIN level_of_access loa ON u.lvl_of_access_id = loa.lvl_of_access_id';

        $conditions = [];
        $params     = [];

        if ($levelId < 4 && $userOfficeId > 0) {
            $conditions[] = 'u.user_office_id = ?';
            $params[]     = $userOfficeId;
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY u.username ASC';

        return $this->db->query($sql, $params)->getResultArray();
    }

    private function preserveRelationsBeforeDelete(BaseConnection $db, string $type, int $id): void
    {
        match ($type) {
            'entity_table'    => $this->guardEntityDelete($db, $id),
            'unit_table'      => $this->guardUnitDelete($db, $id),
            'type_of_product' => $this->guardTypeDelete($db, $id),
            'office_table'    => $this->guardOfficeDelete($db, $id),
            'user_office_table' => $this->guardUserOfficeDelete($db, $id),
            // reference_table: nullify only — references are optional metadata
            'reference_table' => $db->table('transaction_table')->where('reference_id', $id)->set('reference_id', null)->update(),
            default           => null,
        };
    }

    private function guardEntityDelete(BaseConnection $db, int $id): void
    {
        $count = (int) $db->table('product_table')->where('entity_id', $id)->countAllResults();
        if ($count > 0) {
            throw new \DomainException(
                "Cannot delete: this entity is used by {$count} product(s). Remove or reassign them first."
            );
        }
    }

    private function guardUnitDelete(BaseConnection $db, int $id): void
    {
        $count = (int) $db->table('product_table')->where('unit_id', $id)->countAllResults();
        if ($count > 0) {
            throw new \DomainException(
                "Cannot delete: this unit is used by {$count} product(s). Remove or reassign them first."
            );
        }
    }

    private function guardTypeDelete(BaseConnection $db, int $id): void
    {
        $count = (int) $db->table('product_table')->where('type_id', $id)->countAllResults();
        if ($count > 0) {
            throw new \DomainException(
                "Cannot delete: this product type is used by {$count} product(s). Remove or reassign them first."
            );
        }
    }

    private function guardOfficeDelete(BaseConnection $db, int $id): void
    {
        $txnCount   = (int) $db->table('transaction_table')->where('office_id', $id)->countAllResults();
        $batchCount = (int) $db->table('batch_table')->where('office_id', $id)->countAllResults();
        $total = $txnCount + $batchCount;
        if ($total > 0) {
            throw new \DomainException(
                "Cannot delete: this office is referenced by {$total} transaction(s) or batch(es)."
            );
        }
    }

    private function guardUserOfficeDelete(BaseConnection $db, int $id): void
    {
        $users    = (int) $db->table('user_table')->where('user_office_id', $id)->countAllResults();
        $products = (int) $db->table('product_table')->where('user_office_id', $id)->countAllResults();
        $batches  = (int) $db->table('batch_table')->where('user_office_id', $id)->countAllResults();

        $issues = [];
        if ($users    > 0) $issues[] = "{$users} user(s)";
        if ($products > 0) $issues[] = "{$products} product(s)";
        if ($batches  > 0) $issues[] = "{$batches} batch(es)";

        if ($issues) {
            throw new \DomainException(
                'Cannot delete: this user office is still used by ' . implode(', ', $issues) . '.'
            );
        }
    }

    private function clearOfficeRelations(BaseConnection $db, int $officeId): void
    {
        $db->table('transaction_table')->where('office_id', $officeId)->set('office_id', null)->update();
        $db->table('batch_table')->where('office_id', $officeId)->set('office_id', null)->update();
    }
}
