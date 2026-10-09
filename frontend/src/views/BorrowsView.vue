<script setup>
import { ref, computed, onMounted } from 'vue'
import { Handshake, Search, Undo2, CalendarClock } from 'lucide-vue-next'
import { stockApi } from '../api/stock'
import { useAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import StockModal from '../components/StockModal.vue'
import AppPagination from '../components/AppPagination.vue'

const borrows = ref([])
const loading = ref(true)
const tab = ref('open')
const search = ref('')
const page = ref(1)
const pageSize = ref(15)

// Return form, opened on one borrow
const returning = ref(null)
const isStockModalOpen = ref(false)

async function load() {
  try {
    const res = await stockApi.getBorrows()
    borrows.value = res.data?.borrows || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load borrowed items.'), 'error')
  } finally {
    loading.value = false
  }
}

useAutoReload(load)
onMounted(load)

const today = new Date().toISOString().slice(0, 10)
const isOverdue = (b) => b.status !== 'returned' && b.due_date && b.due_date < today

const TABS = [
  { key: 'open', label: 'Not yet returned', test: (b) => b.status !== 'returned' },
  { key: 'overdue', label: 'Overdue', test: isOverdue },
  { key: 'returned', label: 'Returned', test: (b) => b.status === 'returned' },
  { key: 'all', label: 'All', test: () => true },
]

const countFor = (key) => borrows.value.filter(TABS.find((t) => t.key === key).test).length

const filtered = computed(() => {
  const test = TABS.find((t) => t.key === tab.value).test
  const q = search.value.trim().toLowerCase()
  return borrows.value.filter((b) =>
    test(b) && (!q || [b.product, b.borrower_name, b.borrower_unit, b.notes, b.borrowed_by].some((v) => String(v || '').toLowerCase().includes(q)))
  )
})

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize.value)))
const paged = computed(() => {
  const current = Math.min(page.value, totalPages.value)
  return filtered.value.slice((current - 1) * pageSize.value, current * pageSize.value)
})

function setTab(key) {
  tab.value = key
  page.value = 1
}

function qty(value) {
  const n = Number(value || 0)
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '')
}

function shortDate(value) {
  if (!value) return ''
  const d = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

const STATUS = {
  outstanding: { label: 'Not returned', cls: 'is-out' },
  partial: { label: 'Partly returned', cls: 'is-partial' },
  returned: { label: 'Returned', cls: 'is-returned' },
}

function recordReturn(b) {
  returning.value = { product_id: Number(b.product_id), borrow_id: Number(b.borrow_id) }
  isStockModalOpen.value = true
}

function closeReturn() {
  isStockModalOpen.value = false
  returning.value = null
}
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Inter-Unit Borrowing</p>
        <h1 class="hero-title">Borrowed Items</h1>
        <p class="hero-subtitle">
          Stock lent to other units: who borrowed it, how much is back, and what is still out.
          Lending is recorded as a stock-out; each return comes back in as stock-in.
        </p>
      </div>
    </div>

    <section class="panel">
      <div class="panel-header br-toolbar">
        <div class="br-tabs" role="tablist">
          <button
            v-for="t in TABS"
            :key="t.key"
            type="button"
            role="tab"
            class="br-tab"
            :class="[{ 'is-active': tab === t.key }, `tab-${t.key}`]"
            :aria-selected="tab === t.key"
            @click="setTab(t.key)"
          >
            {{ t.label }} <span class="br-tab-count">{{ countFor(t.key) }}</span>
          </button>
        </div>
        <div class="br-search">
          <Search :size="15" />
          <input v-model="search" type="search" class="form-input" placeholder="Search item, borrower or unit…" @input="page = 1" />
        </div>
      </div>

      <div v-if="loading" class="br-empty">Loading borrowed items…</div>
      <div v-else-if="!filtered.length" class="br-empty">
        <Handshake :size="30" />
        <strong>{{ search ? 'Nothing matches your search.' : tab === 'open' ? 'Nothing is out on loan.' : 'No borrows here.' }}</strong>
        <span v-if="!borrows.length">Use “Lend to Another Unit (Borrow)” in Record Stock In / Out to lend stock.</span>
      </div>

      <div v-else class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th>Lent</th>
              <th>Item</th>
              <th>Borrower</th>
              <th style="text-align: right;">Lent Qty</th>
              <th style="text-align: right;">Returned</th>
              <th style="text-align: right;">Still Out</th>
              <th>Return By</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in paged" :key="b.borrow_id" :class="{ 'br-row-overdue': isOverdue(b) }">
              <td data-label="Lent">
                <span class="br-date">{{ shortDate(b.borrowed_at) }}</span>
                <span v-if="b.borrowed_by" class="br-muted">by {{ b.borrowed_by }}</span>
              </td>
              <td class="cell-title">
                <strong>{{ b.product }}</strong>
                <span v-if="Number(b.unit_cost) > 0 || b.copy_label" class="br-muted">₱{{ Number(b.unit_cost).toFixed(2) }}{{ b.copy_label ? ` · ${b.copy_label}` : '' }}</span>
              </td>
              <td data-label="Borrower">
                <strong>{{ b.borrower_name }}</strong>
                <span class="br-muted">{{ b.borrower_unit }}</span>
                <span v-if="b.notes" class="br-muted br-notes">{{ b.notes }}</span>
              </td>
              <td data-label="Lent Qty" class="br-num">{{ qty(b.quantity) }} {{ b.unit_name }}</td>
              <td data-label="Returned" class="br-num">{{ qty(b.returned_qty) }}</td>
              <td data-label="Still Out" class="br-num" :class="{ 'is-due': Number(b.outstanding_qty) > 0 }">{{ qty(b.outstanding_qty) }}</td>
              <td data-label="Return By">
                <span v-if="b.due_date" class="br-due" :class="{ 'is-overdue': isOverdue(b) }">
                  <CalendarClock :size="13" /> {{ shortDate(b.due_date) }}<template v-if="isOverdue(b)"> · overdue</template>
                </span>
                <span v-else class="br-muted">—</span>
              </td>
              <td data-label="Status">
                <span class="br-status" :class="STATUS[b.status]?.cls">{{ STATUS[b.status]?.label || b.status }}</span>
                <span v-if="b.returned_at" class="br-muted">{{ shortDate(b.returned_at) }}</span>
              </td>
              <td class="cell-actions" style="text-align: right;">
                <button v-if="b.status !== 'returned'" type="button" class="btn btn-sm btn-primary br-return" @click="recordReturn(b)">
                  <Undo2 :size="14" /> Record Return
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="filtered.length"
        :current-page="Math.min(page, totalPages)"
        :total-pages="totalPages"
        :total-items="filtered.length"
        :page-size="pageSize"
        :page-size-options="[10, 15, 25, 50]"
        item-name="borrows"
        @update:current-page="page = $event"
        @update:page-size="pageSize = $event; page = 1"
      />
    </section>

    <StockModal
      :is-open="isStockModalOpen"
      :preset-return="returning"
      @close="closeReturn"
      @saved="load"
    />
  </div>
</template>

<style scoped>
.br-toolbar {
  background: var(--bg-subtle);
  gap: 0.75rem;
}

.br-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.br-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-full);
  background: var(--bg-surface);
  color: var(--text-muted);
  font: inherit;
  font-size: 0.83rem;
  font-weight: 600;
  cursor: pointer;
}

.br-tab:hover {
  color: var(--text-main);
}

.br-tab.is-active {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: #fff;
}

.br-tab.is-active.tab-overdue {
  border-color: var(--color-danger);
  background: var(--color-danger);
}

.br-tab-count {
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: var(--radius-full);
  background: rgba(127, 127, 127, 0.16);
  font-size: 0.72rem;
  text-align: center;
}

.br-tab.is-active .br-tab-count {
  background: rgba(255, 255, 255, 0.25);
}

.br-search {
  position: relative;
  flex: 1;
  max-width: 320px;
  min-width: 200px;
}

.br-search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
}

.br-search .form-input {
  width: 100%;
  padding-left: 34px;
}

.br-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 3rem 1rem;
  text-align: center;
  color: var(--text-muted);
}

.br-empty strong {
  color: var(--text-main);
}

.br-date {
  display: block;
  font-size: 0.83rem;
  white-space: nowrap;
}

.br-muted {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.br-notes {
  font-style: italic;
}

.br-num {
  text-align: right;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.br-num.is-due {
  color: var(--color-warning);
}

.br-due {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.82rem;
  white-space: nowrap;
}

.br-due.is-overdue {
  color: var(--color-danger);
  font-weight: 700;
}

.br-row-overdue td:first-child {
  box-shadow: inset 4px 0 0 var(--color-danger);
}

.br-status {
  display: inline-block;
  padding: 0.12rem 0.6rem;
  border-radius: var(--radius-full);
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
}

.br-status.is-out { background: var(--color-warning-bg); color: var(--color-warning); }
.br-status.is-partial { background: var(--color-info-bg); color: var(--color-info); }
.br-status.is-returned { background: var(--color-success-bg); color: var(--color-success); }

.br-return {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
}

@media (max-width: 640px) {
  .br-search {
    max-width: none;
  }
}
</style>
