<?php

namespace App\Models;

use CodeIgniter\Model;

class UnitModel extends Model
{
    protected $table                 = 'unit_table';
    protected $primaryKey            = 'unit_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['unit', 'user_office_id'];
    protected bool $allowEmptyInserts = false;

    public function orderedList(int $userOfficeId = 0): array
    {
        $builder = $this->orderBy('unit', 'ASC');
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

        $existing = $this->where('unit', $name)
                         ->where('user_office_id', $userOfficeId)
                         ->first();
        if ($existing) {
            return (int) $existing['unit_id'];
        }

        $id = $this->insert([
            'unit'           => $name,
            'user_office_id' => $userOfficeId,
        ]);
        return (int) $id;
    }
}
