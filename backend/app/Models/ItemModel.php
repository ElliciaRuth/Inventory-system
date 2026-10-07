<?php

namespace App\Models;

use CodeIgniter\Model;

class ItemModel extends Model
{
    protected $table                 = 'item';
    protected $primaryKey            = 'item_id';
    protected $returnType            = 'array';
    protected $allowedFields         = [
        'item_no',
        'item',
        'description',
        're_order_point',
        'entity_id',
        'unit_id',
        'item_type_id',
        'item_category_id',
        'user_office_id',
    ];
    protected bool $allowEmptyInserts = false;

    public function listForSelect(int $userOfficeId = 0): array
    {
        $builder = $this->select('item_id, item, item_no, description')
            ->orderBy('item', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        return $builder->findAll();
    }

    public function firstItemId(int $userOfficeId = 0): int
    {
        $builder = $this->select('item_id')
            ->orderBy('item', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }
        $row = $builder->first();

        return (int) ($row['item_id'] ?? 0);
    }

    public function findProduct(int $id): ?array
    {
        $product = $this->find($id);

        if ($product === null) {
            return null;
        }

        $product['re_order_point'] = (int) ($product['re_order_point'] ?? 0);
        $product['entity_id'] = $product['entity_id'] ?? '';
        $product['unit_id'] = $product['unit_id'] ?? '';
        $product['item_type_id'] = $product['item_type_id'] ?? '';
        $product['item_category_id'] = $product['item_category_id'] ?? '';

        return $product;
    }

    public function searchProducts(string $search = '', int $userOfficeId = 0): array
    {
        $builder = $this->db->table($this->table);
        $builder->select('item.item_id, item.item_no, item.item,
                CONCAT(COALESCE(item_category.item_category, "Uncategorized"), "-", item.item_no) AS stockcard_no,
                COALESCE(unit.unit, "Deleted Unit") AS unit_name,
                COALESCE(SUM(batch.remaining_qty), 0) AS total_stock');
        $builder->join('batch', 'item.item_id = batch.item_id', 'left');
        $builder->join('unit', 'item.unit_id = unit.unit_id', 'left');
        $builder->join('item_category', 'item.item_category_id = item_category.item_category_id', 'left');

        if ($userOfficeId > 0) {
            $builder->where('item.user_office_id', $userOfficeId);
        }

        if ($search !== '') {
            $builder->groupStart()
                ->like('item.item', $search)
                ->orLike('item.description', $search)
                ->orLike('item.item_no', $search)
                ->groupEnd();
        }

        return $builder
            ->groupBy('item.item_id, item.item_no, item.item, item_category.item_category, unit.unit')
            ->orderBy('item.item_no', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function stockcardInfo(int $itemId): array
    {
        return $this->db->query(
            'SELECT item.item AS item_name, item.description, CONCAT(COALESCE(item_category.item_category, "Uncategorized"), "-", item.item_no) AS stockcard_no,
                    item.item_no, unit.unit AS unit_name, entity.entity_name, entity.fund_cluster,
                    COALESCE(item.re_order_point, 0) AS re_order_point
             FROM item
             LEFT JOIN unit ON item.unit_id = unit.unit_id
             LEFT JOIN entity ON item.entity_id = entity.entity_id
             LEFT JOIN item_category ON item_category.item_category_id = item.item_category_id
             WHERE item.item_id = ?
             LIMIT 1',
            [$itemId],
        )->getRowArray() ?? [];
    }
}
