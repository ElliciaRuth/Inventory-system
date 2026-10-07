<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductModel extends Model
{
    protected $table                 = 'product_table';
    protected $primaryKey            = 'product_id';
    protected $returnType            = 'array';
    protected $allowedFields         = [
        'product_no',
        'product',
        'product_description',
        'measurement',
        'product_reorder_point',
        'expiry_warning_days',
        'expiry_danger_days',
        'entity_id',
        'unit_id',
        'type_id',
        'user_office_id',
        'stock_no',
    ];
    protected bool $allowEmptyInserts = false;

    public function listForSelect(int $userOfficeId = 0): array
    {
        $builder = $this->select('product_table.product_id, product_table.product, product_table.product_no, product_table.product_description, product_table.measurement, unit_table.unit')
            ->join('unit_table', 'product_table.unit_id = unit_table.unit_id', 'left')
            ->orderBy('product_table.product', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('product_table.user_office_id', $userOfficeId);
        }
        return $builder->findAll();
    }

    public function firstProductId(int $userOfficeId = 0): int
    {
        $builder = $this->select('product_id')
            ->orderBy('product', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        $row = $builder->first();
        return (int) ($row['product_id'] ?? 0);
    }

    public function findProduct(int $id): ?array
    {
        $builder = $this->db->table($this->table);
        $builder->select('product_table.*, entity_table.entity as entity_name, unit_table.unit as unit_name, type_of_product.type as type_name');
        $builder->join('entity_table', 'product_table.entity_id = entity_table.entity_id', 'left');
        $builder->join('unit_table', 'product_table.unit_id = unit_table.unit_id', 'left');
        $builder->join('type_of_product', 'product_table.type_id = type_of_product.type_id', 'left');
        $builder->where('product_table.product_id', $id);
        
        $product = $builder->get()->getRowArray();
        if (!$product) {
            return null;
        }
        $product['product_reorder_point'] = (int) ($product['product_reorder_point'] ?? 0);
        $product['entity_name']           = $product['entity_name'] ?? '';
        $product['unit_name']             = $product['unit_name'] ?? '';
        $product['type_name']             = $product['type_name'] ?? '';
        return $product;
    }

    public function searchProducts(string $search = '', int $userOfficeId = 0, int $typeId = 0): array
    {
        $builder = $this->db->table($this->table);
        $builder->select(
            'product_table.product_id,
             product_table.product_no,
             product_table.product,
             product_table.stock_no,
             product_table.measurement,
             COALESCE(unit_table.unit, "Deleted Unit") AS unit_name,
             COALESCE(SUM(batch_table.current_qty), 0) AS total_stock,
             COALESCE(type_of_product.type, "") AS type_name'
        );
        $builder->join('batch_table', 'product_table.product_id = batch_table.product_id', 'left');
        $builder->join('unit_table', 'product_table.unit_id = unit_table.unit_id', 'left');
        $builder->join('type_of_product', 'product_table.type_id = type_of_product.type_id', 'left');

        if ($userOfficeId > 0) {
            $builder->where('product_table.user_office_id', $userOfficeId);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('product_table.product', $search)
                ->orLike('product_table.product_description', $search)
                ->orLike('product_table.product_no', $search)
                ->groupEnd();
        }

        if ($typeId > 0) {
            $builder->where('product_table.type_id', $typeId);
        }

        return $builder
            ->groupBy('product_table.product_id, product_table.product_no, product_table.product, product_table.stock_no, product_table.measurement, unit_table.unit, type_of_product.type')
            ->orderBy('product_table.product_no', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function stockcardInfo(int $productId): array
    {
        return $this->db->query(
            'SELECT
                product_table.product AS item_name,
                product_table.product_description AS description,
                product_table.stock_no,
                product_table.product_no,
                COALESCE(product_table.measurement, "") AS measurement,
                COALESCE(unit_table.unit, "Deleted Unit") AS unit_name,
                COALESCE(entity_table.entity, "N/A") AS entity_name,
                COALESCE(entity_table.fund_cluster, "-") AS fund_cluster,
                COALESCE(product_table.product_reorder_point, 0) AS re_order_point
             FROM product_table
             LEFT JOIN unit_table ON product_table.unit_id = unit_table.unit_id
             LEFT JOIN entity_table ON product_table.entity_id = entity_table.entity_id
             WHERE product_table.product_id = ?
             LIMIT 1',
            [$productId]
        )->getRowArray() ?? [];
    }

    /**
     * Generate and store the stock_no for a product.
     * Format: {user_office_name}-{product_no}
     */
    public function generateStockNo(int $productId, string $userOfficeName, int $productNo): string
    {
        $stockNo = strtoupper($userOfficeName) . '-' . str_pad((string) $productNo, 4, '0', STR_PAD_LEFT);
        $this->update($productId, ['stock_no' => $stockNo]);
        return $stockNo;
    }
}
