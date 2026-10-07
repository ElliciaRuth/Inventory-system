<?php

namespace App\Models;

use CodeIgniter\Model;

class EntityModel extends Model
{
    protected $table                 = 'entity_table';
    protected $primaryKey            = 'entity_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['entity', 'fund_cluster', 'user_office_id'];
    protected bool $allowEmptyInserts = false;

    public function orderedList(int $userOfficeId = 0): array
    {
        $builder = $this->orderBy('entity', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return $builder->findAll();
    }

    public function firstOrCreate(string $name, int $userOfficeId): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existing = $this->where('entity', $name)
                         ->where('user_office_id', $userOfficeId)
                         ->first();
        if ($existing) {
            return (int) $existing['entity_id'];
        }

        $id = $this->insert([
            'entity'         => $name,
            'user_office_id' => $userOfficeId,
            'fund_cluster'   => '',
        ]);
        return (int) $id;
    }
}
