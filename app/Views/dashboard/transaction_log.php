<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>
<?php
    $typeLabels = [
        'receipt'    => ['label' => 'Receipt',    'cls' => 'txn-receipt'],
        'issue'      => ['label' => 'Issue',       'cls' => 'txn-issue'],
        'adjust_out' => ['label' => 'Adjust Out',  'cls' => 'txn-adjust'],
        'borrow'     => ['label' => 'Borrow',      'cls' => 'txn-borrow'],
        'return'     => ['label' => 'Return',      'cls' => 'txn-return'],
    ];

    // Build query string helper (preserves current filters when paginating)
    function txnUrl(array $overrides = []): string {
        $base = ['search' => '', 'type' => '', 'date_from' => '', 'date_to' => '', 'page' => 1, 'limit' => 25];
        $params = array_merge($base, array_filter([
            'search'    => $_GET['search']    ?? '',
            'type'      => $_GET['type']      ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to'   => $_GET['date_to']   ?? '',
            'page'      => $_GET['page']      ?? 1,
            'limit'     => $_GET['limit']     ?? 25,
        ]), $overrides);
        $qs = http_build_query(array_filter($params, fn($v) => $v !== '' && $v != 0));
        return site_url('transactions') . ($qs ? '?' . $qs : '');
    }

    $from = ($page - 1) * $limit + 1;
    $to   = min($page * $limit, $total);
?>
<div class="page-shell">
    <div class="page-header">
        <div>
            <p class="page-eyebrow"><a href="<?= site_url('/') ?>" style="color:inherit;text-decoration:none;">Dashboard</a> &rsaquo; Transaction Log</p>
            <h1>Transaction Log</h1>
            <p class="page-subtitle">Full history of all stock movements — receipts, issues, adjustments, borrows, and returns.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <?php if ($total > 0): ?>
                <span class="txn-count-badge"><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Filters ── -->
    <form method="get" action="<?= site_url('transactions') ?>" class="txn-filter-bar">
        <input
            type="text"
            name="search"
            value="<?= esc($search) ?>"
            placeholder="Search product, stock no, office, reference, user…"
            class="txn-search-input"
        >

        <select name="type" class="txn-select">
            <option value="">All Types</option>
            <?php foreach ($typeLabels as $key => $meta): ?>
                <option value="<?= $key ?>" <?= $type === $key ? 'selected' : '' ?>><?= $meta['label'] ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?= esc($dateFrom) ?>" class="txn-date-input" title="From date">
        <span style="color:var(--text-muted,#94a3b8);font-size:13px;">to</span>
        <input type="date" name="date_to"   value="<?= esc($dateTo) ?>"   class="txn-date-input" title="To date">

        <button type="submit" class="btn-add" style="white-space:nowrap;">Filter</button>
        <?php if ($search !== '' || $type !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <a href="<?= site_url('transactions') ?>" class="txn-clear-link">✕ Clear</a>
        <?php endif; ?>
    </form>

    <!-- ── Table ── -->
    <div class="panel-card table-card" style="overflow-x:auto;">
        <table class="data-table txn-log-table" data-no-paginate="true">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Product</th>
                    <th>Stock No</th>
                    <th style="text-align:right;">Qty</th>
                    <th>Unit</th>
                    <th>Office</th>
                    <th>Reference</th>
                    <th>Reason</th>
                    <th>Recorded By</th>
                    <th>Stockcard</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="11" class="empty-state">
                            No transactions found<?= ($search || $type || $dateFrom || $dateTo) ? ' matching your filters' : '' ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <?php
                            $tType = $txn['transaction_type'] ?? '';
                            $meta  = $typeLabels[$tType] ?? ['label' => ucfirst($tType), 'cls' => ''];
                            $isOut = in_array($tType, ['issue', 'adjust_out', 'borrow']);
                        ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <?= esc(date('M d, Y', strtotime($txn['transaction_date']))) ?>
                                <div style="font-size:11px;color:var(--text-muted,#94a3b8);">
                                    <?= esc(date('H:i', strtotime($txn['transaction_date']))) ?>
                                </div>
                            </td>
                            <td>
                                <span class="txn-type-badge <?= $meta['cls'] ?>"><?= $meta['label'] ?></span>
                            </td>
                            <td>
                                <strong><?= esc($txn['product']) ?></strong>
                                <?php if (!empty($txn['product_no'])): ?>
                                    <div style="font-size:11px;color:var(--text-muted,#94a3b8);">#<?= esc((string) $txn['product_no']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= esc((string) $txn['stock_no']) ?></td>
                            <td style="text-align:right;font-weight:700;color:<?= $isOut ? '#dc2626' : '#16a34a' ?>;">
                                <?= $isOut ? '−' : '+' ?><?= (int) $txn['transaction_qty'] ?>
                            </td>
                            <td><?= esc($txn['unit_name']) ?></td>
                            <td><?= esc($txn['office_name']) ?></td>
                            <td><?= esc($txn['reference']) ?></td>
                            <td>
                                <?php if ($tType === 'adjust_out' && $txn['adjustment_reason'] !== '—'): ?>
                                    <span style="font-size:12px;color:#92400e;"><?= esc($txn['adjustment_reason']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted,#94a3b8);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?= esc($txn['recorded_by']) ?></td>
                            <td>
                                <a href="<?= site_url('stockcard?item_id=' . (int) $txn['product_id']) ?>"
                                   class="action-btn edit-btn" style="white-space:nowrap;">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ── -->
    <div class="txn-pagination">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted,#64748b);">
                <span>Show</span>
                <select onchange="location.href='<?= txnUrl(['limit' => '', 'page' => 1]) ?>&limit=' + this.value"
                        style="padding:5px 8px;border-radius:6px;border:1px solid var(--border-color,#cbd5e1);background:var(--input-bg,#fff);color:inherit;font-size:13px;cursor:pointer;">
                    <?php foreach ([10, 25, 50, 100] as $sz): ?>
                        <option value="<?= $sz ?>" <?= (int)$limit === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                    <?php endforeach; ?>
                </select>
                <span>entries</span>
            </label>
            <span class="txn-page-info">
                Showing <?= number_format($from) ?>–<?= number_format($to) ?> of <?= number_format($total) ?>
            </span>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="txn-page-buttons">
            <?php if ($page > 1): ?>
                <a href="<?= txnUrl(['page' => 1]) ?>" class="txn-page-btn" title="First">«</a>
                <a href="<?= txnUrl(['page' => $page - 1]) ?>" class="txn-page-btn">‹ Prev</a>
            <?php endif; ?>

            <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                for ($p = $start; $p <= $end; $p++):
            ?>
                <a href="<?= txnUrl(['page' => $p]) ?>"
                   class="txn-page-btn <?= $p === $page ? 'txn-page-btn--active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= txnUrl(['page' => $page + 1]) ?>" class="txn-page-btn">Next ›</a>
                <a href="<?= txnUrl(['page' => $totalPages]) ?>" class="txn-page-btn" title="Last">»</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ── Filter bar ── */
.txn-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    padding: 14px 18px;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
}
.txn-search-input {
    flex: 1;
    min-width: 220px;
    height: 40px;
    padding: 0 12px;
    border: 1px solid var(--border-color, #d1d5db);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg, #f9fafb);
    color: var(--text-main, #111);
}
.txn-select, .txn-date-input {
    height: 40px;
    padding: 0 10px;
    border: 1px solid var(--border-color, #d1d5db);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg, #f9fafb);
    color: var(--text-main, #111);
}
.txn-select { min-width: 130px; }
.txn-date-input { min-width: 140px; }
.txn-clear-link {
    font-size: 13px;
    color: #dc2626;
    text-decoration: none;
    white-space: nowrap;
    font-weight: 600;
}
.txn-clear-link:hover { text-decoration: underline; }

/* ── Count badge ── */
.txn-count-badge {
    background: var(--card-bg, #f1f5f9);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted, #64748b);
}

/* ── Type badges ── */
.txn-type-badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .03em;
    white-space: nowrap;
}
.txn-receipt  { background: #dcfce7; color: #166534; }
.txn-issue    { background: #fee2e2; color: #991b1b; }
.txn-adjust   { background: #fef3c7; color: #92400e; }
.txn-borrow   { background: #ffedd5; color: #9a3412; }
.txn-return   { background: #e0f2fe; color: #0c4a6e; }

/* ── Table tweaks ── */
.txn-log-table td { vertical-align: middle; }

/* ── Pagination ── */
.txn-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
    padding: 0 4px;
}
.txn-page-info { font-size: 13px; color: var(--text-muted, #64748b); }
.txn-page-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
.txn-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main, #374151);
    background: var(--card-bg, #fff);
    text-decoration: none;
    transition: background .15s;
}
.txn-page-btn:hover { background: var(--hover-bg, #f1f5f9); }
.txn-page-btn--active {
    background: #0f766e;
    color: #fff;
    border-color: #0f766e;
}
</style>

<?= $this->endSection() ?>

