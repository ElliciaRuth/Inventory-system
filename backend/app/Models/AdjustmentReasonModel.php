<?php

namespace App\Models;

use CodeIgniter\Model;

class AdjustmentReasonModel extends Model
{
    /** Reasons offered for each kind of adjustment ("Physical Count" is set by the count itself). */
    public const ADJUST_OUT = ['Spoiled', 'Damaged', 'Correction', 'Others'];
    public const ADJUST_IN  = ['Wrong Count', 'Others'];

    protected $table                 = 'adjustment_reason';
    protected $primaryKey            = 'adjustment_reason_id';
    protected $returnType            = 'array';
    protected $allowedFields         = ['adjustment_reason'];
    protected bool $allowEmptyInserts = false;

    /** The reasons offered for 'adjust_out' or 'adjust_in', in the order listed above. */
    public function forType(string $type): array
    {
        $names = $type === 'adjust_in' ? self::ADJUST_IN : self::ADJUST_OUT;
        $rows  = $this->whereIn('adjustment_reason', $names)->findAll();
        usort($rows, static fn (array $a, array $b) => array_search($a['adjustment_reason'], $names, true) <=> array_search($b['adjustment_reason'], $names, true));

        return $rows;
    }
}
