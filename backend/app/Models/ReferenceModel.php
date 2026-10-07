<?php

namespace App\Models;

use CodeIgniter\Model;

class ReferenceModel extends Model
{
    protected $table                 = 'reference_table';
    protected $primaryKey            = 'reference_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['reference', 'user_office_id'];
    protected bool $allowEmptyInserts = false;

    public function orderedList(int $userOfficeId = 0): array
    {
        $builder = $this->orderBy('reference', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return $builder->findAll();
    }
}
