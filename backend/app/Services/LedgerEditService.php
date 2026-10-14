<?php

namespace App\Services;

use App\Libraries\AuditLog;
use App\Models\TransactionModel;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

/**
 * Applies the edits made inline on the Stock Card and the Summary Report.
 *
 * One stock card row can stand for several ledger entries (a stock-out taken from several
 * batches is saved as one entry per batch), so every change names the whole group of
 * transaction ids it covers. All changes are saved in one database transaction: either
 * every change is kept or none is.
 */
class LedgerEditService
{
    /** Most rows one save may change; keeps a single request quick */
    public const MAX_CHANGES = 500;

    /** Most ledger entries one row may stand for */
    private const MAX_GROUP_SIZE = 100;

    private const EPSILON = 0.000001;

    /** @var array<int, array> batch_id => batch row, updated as changes are applied */
    private array $batches = [];

    /** @var array<int, true> products whose ledger changed */
    private array $products = [];

    /** @var array<int, int> product_id => the office whose stock card it is on */
    private array $productOffice = [];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<array> $edits   [{ transaction_ids: int[], qty?, unit_cost?, reference?, office?, date?, expiration_date?, type? }]
     * @param list<array> $deletes [int[], ...] each one row's transaction ids
     * @return array{saved: int, products: list<int>}
     */
    public function apply(array $edits, array $deletes, int $userOfficeId): array
    {
        if (count($edits) + count($deletes) === 0) {
            throw new DomainException('There are no changes to save.');
        }
        if (count($edits) + count($deletes) > self::MAX_CHANGES) {
            throw new DomainException('Save at most ' . self::MAX_CHANGES . ' rows at a time.');
        }

        // Load every row named in the request once, and check no entry is named twice
        $groups  = [];
        $seenIds = [];
        foreach ([...array_map(fn($ids) => ['transaction_ids' => $ids, '_delete' => true], $deletes), ...$edits] as $change) {
            $ids = $this->ids($change['transaction_ids'] ?? null);
            foreach ($ids as $id) {
                if (isset($seenIds[$id])) {
                    throw new DomainException('Entry #' . $id . ' was changed twice in one save. Reload the page and try again.');
                }
                $seenIds[$id] = true;
            }
            $groups[] = ['ids' => $ids, 'change' => $change];
        }

        $rows = $this->loadTransactions(array_keys($seenIds), $userOfficeId);

        // Lowest balance of each product's card before the changes (see checkBalances)
        foreach ($rows as $row) {
            $this->productOffice[(int) $row['product_id']] = (int) $row['batch_office_id'];
        }
        $before = [];
        foreach ($this->productOffice as $productId => $officeId) {
            $before[$productId] = $this->lowestBalance($productId, $officeId);
        }

        $this->db->transBegin();
        try {
            $saved = 0;
            foreach ($groups as $group) {
                $txns = array_map(fn($id) => $rows[$id], $group['ids']);
                $this->assertOneRow($txns);

                if (! empty($group['change']['_delete'])) {
                    $this->deleteGroup($txns);
                } else {
                    $this->editGroup($txns, $group['change']);
                }
                $saved++;
            }

            $this->checkBalances($before);
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return ['saved' => $saved, 'products' => array_keys($this->products)];
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        $ids = is_array($value) ? $value : [$value];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));

        if ($ids === [] || count($ids) > self::MAX_GROUP_SIZE) {
            throw new DomainException('A change does not name a valid stock card entry.');
        }

        return $ids;
    }

    /** @return array<int, array> transaction_id => row (with its batch and type) */
    private function loadTransactions(array $ids, int $userOfficeId): array
    {
        $rows = $this->db->table('transaction_table t')
            ->select('t.*, tt.transaction_type, b.product_id, b.batch_no, b.user_office_id AS batch_office_id')
            ->join('transaction_type_table tt', 'tt.transaction_type_id = t.transaction_type_id')
            ->join('batch_table b', 'b.batch_id = t.batch_id')
            ->whereIn('t.transaction_id', $ids)
            ->get()->getResultArray();

        $byId = [];
        foreach ($rows as $row) {
            // Another office's entries are reported as missing, like the single-entry endpoints do
            if ($userOfficeId > 0 && (int) $row['batch_office_id'] !== $userOfficeId) {
                continue;
            }
            $byId[(int) $row['transaction_id']] = $row;
        }

        $missing = array_diff($ids, array_keys($byId));
        if ($missing !== []) {
            throw new DomainException('Entry #' . reset($missing) . ' was not found. It may have been deleted; reload the page.');
        }

        return $byId;
    }

    /** The entries of one row must be the same movement of the same product */
    private function assertOneRow(array $txns): void
    {
        $first = $txns[0];
        foreach ($txns as $txn) {
            if ((int) $txn['product_id'] !== (int) $first['product_id'] || $txn['transaction_type'] !== $first['transaction_type']) {
                throw new DomainException('Entries #' . $first['transaction_id'] . ' and #' . $txn['transaction_id'] . ' are not one stock card row. Reload the page and try again.');
            }
        }
    }

    private function editGroup(array $txns, array $change): void
    {
        $label    = $this->label($txns);
        $type     = $txns[0]['transaction_type'];
        $isBorrow = $this->isBorrowLinked($txns);
        $before   = array_map(fn($t) => $this->auditFields($t), $txns);
        $updates  = [];   // transaction_id => fields
        $notes    = [];

        // Movement type: a single receipt or issue can be switched to the other
        $newType = isset($change['type']) && $change['type'] !== '' ? (string) $change['type'] : $type;
        if ($newType !== $type) {
            if (count($txns) > 1 || $isBorrow || ! in_array($type, ['receipt', 'issue'], true) || ! in_array($newType, ['receipt', 'issue'], true)) {
                throw new DomainException("{$label}: only a single Receipt or Issue entry can be switched between Receipt and Issue.");
            }
        }

        // Quantity (the row's total) and type, with their effect on the batches
        $oldTotal = array_sum(array_map(fn($t) => (float) $t['transaction_qty'], $txns));
        $newTotal = array_key_exists('qty', $change) && $change['qty'] !== null && $change['qty'] !== '' ? (float) $change['qty'] : $oldTotal;

        if (! is_finite($newTotal) || $newTotal <= 0) {
            throw new DomainException("{$label}: the quantity must be greater than 0.");
        }
        if ($isBorrow && (abs($newTotal - $oldTotal) > self::EPSILON || $newType !== $type)) {
            throw new DomainException("{$label}: this entry belongs to a borrow record, so its quantity cannot be changed here. Record a Return instead.");
        }

        if ($newType !== $type) {
            $txn = $txns[0];
            $this->moveBatch((int) $txn['batch_id'], -$this->effect($type, (float) $txn['transaction_qty']) + $this->effect($newType, $newTotal), $label);
            $updates[(int) $txn['transaction_id']]['transaction_qty']     = $newTotal;
            $updates[(int) $txn['transaction_id']]['transaction_type_id'] = $this->typeId($newType);
            $notes[] = "type {$type} → {$newType}";
        } elseif (abs($newTotal - $oldTotal) > self::EPSILON) {
            // Entries added from other batches take the row's other changes too
            $txns = [...$txns, ...$this->changeGroupQty($txns, $newTotal - $oldTotal, $type, $label, $updates)];
        }
        if (abs($newTotal - $oldTotal) > self::EPSILON) {
            $notes[] = 'qty ' . $this->num($oldTotal) . ' → ' . $this->num($newTotal);
        }

        // Unit cost: the price shown on the card and used by the Summary Report
        if (array_key_exists('unit_cost', $change) && $change['unit_cost'] !== null && $change['unit_cost'] !== '') {
            $cost = (float) $change['unit_cost'];
            if (! is_finite($cost) || $cost < 0) {
                throw new DomainException("{$label}: the unit cost cannot be negative.");
            }
            foreach ($txns as $txn) {
                $updates[(int) $txn['transaction_id']]['transaction_unit_cost'] = round($cost, 4);
            }
            $notes[] = 'unit cost ' . number_format($cost, 2);
        }

        // Reference and office / source: free text, matched to (or added to) the office's lists
        $officeScope = ['user_office_id' => (int) $txns[0]['batch_office_id']];
        $inventory   = new InventoryService($this->db);
        if (array_key_exists('reference', $change)) {
            $referenceId = $inventory->resolveReferenceId($officeScope + ['reference' => $this->text($change['reference'], 'reference', $label)]);
            foreach ($txns as $txn) {
                $updates[(int) $txn['transaction_id']]['reference_id'] = $referenceId;
            }
            $notes[] = 'reference';
        }
        if (array_key_exists('office', $change)) {
            $officeId = $inventory->resolveOfficeId($officeScope + ['office' => $this->text($change['office'], 'office', $label)]);
            foreach ($txns as $txn) {
                $updates[(int) $txn['transaction_id']]['office_id'] = $officeId;
            }
            $notes[] = 'office';
        }

        // Date: each entry keeps its time of day, so the row's entries stay together and in order
        if (array_key_exists('date', $change) && (string) $change['date'] !== '') {
            $date = (string) $change['date'];
            if (! $this->isDate($date)) {
                throw new DomainException("{$label}: the date is not valid.");
            }
            if ($date > date('Y-m-d')) {
                throw new DomainException("{$label}: the date cannot be in the future.");
            }
            if ($isBorrow) {
                throw new DomainException("{$label}: this entry belongs to a borrow record, so its date cannot be changed here.");
            }
            foreach ($txns as $txn) {
                $time = substr((string) $txn['transaction_date'], 11, 8) ?: '00:00:00';
                $updates[(int) $txn['transaction_id']]['transaction_date'] = $date . ' ' . $time;
            }
            $notes[] = 'date';
        }

        // Expiry belongs to the batch that a receipt or return created
        if (array_key_exists('expiration_date', $change)) {
            $expiry = trim((string) $change['expiration_date']);
            if ($expiry !== '' && ! $this->isDate($expiry)) {
                throw new DomainException("{$label}: the expiry date is not valid.");
            }
            if (! in_array($type, ['receipt', 'return'], true)) {
                throw new DomainException("{$label}: only receipts and returns carry an expiry date.");
            }
            foreach (array_unique(array_map(fn($t) => (int) $t['batch_id'], $txns)) as $batchId) {
                $this->db->table('batch_table')->where('batch_id', $batchId)->update([
                    'expiration_date' => $expiry === '' ? null : $expiry,
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);
            }
            $notes[] = 'expiry';
        }

        if ($notes === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($updates as $id => $fields) {
            if (($fields['transaction_qty'] ?? null) === 0.0) {
                continue;   // emptied by a smaller quantity; removed in changeGroupQty
            }
            $this->db->table('transaction_table')->where('transaction_id', $id)->update($fields + ['updated_at' => $now]);
        }

        $this->products[(int) $txns[0]['product_id']] = true;

        $after = $this->db->table('transaction_table')->whereIn('transaction_id', array_column($txns, 'transaction_id'))->get()->getResultArray();
        AuditLog::record('stock.transaction_edited', 'transaction', (int) $txns[0]['transaction_id'],
            'Edited ledger entry #' . $txns[0]['transaction_id'] . ' of ' . $this->productName((int) $txns[0]['product_id']) . ' (' . implode(', ', $notes) . ')',
            [
                'transaction_ids' => array_map('intval', array_column($txns, 'transaction_id')),
                'before'          => $before,
                'after'           => array_map(fn($t) => $this->auditFields($t), $after),
            ]
        );
    }

    /**
     * Spread a quantity change over a row's entries. Less comes off the last entries first,
     * removing any that reach 0. More goes on the last entry; for a stock-out whose batch
     * runs short, the rest is taken from the product's other batches the way a new
     * stock-out would (oldest first), as extra entries of the same row.
     *
     * @return list<array> the entries added to the row
     */
    private function changeGroupQty(array $txns, float $diff, string $type, string $label, array &$updates): array
    {
        usort($txns, fn($a, $b) => (int) $a['transaction_id'] <=> (int) $b['transaction_id']);

        if ($diff > 0) {
            $last    = end($txns);
            $batchId = (int) $last['batch_id'];
            $own     = $diff;

            if (! in_array($type, TransactionModel::STOCK_IN_TYPES, true)) {
                $this->batches[$batchId] ??= $this->db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray();
                $own = min($diff, max(0.0, (float) ($this->batches[$batchId]['current_qty'] ?? 0)));
            }

            if ($own > self::EPSILON) {
                $this->moveBatch($batchId, $this->effect($type, $own), $label);
                $updates[(int) $last['transaction_id']]['transaction_qty'] = (float) $last['transaction_qty'] + $own;
            }

            return $diff - $own > self::EPSILON ? $this->takeFromOtherBatches($last, $diff - $own, $type, $label) : [];
        }

        $remove = -$diff;
        for ($i = count($txns) - 1; $i >= 0 && $remove > self::EPSILON; $i--) {
            $txn  = $txns[$i];
            $qty  = (float) $txn['transaction_qty'];
            $take = min($qty, $remove);
            $this->moveBatch((int) $txn['batch_id'], -$this->effect($type, $take), $label);
            $remove -= $take;

            if ($qty - $take <= self::EPSILON) {
                // The first entry always keeps some quantity: the new total is above 0
                $this->db->table('transaction_table')->where('transaction_id', (int) $txn['transaction_id'])->delete();
                $updates[(int) $txn['transaction_id']]['transaction_qty'] = 0.0;
            } else {
                $updates[(int) $txn['transaction_id']]['transaction_qty'] = $qty - $take;
            }
        }

        return [];
    }

    /** New entries for $qty more of a stock-out, copied from the row's last entry */
    private function takeFromOtherBatches(array $last, float $qty, string $type, string $label): array
    {
        $mode = $type === 'adjust_out' ? InventoryService::MODE_ADJUST : InventoryService::MODE_ISSUE;
        $plan = (new InventoryService($this->db))->planDepletion(
            (int) $last['product_id'], $qty, (int) $last['batch_office_id'], (int) ($last['copy_id'] ?? 0), $mode
        );

        if ($plan['shortfall'] > self::EPSILON) {
            throw new DomainException("{$label}: only " . $this->num($qty - $plan['shortfall']) . ' more is in stock, so the quantity can go up by at most that much.');
        }

        $added = [];
        $now   = date('Y-m-d H:i:s');
        foreach ($plan['batches'] as $batch) {
            if ($batch['take'] <= self::EPSILON) {
                continue;
            }

            $this->moveBatch((int) $batch['batch_id'], -$batch['take'], $label);
            $row = array_intersect_key($last, array_flip([
                'transaction_type_id', 'transaction_unit_cost', 'transaction_date', 'copy_id', 'reference_id',
                'office_id', 'user_id', 'user_office_id', 'adjustment_reason_id',
            ]));
            $this->db->table('transaction_table')->insert($row + [
                'transaction_qty' => $batch['take'],
                'batch_id'        => (int) $batch['batch_id'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $added[] = ['transaction_id' => (int) $this->db->insertID(), 'batch_id' => (int) $batch['batch_id'], 'transaction_qty' => $batch['take']] + $row + $last;
        }

        return $added;
    }

    private function deleteGroup(array $txns): void
    {
        $label = $this->label($txns);
        if ($this->isBorrowLinked($txns)) {
            throw new DomainException("{$label}: this entry belongs to a borrow record and cannot be deleted; the borrow history would no longer add up.");
        }

        foreach ($txns as $txn) {
            $this->moveBatch((int) $txn['batch_id'], -$this->effect($txn['transaction_type'], (float) $txn['transaction_qty']), $label);
            $this->db->table('transaction_table')->where('transaction_id', (int) $txn['transaction_id'])->delete();
        }

        $this->products[(int) $txns[0]['product_id']] = true;

        AuditLog::record('stock.transaction_deleted', 'transaction', (int) $txns[0]['transaction_id'],
            'Deleted ledger entry #' . $txns[0]['transaction_id'] . ' of ' . $this->productName((int) $txns[0]['product_id'])
                . ' (qty ' . $this->num(array_sum(array_map(fn($t) => (float) $t['transaction_qty'], $txns))) . ')',
            ['deleted' => array_map(fn($t) => $this->auditFields($t), $txns)]
        );
    }

    /** Add $delta to a batch's quantity on hand; it may not go below 0 */
    private function moveBatch(int $batchId, float $delta, string $label): void
    {
        if (abs($delta) <= self::EPSILON) {
            return;
        }

        $this->batches[$batchId] ??= $this->db->table('batch_table')->where('batch_id', $batchId)->get(1)->getRowArray()
            ?? throw new DomainException("{$label}: its batch no longer exists.");

        $newQty = round((float) $this->batches[$batchId]['current_qty'] + $delta, 4);
        if ($newQty < -self::EPSILON) {
            $left = $this->num((float) $this->batches[$batchId]['current_qty']);
            throw new DomainException("{$label}: batch {$this->batches[$batchId]['batch_no']} has only {$left} on hand, so this change would take it below 0. Some of it may already be issued.");
        }

        $this->batches[$batchId]['current_qty'] = max(0, $newQty);
        $this->db->table('batch_table')->where('batch_id', $batchId)->update([
            'current_qty' => max(0, $newQty),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** What an entry of this type and quantity does to its batch */
    private function effect(string $type, float $qty): float
    {
        return in_array($type, TransactionModel::STOCK_IN_TYPES, true) ? $qty : -$qty;
    }

    /**
     * A change may not make a product's running balance go below 0 at any date (for
     * example moving a receipt after the issues it supplied), unless it already did.
     */
    private function checkBalances(array $before): void
    {
        foreach (array_keys($this->products) as $productId) {
            $after = $this->lowestBalance($productId, $this->productOffice[$productId] ?? 0);

            if ($after['balance'] < -self::EPSILON && $after['balance'] < ($before[$productId]['balance'] ?? 0) - self::EPSILON) {
                throw new DomainException(
                    'These changes would take the stock card of ' . $this->productName($productId) . ' down to '
                    . $this->num($after['balance']) . ' on ' . substr((string) $after['date'], 0, 10) . '. '
                    . 'Check the dates and quantities of the receipts before that day.'
                );
            }
        }
    }

    /** The product's lowest running balance, worked out the same way the stock card does */
    private function lowestBalance(int $productId, int $officeId): array
    {
        $officeFilter = $officeId > 0 ? ' AND t.user_office_id = ' . $officeId : '';
        $row = $this->db->query(
            "SELECT balance, date FROM (
                SELECT t.transaction_date AS date,
                       SUM(CASE WHEN tt.transaction_type IN ('receipt', 'return', 'adjust_in') THEN t.transaction_qty ELSE -t.transaction_qty END)
                           OVER (ORDER BY t.transaction_date ASC, t.transaction_id ASC) AS balance
                FROM transaction_table t
                INNER JOIN transaction_type_table tt ON t.transaction_type_id = tt.transaction_type_id
                INNER JOIN batch_table b ON t.batch_id = b.batch_id
                WHERE b.product_id = ?{$officeFilter}
                  AND tt.transaction_type IN ('receipt','issue','adjust_out','borrow','return','adjust_in')
             ) AS running
             ORDER BY balance ASC, date ASC
             LIMIT 1",
            [$productId]
        )->getRowArray();

        return ['balance' => (float) ($row['balance'] ?? 0), 'date' => $row['date'] ?? ''];
    }

    private function isBorrowLinked(array $txns): bool
    {
        foreach ($txns as $txn) {
            if (! empty($txn['borrow_id'])) {
                return true;
            }
        }

        return false;
    }

    private function typeId(string $type): int
    {
        $row = $this->db->table('transaction_type_table')->select('transaction_type_id')->where('transaction_type', $type)->get(1)->getRowArray();

        return (int) ($row['transaction_type_id'] ?? throw new DomainException("Unknown movement type {$type}."));
    }

    private function text(mixed $value, string $field, string $label): string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > 150) {
            throw new DomainException("{$label}: the {$field} is too long (150 characters at most).");
        }

        return $text;
    }

    private function isDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** "Flour, 2026-10-03 receipt" — names the row in error messages */
    private function label(array $txns): string
    {
        return $this->productName((int) $txns[0]['product_id']) . ', ' . substr((string) $txns[0]['transaction_date'], 0, 10) . ' ' . str_replace('_', ' ', $txns[0]['transaction_type']);
    }

    private array $productNames = [];

    private function productName(int $productId): string
    {
        return $this->productNames[$productId] ??= ($this->db->table('product_table')->select('product')->where('product_id', $productId)->get(1)->getRowArray()['product'] ?? "product #{$productId}");
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function auditFields(array $txn): array
    {
        return array_intersect_key($txn, array_flip([
            'transaction_id', 'transaction_type_id', 'transaction_qty', 'transaction_unit_cost', 'transaction_date',
            'batch_id', 'copy_id', 'office_id', 'reference_id', 'user_id', 'adjustment_reason_id',
        ]));
    }
}
