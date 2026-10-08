<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Download } from 'lucide-vue-next'
import { reportsApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const now = new Date()
const YEARS = Array.from({ length: now.getFullYear() - 2019 }, (_, i) => now.getFullYear() - i)

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

async function load() {
  loading.value = true
  try {
    const res = await reportsApi.getBatchList({ ...filters })
    groupedRows.value = res.data?.groupedRows || {}
    productTypes.value = res.data?.productTypes || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load the report.'), 'error')
  } finally {
    loading.value = false
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

    <form class="panel" style="padding: 1.25rem; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;" @submit.prevent="load">
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
    </form>

    <div v-if="loading" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">Loading report…</div>
    <div v-else-if="!groups.length" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">No inventory activity for this period.</div>

    <section v-for="group in groups" :key="group.type" class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header"><h2 class="panel-title">{{ group.type }}</h2></div>
      <div class="table-responsive">
        <table class="data-table report-table">
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
              <tr v-for="row in rows" :key="`${row.product_id}-${row.copy_id}`">
                <td>{{ rows.length > 1 ? '' : row.counter }}</td>
                <td>{{ rows.length > 1 ? '' : row.stock_no }}</td>
                <td :class="{ variant: rows.length > 1 }">
                  <template v-if="rows.length > 1">↳ ₱{{ money(row.copy_unit_cost) }}{{ row.copy_label ? ` (${row.copy_label})` : '' }}</template>
                  <template v-else>{{ row.item }}</template>
                </td>
                <td>{{ qty(row.begin_qty) }}</td>
                <td>{{ row.unit_name }}</td>
                <td>{{ money(row.begin_cost) }}</td>
                <td>{{ money(row.begin_qty * row.begin_cost) }}</td>
                <td>{{ qty(row.purchase_qty) }}</td>
                <td>{{ money(row.purchase_cost) }}</td>
                <td>{{ money(row.purchase_total) }}</td>
                <td>{{ qty(row.used_qty) }}</td>
                <td>{{ money(row.used_cost) }}</td>
                <td>{{ money(row.used_total) }}</td>
                <td>{{ qty(row.spoiled_qty) }}</td>
                <td>{{ money(row.spoiled_cost) }}</td>
                <td>{{ money(row.spoiled_total) }}</td>
                <td>{{ qty(row.ending_qty) }}</td>
                <td>{{ money(row.ending_cost) }}</td>
                <td>{{ money(row.ending_qty * row.ending_cost) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
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
</style>
