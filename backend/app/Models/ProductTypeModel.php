<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * ProductTypeModel — replaces ItemTypeModel.
 * Uses table type_of_product with type_id PK and type column.
 */
class ProductTypeModel extends Model
{
    protected $table                 = 'type_of_product';
    protected $primaryKey            = 'type_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['type', 'user_office_id'];
    protected bool $allowEmptyInserts = false;

    public function orderedList(int $userOfficeId = 0): array
    {
        $builder = $this->orderBy('type', 'ASC');
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

        $existing = $this->where('type', $name)
                         ->where('user_office_id', $userOfficeId)
                         ->first();
        if ($existing) {
            return (int) $existing['type_id'];
        }

        $id = $this->insert([
            'type'           => $name,
            'user_office_id' => $userOfficeId,
        ]);
        return (int) $id;
    }
}
