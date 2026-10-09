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
        'archived_at',
        'archived_by',
        'archive_reason',
    ];
    protected bool $allowEmptyInserts = false;

    /**
     * Active products for pickers. $alsoProductId keeps one archived product in the list
     * (the one whose stockcard is open), flagged by its archived_at.
     */
    public function listForSelect(int $userOfficeId = 0, int $alsoProductId = 0): array
    {
        $builder = $this->select('product_table.product_id, product_table.product, product_table.product_no, product_table.stock_no, product_table.product_description, product_table.measurement, product_table.archived_at, unit_table.unit')
            ->join('unit_table', 'product_table.unit_id = unit_table.unit_id', 'left')
            ->groupStart()
                ->where('product_table.archived_at', null)
                ->orWhere('product_table.product_id', $alsoProductId)
            ->groupEnd()
            ->orderBy('product_table.product', 'ASC');
        if ($userOfficeId > 0) {
            $builder->where('product_table.user_office_id', $userOfficeId);
        }
        return $builder->findAll();
    }

    public function isArchived(int $productId): bool
    {
        $row = $this->db->table($this->table)->select('archived_at')->where('product_id', $productId)->get(1)->getRowArray();

        return ! empty($row['archived_at']);
    }

    /**
     * Why a product can't be archived yet (empty = it can): stock on hand,
     * stock-out requests still waiting, or stock lent out and not returned.
     *
     * @return list<string>
     */
    public function archiveBlockers(int $productId, int $userOfficeId): array
    {
        $db       = $this->db;
        $blockers = [];

        $stock = (float) ($db->table('batch_table')->selectSum('current_qty', 'q')->where('product_id', $productId)
            ->where('user_office_id', $userOfficeId)->get()->getRowArray()['q'] ?? 0);
        if ($stock > 0) {
            $blockers[] = rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.') . ' still in stock: issue it, or bring it to 0 with Adjust Out first';
        }

        $pending = $db->table('temp_stockout_item i')
            ->join('temp_stockout t', 't.temp_stockout_id = i.temp_stockout_id')
            ->where('i.product_id', $productId)
            ->where('i.status', 'pending')
            ->where('t.status', 'pending')
            ->countAllResults();
        if ($pending > 0) {
            $blockers[] = "{$pending} stock-out request item(s) still waiting; accept or reject them first";
        }

        if ($db->tableExists('borrow_table')) {
            $open = $db->table('borrow_table')->where('product_id', $productId)->where('status !=', 'returned')->countAllResults();
            if ($open > 0) {
                $blockers[] = "{$open} borrow(s) not yet returned";
            }
        }

        return $blockers;
    }

    public function firstProductId(int $userOfficeId = 0): int
    {
        $builder = $this->select('product_id')
            ->where('archived_at', null)
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

    /** $archived false: active products; true: the archived ones. */
    public function searchProducts(string $search = '', int $userOfficeId = 0, int $typeId = 0, bool $archived = false): array
    {
        $builder = $this->db->table($this->table);
        $builder->select(
            'product_table.product_id,
             product_table.product_no,
             product_table.product,
             product_table.stock_no,
             product_table.measurement,
             product_table.archived_at,
             product_table.archive_reason,
             COALESCE(au.username, "") AS archived_by_name,
             COALESCE(unit_table.unit, "Deleted Unit") AS unit_name,
             COALESCE(SUM(batch_table.current_qty), 0) AS total_stock,
             COALESCE(type_of_product.type, "") AS type_name'
        );
        $builder->join('batch_table', 'product_table.product_id = batch_table.product_id', 'left');
        $builder->join('unit_table', 'product_table.unit_id = unit_table.unit_id', 'left');
        $builder->join('type_of_product', 'product_table.type_id = type_of_product.type_id', 'left');
        $builder->join('user_table au', 'au.user_id = product_table.archived_by', 'left');
        $archived
            ? $builder->where('product_table.archived_at IS NOT NULL', null, false)
            : $builder->where('product_table.archived_at', null);

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
            ->groupBy('product_table.product_id, product_table.product_no, product_table.product, product_table.stock_no, product_table.measurement, product_table.archived_at, product_table.archive_reason, au.username, unit_table.unit, type_of_product.type')
            ->orderBy($archived ? 'product_table.archived_at' : 'product_table.product_no', $archived ? 'DESC' : 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * The product's batches that have an expiration date, soonest first,
     * for the stockcard's expiry warnings.
     */
    public function datedBatches(int $productId, int $userOfficeId = 0): array
    {
        $builder = $this->db->table('batch_table')
            ->select('batch_id, batch_no, current_qty, manufacturing_date, expiration_date,
                      DATEDIFF(expiration_date, CURDATE()) AS days_left', false)
            ->where('product_id', $productId)
            ->where('expiration_date IS NOT NULL', null, false)
            ->orderBy('expiration_date', 'ASC');

        if ($userOfficeId > 0) {
            $builder->where('user_office_id', $userOfficeId);
        }

        return $builder->get()->getResultArray();
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
                COALESCE(product_table.product_reorder_point, 0) AS re_order_point,
                COALESCE(product_table.expiry_warning_days, 30) AS expiry_warning_days,
                COALESCE(product_table.expiry_danger_days, 7) AS expiry_danger_days,
                product_table.archived_at,
                product_table.archive_reason
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
