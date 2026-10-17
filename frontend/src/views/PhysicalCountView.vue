<script setup>
import { ref, reactive, computed, onMounted, nextTick, watch } from 'vue'
import { ClipboardCheck, Search, CheckCircle2, RotateCcw } from 'lucide-vue-next'
import { stockApi } from '../api/stock'
import { barcodesApi } from '../api/reports'
import { triggerAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'
import AppPagination from '../components/AppPagination.vue'
import CameraScanButton from '../components/CameraScanButton.vue'

const items = ref([])
const stockMap = ref({})
const loading = ref(true)
const saving = ref(false)
const search = ref('')
// Filters: category, and which rows to show by their count
const category = ref('')
const show = ref('all') // all | differences | short | over | uncounted
const SHOW_OPTIONS = [
  ['all', 'All items'],
  ['differences', 'Differences only'],
  ['short', 'Short'],
  ['over', 'Over'],
  ['uncounted', 'Not counted'],
]
const page = ref(1)
const pageSize = ref(15)
const note = ref('')
// product_id → counted quantity; starts at the system quantity, so only differences need typing ('' = not counted)
const counted = reactive({})
const result = ref(null)
const highlight = ref(0)

async function load() {
  try {
    const res = await stockApi.getOptions()
    items.value = res.data?.items || []
    stockMap.value = res.data?.stockMap || {}
    fillFromSystem()
  } catch (err) {
    toast(errorMessage(err, 'Failed to load products.'), 'error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

function qty(value) {
  const n = Number(value || 0)
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '')
}

const systemQty = (item) => Number(stockMap.value[item.product_id] || 0)
const isEntered = (item) => counted[item.product_id] !== undefined && counted[item.product_id] !== '' && counted[item.product_id] !== null
const variance = (item) => (isEntered(item) ? Number(counted[item.product_id]) - systemQty(item) : null)

const hasDifference = (item) => variance(item) !== null && Math.abs(variance(item)) > 0.0001

const categoryOf = (item) => String(item.type_name || '').trim() || 'Uncategorized'
const categories = computed(() => [...new Set(items.value.map(categoryOf))].sort((a, b) => a.localeCompare(b)))

function matchesShow(item) {
  switch (show.value) {
    case 'differences': return hasDifference(item)
    case 'short': return hasDifference(item) && variance(item) < 0
    case 'over': return hasDifference(item) && variance(item) > 0
    case 'uncounted': return !isEntered(item)
    default: return true
  }
}

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return items.value.filter((item) =>
    (!category.value || categoryOf(item) === category.value) &&
    matchesShow(item) &&
    (!q || [item.product, item.product_no, item.stock_no, item.product_description].some((v) => String(v ?? '').toLowerCase().includes(q)))
  )
})

// The page of rows on screen
const totalPages = computed(() => Math.max(1, Math.ceil(rows.value.length / pageSize.value)))
const pagedRows = computed(() => {
  const current = Math.min(page.value, totalPages.value)
  return rows.value.slice((current - 1) * pageSize.value, current * pageSize.value)
})
watch([search, category, show], () => {
  page.value = 1
})

const entered = computed(() => items.value.filter(isEntered))
const differences = computed(() => entered.value.filter(hasDifference))
const shortages = computed(() => differences.value.filter((item) => variance(item) < 0))
const overages = computed(() => differences.value.filter((item) => variance(item) > 0))
const invalid = computed(() => entered.value.filter((item) => !(Number(counted[item.product_id]) >= 0)))

// Every item starts counted at its system quantity; boxes already typed in are kept
function fillFromSystem(reset = false) {
  for (const item of items.value) {
    if (reset || counted[item.product_id] === undefined) counted[item.product_id] = systemQty(item)
  }
}

// Back to the system quantities
function resetAll() {
  fillFromSystem(true)
  result.value = null
}

// Enter in the search box: a scanned batch barcode jumps to its product's count field
async function onSearchEnter() {
  const value = search.value.trim()
  if (!value) return
  if (rows.value.length === 1) return focusRow(rows.value[0].product_id)
  if (rows.value.length) return
  try {
    const res = await barcodesApi.lookup(value)
    const item = items.value.find((i) => Number(i.product_id) === Number(res.data?.product_id))
    if (!item) throw new Error('not in list')
    search.value = ''
    focusRow(item.product_id)
  } catch {
    toast(`No product or batch matches “${value}”.`, 'error')
  }
}

// Camera scan: same as a USB scanner typing the code and pressing Enter
function onCameraScan(text) {
  search.value = text
  onSearchEnter()
}

function focusRow(productId) {
  // Bring the item into the list (filters off if they hide it) and turn to its page
  let index = rows.value.findIndex((i) => Number(i.product_id) === Number(productId))
  if (index < 0) {
    category.value = ''
    show.value = 'all'
    index = rows.value.findIndex((i) => Number(i.product_id) === Number(productId))
  }
  if (index >= 0) page.value = Math.floor(index / pageSize.value) + 1
  highlight.value = Number(productId)
  nextTick(() => {
    const input = document.getElementById(`count-${productId}`)
    input?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    input?.focus()
    input?.select()
  })
  setTimeout(() => {
    if (highlight.value === Number(productId)) highlight.value = 0
  }, 2500)
}

async function reconcile() {
  if (invalid.value.length) {
    toast('Counted quantities must be 0 or more.', 'error')
    return
  }
  if (!entered.value.length) {
    toast('Enter at least one counted quantity.', 'error')
    return
  }

  const ok = await confirmDialog({
    title: differences.value.length ? 'Adjust stock to match the count?' : 'Save this count?',
    message: differences.value.length
      ? `${entered.value.length} item(s) counted. ${shortages.value.length} short (Adjust Out, expired batches first) and ${overages.value.length} over (Adjust In). Each adjustment is recorded in the stockcard ledger with the reason "Physical Count" and in the audit log.`
      : `All ${entered.value.length} counted item(s) match the system. Nothing will be adjusted.`,
    details: [
      ['Items counted', entered.value.length],
      ['Short (Adjust Out)', shortages.value.length],
      ['Over (Adjust In)', overages.value.length],
    ],
    confirmText: differences.value.length ? 'Reconcile' : 'Save count',
    variant: differences.value.length ? 'danger' : undefined,
  })
  if (!ok) return

  saving.value = true
  try {
    const res = await stockApi.submitCount(
      entered.value.map((item) => ({ product_id: Number(item.product_id), counted_qty: Number(counted[item.product_id]) })),
      note.value.trim()
    )
    result.value = res.data
    toast(res.message || 'Count saved.')
    triggerAutoReload('physical-count')
    Object.keys(counted).forEach((key) => delete counted[key])
    note.value = ''
    await load()
  } catch (err) {
    toast(errorMessage(err, 'The count could not be saved.'), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Reconciliation</p>
        <h1 class="hero-title">Physical Count</h1>
        <p class="hero-subtitle">
          Every count starts at the system quantity: change the ones that differ from the shelf and the system shows the difference. Reconciling records
          an Adjust Out for shortages (expired batches first) and an Adjust In for overages, with the reason “Physical Count”.
        </p>
      </div>
    </div>

    <!-- Last reconciliation -->
    <section v-if="result" class="panel pc-result">
      <div class="pc-result-head">
        <CheckCircle2 :size="20" />
        <div>
          <strong>Count {{ result.reference }} saved.</strong>
          <span>{{ result.lines.length ? `${result.lines.length} item(s) adjusted.` : 'Every counted quantity already matched; nothing was adjusted.' }}</span>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" @click="result = null">Dismiss</button>
      </div>
      <ul v-if="result.lines.length" class="pc-result-list">
        <li v-for="line in result.lines" :key="line.product_id">
          <strong>{{ line.product }}</strong>
          <span>system {{ qty(line.system_qty) }} → counted {{ qty(line.counted) }}</span>
          <span class="pc-var" :class="line.variance < 0 ? 'is-short' : 'is-over'">{{ line.variance > 0 ? '+' : '' }}{{ qty(line.variance) }}</span>
          <span class="pc-batches">{{ line.batches.map((b) => b.batch_no).join(', ') }}</span>
        </li>
      </ul>
    </section>

    <section class="panel">
      <div class="panel-header pc-toolbar">
        <div class="pc-search">
          <Search :size="15" />
          <input
            v-model="search"
            type="search"
            class="form-input"
            placeholder="Find an item, or scan its batch barcode…"
            aria-label="Find an item or scan a barcode"
            @keydown.enter.prevent="onSearchEnter"
          />
          <span class="input-cam"><CameraScanButton @scanned="onCameraScan" /></span>
        </div>
        <select v-model="category" class="form-select pc-filter" aria-label="Category">
          <option value="">All categories</option>
          <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
        </select>
        <select v-model="show" class="form-select pc-filter" aria-label="Show">
          <option v-for="[value, label] in SHOW_OPTIONS" :key="value" :value="value">{{ label }}</option>
        </select>
        <div class="pc-summary">
          <span><strong>{{ entered.length }}</strong> counted</span>
          <span class="is-short"><strong>{{ shortages.length }}</strong> short</span>
          <span class="is-over"><strong>{{ overages.length }}</strong> over</span>
        </div>
      </div>

      <div v-if="loading" class="pc-empty">Loading items…</div>
      <div v-else-if="!rows.length" class="pc-empty">
        <ClipboardCheck :size="30" />
        <strong>{{ show === 'differences' && !search && !category ? 'No differences: every count matches the system.' : 'No items match these filters.' }}</strong>
      </div>

      <div v-else class="table-responsive">
        <table class="data-table stack-mobile pc-table">
          <thead>
            <tr>
              <th>Item</th>
              <th style="text-align: right;">System Qty</th>
              <th style="width: 190px;">Counted Qty</th>
              <th style="text-align: right;">Difference</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in pagedRows"
              :key="item.product_id"
              :class="{
                'is-highlight': highlight === Number(item.product_id),
                'is-short': variance(item) !== null && variance(item) < -0.0001,
                'is-over': variance(item) !== null && variance(item) > 0.0001,
              }"
            >
              <td class="cell-title">
                <span class="pc-no">{{ item.product_no }}</span>
                <strong>{{ item.product }}</strong>
                <span v-if="item.stock_no && item.stock_no !== item.product_no" class="pc-muted">{{ item.stock_no }}</span>
              </td>
              <td data-label="System Qty" class="pc-num">{{ qty(systemQty(item)) }} <span class="pc-unit">{{ item.unit }}</span></td>
              <td data-label="Counted Qty">
                <div class="pc-input">
                  <input
                    :id="`count-${item.product_id}`"
                    v-model="counted[item.product_id]"
                    type="number"
                    min="0"
                    step="any"
                    class="form-input"
                    placeholder="—"
                  />
                </div>
              </td>
              <td data-label="Difference" class="pc-num">
                <span v-if="variance(item) === null" class="pc-muted">not counted</span>
                <span v-else-if="Math.abs(variance(item)) < 0.0001" class="pc-var is-match">matches</span>
                <span v-else class="pc-var" :class="variance(item) < 0 ? 'is-short' : 'is-over'">
                  {{ variance(item) > 0 ? '+' : '' }}{{ qty(variance(item)) }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="!loading && rows.length > 0"
        :current-page="Math.min(page, totalPages)"
        :total-pages="totalPages"
        :total-items="rows.length"
        :page-size="pageSize"
        :page-size-options="[10, 15, 25, 50, 100]"
        item-name="items"
        class="pc-pagination"
        @update:current-page="page = $event"
        @update:page-size="pageSize = $event; page = 1"
      />

      <div class="pc-footer">
        <input v-model="note" type="text" class="form-input pc-note" maxlength="300" placeholder="Note (optional), e.g. Month-end count, storeroom A" />
        <button type="button" class="btn btn-secondary" :disabled="saving || !differences.length" title="Put every count back to the system quantity" @click="resetAll">
          <RotateCcw :size="14" /> Reset
        </button>
        <button type="button" class="btn btn-primary" :disabled="saving || !entered.length" @click="reconcile">
          <ClipboardCheck :size="15" />
          {{ saving ? 'Saving…' : differences.length ? `Reconcile ${differences.length} difference${differences.length === 1 ? '' : 's'}` : 'Save count' }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.pc-toolbar {
  background: var(--bg-subtle);
  gap: 0.75rem 1.25rem;
}

.pc-search {
  position: relative;
  flex: 1;
  min-width: 240px;
  max-width: 420px;
}

.pc-search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  pointer-events: none;
}

.pc-search .form-input {
  width: 100%;
  padding-left: 34px;
  padding-right: 34px;
}

.pc-filter {
  width: auto;
  min-width: 160px;
  height: 40px;
  padding-top: 0;
  padding-bottom: 0;
  font-size: 0.875rem;
  background-color: var(--bg-surface);
}

.pc-summary {
  display: flex;
  gap: 0.9rem;
  margin-left: auto;
  font-size: 0.83rem;
  color: var(--text-muted);
}

.pc-summary strong {
  color: var(--text-main);
}

.pc-summary .is-short strong {
  color: var(--color-danger);
}

.pc-summary .is-over strong {
  color: var(--color-info);
}

.pc-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 3rem 1rem;
  color: var(--text-muted);
}

.pc-empty strong {
  color: var(--text-main);
}

.pc-no {
  margin-right: 0.4rem;
  font-family: var(--font-mono);
  font-size: 0.75rem;
  color: var(--text-muted);
}

.pc-muted {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.pc-num {
  text-align: right;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.pc-unit {
  font-weight: 500;
  font-size: 0.78rem;
  color: var(--text-muted);
}

.pc-input {
  display: flex;
  gap: 0.35rem;
}

.pc-input .form-input {
  width: 100%;
  min-width: 90px;
  text-align: right;
}

.pc-var {
  display: inline-block;
  padding: 0.1rem 0.55rem;
  border-radius: var(--radius-full);
  font-size: 0.8rem;
  font-weight: 800;
}

.pc-var.is-short { background: var(--color-danger-bg); color: var(--color-danger); }
.pc-var.is-over { background: var(--color-info-bg); color: var(--color-info); }
.pc-var.is-match { background: var(--color-success-bg); color: var(--color-success); }

.pc-table tr.is-short td:first-child { box-shadow: inset 4px 0 0 var(--color-danger); }
.pc-table tr.is-over td:first-child { box-shadow: inset 4px 0 0 var(--color-info); }
.pc-table tr.is-highlight td { background: var(--color-primary-light); transition: background 0.3s; }

/* The Reconcile bar follows the pagination, so it keeps square corners */
section .pc-pagination {
  border-radius: 0;
}

.pc-footer {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--border-subtle);
  background: var(--bg-subtle);
}

.pc-note {
  flex: 1;
  min-width: 220px;
}

.pc-footer .btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.pc-result {
  margin-bottom: 1.5rem;
  padding: 1rem 1.25rem;
  border-left: 5px solid var(--color-success);
}

.pc-result-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  color: var(--color-success);
}

.pc-result-head div {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.pc-result-head strong {
  color: var(--text-main);
}

.pc-result-head span {
  font-size: 0.83rem;
  color: var(--text-muted);
}

.pc-result-list {
  list-style: none;
  margin: 0.75rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.35rem;
}

.pc-result-list li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem 0.85rem;
  padding: 0.45rem 0.7rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  font-size: 0.83rem;
}

.pc-batches {
  font-family: var(--font-mono);
  font-size: 0.72rem;
  color: var(--text-muted);
}

@media (max-width: 640px) {
  .pc-summary {
    margin-left: 0;
  }
}
</style>
