<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Download, PencilLine, ExternalLink } from 'lucide-vue-next'
import { reportsApi } from '../api/reports'
import { stockApi } from '../api/stock'
import { useAuthStore } from '../stores/authStore'
import { toast, errorMessage } from '../composables/useToast'
import { triggerAutoReload } from '../composables/useAutoReload'
import { useUnsavedChanges } from '../composables/useUnsavedChanges'
import UnsavedChangesBar from '../components/UnsavedChangesBar.vue'
import { typeLabel } from '../utils/transactionTypes'
import '../styles/inline-edit.css'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const now = new Date()
const YEARS = Array.from({ length: now.getFullYear() - 2019 }, (_, i) => now.getFullYear() - i)

// The columns a stock card entry can be counted in; Beginning, Ending and the amounts are worked out
const SECTIONS = ['purchase', 'used', 'spoiled']

const authStore = useAuthStore()

const filters = reactive({
  search: '',
  type_id: 0,
  month: String(now.getMonth() + 1).padStart(2, '0'),
  year: now.getFullYear(),
})
const groupedRows = ref({})
const productTypes = ref([])
const loading = ref(true)

function qty(v) {
  const n = Number(v || 0)
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '')
}

function money(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Products with several price variants get a parent row followed by one row per variant
const groups = computed(() =>
  Object.entries(groupedRows.value).map(([type, rows]) => {
    const byProduct = new Map()
    for (const row of rows) {
      const pid = Number(row.product_id)
      if (!byProduct.has(pid)) byProduct.set(pid, [])
      byProduct.get(pid).push(row)
    }
    return { type, products: [...byProduct.values()] }
  })
)

// ── Edit mode: Purchase / Used / Spoiled quantities and costs are the stock card entries behind them,
// so a change here is saved to those entries and shows on the Stock Card ──
const canEdit = computed(() => authStore.levelId === 2 || authStore.levelId === 3)
const editMode = ref(false)
const drafts = reactive({}) // entry key → { qty, cost }
const saving = ref(false)
const saveError = ref('')

const entryKey = (entry) => entry.transaction_ids.join(',')
const round2 = (n) => Math.round(Number(n) * 100) / 100
const sameNumber = (a, b) => Math.abs(Number(a) - Number(b)) < 0.005

const allEntries = computed(() => Object.values(groupedRows.value).flat().flatMap((row) => row.entries || []))

function entryChanges(entry) {
  const draft = drafts[entryKey(entry)]
  if (!draft) return { qty: false, cost: false }
  return { qty: !sameNumber(draft.qty, entry.qty), cost: !sameNumber(draft.cost, entry.cost) }
}

const isEntryChanged = (entry) => {
  const changes = entryChanges(entry)
  return changes.qty || changes.cost
}

const entryInvalid = (entry) => {
  const draft = drafts[entryKey(entry)]
  return { qty: !draft || !(Number(draft.qty) > 0), cost: !draft || draft.cost === '' || !(Number(draft.cost) >= 0) }
}

const changedEntries = computed(() => allEntries.value.filter(isEntryChanged))
const changeCount = computed(() => changedEntries.value.length)

function syncDrafts() {
  for (const entry of allEntries.value) {
    drafts[entryKey(entry)] ??= { qty: Number(entry.qty), cost: round2(entry.cost) }
  }
}

function clearDrafts() {
  for (const key of Object.keys(drafts)) delete drafts[key]
  saveError.value = ''
}

// The row as it will read once saved: changed entries give new totals and a new ending
function liveRow(row) {
  if (!editMode.value || !(row.entries || []).some(isEntryChanged)) return row
  const out = { ...row }
  for (const section of SECTIONS) {
    const list = row.entries.filter((e) => e.section === section)
    if (!list.length) continue
    let sectionQty = 0
    let total = 0
    for (const entry of list) {
      const draft = drafts[entryKey(entry)]
      sectionQty += Number(draft.qty) || 0
      total += (Number(draft.qty) || 0) * (Number(draft.cost) || 0)
    }
    out[`${section}_qty`] = sectionQty
    out[`${section}_total`] = total
    out[`${section}_cost`] = sectionQty > 0 ? total / sectionQty : 0
  }
  out.ending_qty = out.begin_qty + out.purchase_qty - out.used_qty - out.spoiled_qty
  const endingAmount = out.begin_qty * out.begin_cost + out.purchase_total - out.used_total - out.spoiled_total
  out.ending_cost = out.ending_qty > 0 ? endingAmount / out.ending_qty : 0
  out.changed = true
  return out
}

// Worked out once per change for every row (the template reads it many times per row)
const liveRows = computed(() => {
  const map = new Map()
  for (const rows of Object.values(groupedRows.value)) {
    for (const row of rows) map.set(row, liveRow(row))
  }
  return map
})
const live = (row) => liveRows.value.get(row) || row

// One entry behind a row: its inputs sit in the row itself; several get a line each below it
const singleEntry = (row) => (editMode.value && row.entries?.length === 1 ? row.entries[0] : null)

function entryLabel(entry) {
  const parts = [String(entry.date).slice(0, 10), typeLabel(entry.type), entry.reference || 'N/A']
  if (entry.office) parts.push(entry.office)
  return parts.join(' · ')
}

const { confirmDiscard } = useUnsavedChanges(() => changeCount.value > 0)

async function load() {
  // Unsaved edits keep their drafts; otherwise the inputs start again from the fresh values
  const keepDrafts = changeCount.value > 0
  loading.value = true
  try {
    const res = await reportsApi.getBatchList({ ...filters, entries: editMode.value ? 1 : 0 })
    groupedRows.value = res.data?.groupedRows || {}
    productTypes.value = res.data?.productTypes || []
    if (!keepDrafts) clearDrafts()
    if (editMode.value) syncDrafts()
  } catch (err) {
    toast(errorMessage(err, 'Failed to load the report.'), 'error')
  } finally {
    loading.value = false
  }
}

async function applyFilter() {
  if (!(await confirmDiscard('Change the report period?', 'Discard and apply'))) return
  clearDrafts()
  load()
}

async function toggleEditMode() {
  if (editMode.value) {
    if (!(await confirmDiscard('Leave edit mode?'))) return
    clearDrafts()
    editMode.value = false
    // Back to the lighter report without the entries
    load()
    return
  }
  editMode.value = true
  load()
}

async function discardEdits() {
  if (!(await confirmDiscard())) return
  clearDrafts()
  syncDrafts()
}

async function saveEdits() {
  saveError.value = ''
  const bad = changedEntries.value.find((entry) => entryInvalid(entry).qty || entryInvalid(entry).cost)
  if (bad) {
    saveError.value = `Fix the ${entryInvalid(bad).qty ? 'quantity' : 'cost'} of the ${String(bad.date).slice(0, 10)} entry first.`
    return
  }

  const edits = changedEntries.value.map((entry) => {
    const draft = drafts[entryKey(entry)]
    const changes = entryChanges(entry)
    const edit = { transaction_ids: entry.transaction_ids }
    if (changes.qty) edit.qty = Number(draft.qty)
    if (changes.cost) edit.unit_cost = Number(draft.cost)
    return edit
  })

  saving.value = true
  try {
    const res = await stockApi.saveLedgerEdits({ edits })
    clearDrafts()
    toast(`${res.message || 'Saved'}. The Stock Card shows the new values.`)
    triggerAutoReload('ledger-edit')
    await load()
  } catch (err) {
    // Nothing was saved: the edits stay so they can be fixed
    saveError.value = errorMessage(err, 'The changes could not be saved.')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Reports</p>
        <h1 class="hero-title">Inventory Report</h1>
        <p class="hero-subtitle">Beginning, purchase, usage, spoiled and ending balances for the selected month.</p>
      </div>
      <router-link to="/export/summary" class="btn btn-secondary">
        <Download :size="15" />
        <span>Export Summary</span>
      </router-link>
    </div>

    <form class="panel" style="padding: 1.25rem; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;" @submit.prevent="applyFilter">
      <div class="form-group" style="margin: 0; flex: 2; min-width: 200px;">
        <label class="form-label">Search Product</label>
        <input v-model="filters.search" type="text" class="form-input" placeholder="Search product name" />
      </div>
      <div class="form-group" style="margin: 0; min-width: 180px;">
        <label class="form-label">Product Type</label>
        <select v-model.number="filters.type_id" class="form-select">
          <option :value="0">All Product Types</option>
          <option v-for="t in productTypes" :key="t.type_id" :value="Number(t.type_id)">{{ t.type }}</option>
        </select>
      </div>
      <div class="form-group" style="margin: 0;">
        <label class="form-label">Month</label>
        <select v-model="filters.month" class="form-select">
          <option v-for="(m, i) in MONTHS" :key="m" :value="String(i + 1).padStart(2, '0')">{{ m }}</option>
        </select>
      </div>
      <div class="form-group" style="margin: 0;">
        <label class="form-label">Year</label>
        <select v-model.number="filters.year" class="form-select">
          <option v-for="y in YEARS" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Apply Filter</button>
      <button
        v-if="canEdit"
        type="button"
        class="edit-toggle"
        :class="{ 'is-on': editMode }"
        :aria-pressed="editMode"
        title="Edit the Purchase, Used and Spoiled quantities and costs; saved changes update the Stock Card"
        style="margin-left: auto;"
        @click="toggleEditMode"
      >
        <PencilLine :size="15" /> Edit Mode
        <span class="edit-toggle-state">{{ editMode ? 'ON' : 'OFF' }}</span>
      </button>
    </form>

    <p v-if="editMode" class="report-edit-note">
      Change a Purchase, Used or Spoiled <strong>quantity</strong> or <strong>cost</strong>; each one is a Stock Card entry,
      so saving updates the Stock Card too. Beginning, Ending and the amounts are worked out from them: they update as you
      type, and costs that come from first-in-first-out are recalculated exactly when you save.
    </p>

    <div v-if="loading && !groups.length" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">Loading report…</div>
    <div v-else-if="!groups.length" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">No inventory activity for this period.</div>

    <section v-for="group in groups" :key="group.type" class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header"><h2 class="panel-title">{{ group.type }}</h2></div>
      <div class="table-responsive">
        <table class="data-table report-table" :class="{ 'is-editing': editMode }">
          <thead>
            <tr>
              <th rowspan="2">No.</th>
              <th rowspan="2">Stock No</th>
              <th rowspan="2">Product</th>
              <th colspan="4" class="grp">BEGINNING</th>
              <th colspan="3" class="grp">PURCHASE</th>
              <th colspan="3" class="grp">USED</th>
              <th colspan="3" class="grp">SPOILED</th>
              <th colspan="3" class="grp">ENDING</th>
            </tr>
            <tr>
              <th>Qty</th><th>Unit</th><th>Cost</th><th>Amount</th>
              <th>Qty</th><th>Cost</th><th>Amount</th>
              <th>Qty</th><th>Cost</th><th>Amount</th>
              <th>Qty</th><th>Cost</th><th>Amount</th>
              <th>Qty</th><th>Cost</th><th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="rows in group.products" :key="rows[0].product_id">
              <tr v-if="rows.length > 1" class="parent-row">
                <td>{{ rows[0].counter }}</td>
                <td>{{ rows[0].stock_no }}</td>
                <td>{{ rows[0].item }}</td>
                <td colspan="16" style="color: var(--text-muted); font-style: italic;">Multiple price variants</td>
              </tr>
              <template v-for="row in rows" :key="`${row.product_id}-${row.copy_id}`">
                <tr :class="{ 'row-changed': live(row).changed }">
                  <td>{{ rows.length > 1 ? '' : row.counter }}</td>
                  <td>{{ rows.length > 1 ? '' : row.stock_no }}</td>
                  <td :class="{ variant: rows.length > 1 }">
                    <template v-if="rows.length > 1">↳ ₱{{ money(row.copy_unit_cost) }}{{ row.copy_label ? ` (${row.copy_label})` : '' }}</template>
                    <template v-else>{{ row.item }}</template>
                    <router-link
                      v-if="editMode"
                      :to="{ path: '/stockcard', query: { item_id: row.product_id } }"
                      class="report-card-link"
                      title="Open this product's Stock Card"
                    >
                      <ExternalLink :size="12" />
                    </router-link>
                  </td>
                  <td>{{ qty(row.begin_qty) }}</td>
                  <td>{{ row.unit_name }}</td>
                  <td>{{ money(row.begin_cost) }}</td>
                  <td>{{ money(row.begin_qty * row.begin_cost) }}</td>
                  <template v-for="section in SECTIONS" :key="section">
                    <!-- A single stock card entry behind this column: edit it right here -->
                    <template v-if="singleEntry(row)?.section === section && drafts[entryKey(singleEntry(row))]">
                      <td>
                        <input
                          v-model.number="drafts[entryKey(singleEntry(row))].qty"
                          type="number"
                          step="any"
                          min="0.01"
                          class="cell-input cell-narrow"
                          :class="{ 'is-changed': entryChanges(singleEntry(row)).qty, 'is-invalid': entryInvalid(singleEntry(row)).qty }"
                          :disabled="singleEntry(row).borrow"
                          :title="singleEntry(row).borrow ? 'Part of a borrow record: record a Return instead of changing it' : entryLabel(singleEntry(row))"
                          :aria-label="`${section} quantity of ${row.item}`"
                        />
                      </td>
                      <td>
                        <input
                          v-model.number="drafts[entryKey(singleEntry(row))].cost"
                          type="number"
                          step="any"
                          min="0"
                          class="cell-input cell-narrow"
                          :class="{ 'is-changed': entryChanges(singleEntry(row)).cost, 'is-invalid': entryInvalid(singleEntry(row)).cost }"
                          :title="entryLabel(singleEntry(row))"
                          :aria-label="`${section} cost of ${row.item}`"
                        />
                      </td>
                    </template>
                    <template v-else>
                      <td>{{ qty(live(row)[`${section}_qty`]) }}</td>
                      <td>{{ money(live(row)[`${section}_cost`]) }}</td>
                    </template>
                    <td>{{ money(live(row)[`${section}_total`]) }}</td>
                  </template>
                  <td>{{ qty(live(row).ending_qty) }}</td>
                  <td>{{ money(live(row).ending_cost) }}</td>
                  <td>{{ money(live(row).ending_qty * live(row).ending_cost) }}</td>
                </tr>

                <!-- Several entries behind the row: one editable line each -->
                <template v-if="editMode && row.entries?.length > 1">
                  <tr
                    v-for="entry in row.entries"
                    :key="entryKey(entry)"
                    class="entry-row"
                    :class="{ 'row-changed': isEntryChanged(entry) }"
                  >
                    <td colspan="3" class="entry-label" :title="entry.transaction_ids.length > 1 ? `Taken from ${entry.transaction_ids.length} batches` : undefined">
                      ↳ {{ entryLabel(entry) }}
                    </td>
                    <td colspan="4"></td>
                    <template v-for="section in SECTIONS" :key="section">
                      <template v-if="entry.section === section && drafts[entryKey(entry)]">
                        <td>
                          <input
                            v-model.number="drafts[entryKey(entry)].qty"
                            type="number"
                            step="any"
                            min="0.01"
                            class="cell-input cell-narrow"
                            :class="{ 'is-changed': entryChanges(entry).qty, 'is-invalid': entryInvalid(entry).qty }"
                            :disabled="entry.borrow"
                            :title="entry.borrow ? 'Part of a borrow record: record a Return instead of changing it' : undefined"
                            :aria-label="`${section} quantity, ${entryLabel(entry)}`"
                          />
                        </td>
                        <td>
                          <input
                            v-model.number="drafts[entryKey(entry)].cost"
                            type="number"
                            step="any"
                            min="0"
                            class="cell-input cell-narrow"
                            :class="{ 'is-changed': entryChanges(entry).cost, 'is-invalid': entryInvalid(entry).cost }"
                            :aria-label="`${section} cost, ${entryLabel(entry)}`"
                          />
                        </td>
                        <td>{{ money((Number(drafts[entryKey(entry)].qty) || 0) * (Number(drafts[entryKey(entry)].cost) || 0)) }}</td>
                      </template>
                      <td v-else colspan="3"></td>
                    </template>
                    <td colspan="3"></td>
                  </tr>
                </template>
              </template>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Edit mode: Save stays on screen while scrolling the report -->
    <UnsavedChangesBar
      v-if="editMode"
      :count="changeCount"
      :saving="saving"
      :error="saveError"
      hint="Saved changes also update the Stock Card."
      @save="saveEdits"
      @discard="discardEdits"
    />
  </div>
</template>

<style scoped>
.report-table th,
.report-table td {
  white-space: nowrap;
  font-size: 0.82rem;
}

.report-table th.grp {
  text-align: center;
}

.parent-row {
  background: var(--bg-subtle);
  font-weight: 600;
}

.variant {
  padding-left: 2rem !important;
  border-left: 3px solid var(--border-hover);
}

.report-table.is-editing td {
  vertical-align: middle;
}

/* The stock card entries listed under a row in edit mode */
.entry-row td {
  background: var(--bg-subtle);
  font-size: 0.78rem;
}

.entry-label {
  padding-left: 2rem !important;
  color: var(--text-muted);
  max-width: 340px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.report-card-link {
  display: inline-flex;
  margin-left: 6px;
  color: var(--text-subtle);
  vertical-align: -1px;
}

.report-card-link:hover {
  color: var(--color-primary);
}

.report-edit-note {
  margin: -0.75rem 0 1.25rem;
  padding: 0.65rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
  color: var(--text-main);
  font-size: 0.85rem;
}
</style>
