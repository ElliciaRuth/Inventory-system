<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { ClipboardCheck, Search, ScanLine, CheckCircle2, RotateCcw } from 'lucide-vue-next'
import { stockApi } from '../api/stock'
import { barcodesApi } from '../api/reports'
import { triggerAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'

const items = ref([])
const stockMap = ref({})
const loading = ref(true)
const saving = ref(false)
const search = ref('')
const onlyEntered = ref(false)
const note = ref('')
// product_id → counted quantity as typed ('' = not counted)
const counted = reactive({})
const result = ref(null)
const highlight = ref(0)

async function load() {
  try {
    const res = await stockApi.getOptions()
    items.value = res.data?.items || []
    stockMap.value = res.data?.stockMap || {}
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

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return items.value.filter((item) =>
    (!onlyEntered.value || isEntered(item)) &&
    (!q || [item.product, item.product_no, item.stock_no, item.product_description].some((v) => String(v ?? '').toLowerCase().includes(q)))
  )
})

const entered = computed(() => items.value.filter(isEntered))
const differences = computed(() => entered.value.filter((item) => Math.abs(variance(item)) > 0.0001))
const shortages = computed(() => differences.value.filter((item) => variance(item) < 0))
const overages = computed(() => differences.value.filter((item) => variance(item) > 0))
const invalid = computed(() => entered.value.filter((item) => !(Number(counted[item.product_id]) >= 0)))

function fillSystem(item) {
  counted[item.product_id] = systemQty(item)
}

function clearAll() {
  Object.keys(counted).forEach((key) => delete counted[key])
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

function focusRow(productId) {
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
          Count what is on the shelf, enter it here, and the system shows the difference. Reconciling records
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
          <ScanLine :size="15" class="pc-scan-icon" />
        </div>
        <label class="pc-toggle">
          <input v-model="onlyEntered" type="checkbox" />
          <span>Only counted items</span>
        </label>
        <div class="pc-summary">
          <span><strong>{{ entered.length }}</strong> counted</span>
          <span class="is-short"><strong>{{ shortages.length }}</strong> short</span>
          <span class="is-over"><strong>{{ overages.length }}</strong> over</span>
        </div>
      </div>

      <div v-if="loading" class="pc-empty">Loading items…</div>
      <div v-else-if="!rows.length" class="pc-empty">
        <ClipboardCheck :size="30" />
        <strong>{{ onlyEntered ? 'No counts entered yet.' : 'No items match.' }}</strong>
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
              v-for="item in rows"
              :key="item.product_id"
              :class="{
                'is-highlight': highlight === Number(item.product_id),
                'is-short': variance(item) !== null && variance(item) < -0.0001,
                'is-over': variance(item) !== null && variance(item) > 0.0001,
              }"
            >
              <td class="cell-title">
                <span class="pc-no">#{{ item.product_no }}</span>
                <strong>{{ item.product }}</strong>
                <span v-if="item.stock_no" class="pc-muted">{{ item.stock_no }}</span>
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
                  <button type="button" class="pc-same" title="Counted the same as the system" @click="fillSystem(item)">=</button>
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

      <div class="pc-footer">
        <input v-model="note" type="text" class="form-input pc-note" maxlength="300" placeholder="Note (optional), e.g. Month-end count, storeroom A" />
        <button type="button" class="btn btn-secondary" :disabled="saving || !entered.length" @click="clearAll">
          <RotateCcw :size="14" /> Clear
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

.pc-search .pc-scan-icon {
  left: auto;
  right: 12px;
}

.pc-search .form-input {
  width: 100%;
  padding-left: 34px;
  padding-right: 34px;
}

.pc-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
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

.pc-same {
  flex-shrink: 0;
  width: 34px;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  background: var(--bg-subtle);
  color: var(--text-muted);
  font-weight: 800;
  cursor: pointer;
}

.pc-same:hover {
  color: var(--color-primary);
  border-color: var(--color-primary);
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
