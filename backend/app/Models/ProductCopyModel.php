<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * ProductCopyModel — manages price-based sub-products ("copies").
 *
 * Each copy represents a product at a specific unit cost.
 * When a receipt arrives at a new price, a new copy is auto-created.
 * All copies share the same stockcard but have independent stock counts.
 */
class ProductCopyModel extends Model
{
    protected $table                 = 'product_copy_table';
    protected $primaryKey            = 'copy_id';
    protected $returnType            = 'array';
    protected $allowedFields         = [
        'product_id', 'unit_cost', 'label',
        'user_office_id', 'created_at', 'updated_at',
    ];
    protected bool $allowEmptyInserts = false;

    /**
     * Find an existing copy with the same unit cost, or create a new one.
     * Used during receipt processing.
     */
    public function findOrCreateCopy(int $productId, float $unitCost, int $userOfficeId): array
    {
        // Look for an existing copy with the same price (within ₱0.005 tolerance)
        $existing = $this->db->table($this->table)
            ->where('product_id', $productId)
            ->where('ABS(unit_cost - ' . $this->db->escape($unitCost) . ') < 0.005', null, false)
            ->where('user_office_id', $userOfficeId)
            ->get(1)
            ->getRowArray();

        if ($existing) {
            return $existing;
        }

        // Count existing copies for label generation
        $count = (int) $this->where('product_id', $productId)
            ->where('user_office_id', $userOfficeId)
            ->countAllResults();

        $now = date('Y-m-d H:i:s');

        $this->insert([
            'product_id'     => $productId,
            'unit_cost'      => $unitCost,
            'label'          => $count > 0 ? 'Copy ' . ($count + 1) : '',
            'user_office_id' => $userOfficeId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $this->find($this->getInsertID());
    }

    /**
     * List all copies for a product with their current stock.
     * Used to populate dropdowns on issue/borrow/return forms.
     */
    public function copiesForProduct(int $productId, int $userOfficeId = 0): array
    {
        $builder = $this->db->table($this->table . ' AS pc')
            ->select('pc.copy_id, pc.product_id, pc.unit_cost, pc.label,
                       COALESCE(SUM(b.current_qty), 0) AS current_stock')
            ->join('batch_table b', 'pc.copy_id = b.copy_id', 'left')
            ->where('pc.product_id', $productId)
            ->groupBy('pc.copy_id, pc.product_id, pc.unit_cost, pc.label')
            ->orderBy('pc.unit_cost', 'ASC');

        if ($userOfficeId > 0) {
            $builder->where('pc.user_office_id', $userOfficeId);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Get the current stock for a specific copy.
     */
    public function copyStock(int $copyId): float
    {
        $row = $this->db->table('batch_table')
            ->selectSum('current_qty', 'stock')
            ->where('copy_id', $copyId)
            ->get()
            ->getRowArray();

        return (float) ($row['stock'] ?? 0);
    }

    /**
     * Get a single copy by ID with product info.
     */
    public function findCopy(int $copyId): ?array
    {
        return $this->db->table($this->table . ' AS pc')
            ->select('pc.*, p.product AS product_name, COALESCE(ut.unit, "pcs") AS unit_name')
            ->join('product_table p', 'pc.product_id = p.product_id', 'left')
            ->join('unit_table ut', 'p.unit_id = ut.unit_id', 'left')
            ->where('pc.copy_id', $copyId)
            ->get(1)
            ->getRowArray();
    }

    /**
     * Get all copies for all products in a user office (used for bulk loading).
     * Returns keyed by product_id.
     */
    public function allCopiesGrouped(int $userOfficeId = 0): array
    {
        $builder = $this->db->table($this->table . ' AS pc')
            ->select('pc.copy_id, pc.product_id, pc.unit_cost, pc.label,
                       COALESCE(SUM(b.current_qty), 0) AS current_stock')
            ->join('batch_table b', 'pc.copy_id = b.copy_id', 'left')
            ->groupBy('pc.copy_id, pc.product_id, pc.unit_cost, pc.label')
            ->orderBy('pc.product_id', 'ASC')
            ->orderBy('pc.unit_cost', 'ASC');

        if ($userOfficeId > 0) {
            $builder->where('pc.user_office_id', $userOfficeId);
        }

        $rows = $builder->get()->getResultArray();
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }
}
