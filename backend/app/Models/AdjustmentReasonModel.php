<?php

namespace App\Models;

use CodeIgniter\Model;

class AdjustmentReasonModel extends Model
{
    protected $table                 = 'adjustment_reason';
    protected $primaryKey            = 'adjustment_reason_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['adjustment_reason'];
    protected bool $allowEmptyInserts = false;

    public function orderedList(): array
    {
        return $this->orderBy('adjustment_reason', 'ASC')->findAll();
    }
}
