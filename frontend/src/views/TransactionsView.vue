<script setup>
import { ref, onMounted } from 'vue'
import { dashboardApi } from '../api/dashboard'
import { useAutoReload, deduplicateById } from '../composables/useAutoReload'
import AppPagination from '../components/AppPagination.vue'

const transactions = ref([])
const total = ref(0)
const totalPages = ref(1)
const currentPage = ref(1)
const limit = ref(25)
const loading = ref(true)

const search = ref('')
const selectedType = ref('')
const dateFrom = ref('')
const dateTo = ref('')

async function loadTransactions() {
  loading.value = true
  try {
    const res = await dashboardApi.getTransactions({
      page: currentPage.value,
      limit: limit.value,
      search: search.value,
      type: selectedType.value,
      date_from: dateFrom.value,
      date_to: dateTo.value,
    })

    if (res.status && res.data) {
      transactions.value = deduplicateById(res.data.transactions || [], 'transaction_id')
      total.value = res.data.total || 0
      totalPages.value = res.data.totalPages || 1
      currentPage.value = res.data.page || 1
    }
  } catch (err) {
    console.error('Failed to load transaction log', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(loadTransactions)

function handleFilter() {
  currentPage.value = 1
  loadTransactions()
}

function handleReset() {
  search.value = ''
  selectedType.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  currentPage.value = 1
  loadTransactions()
}

function handlePageChange(newPage) {
  currentPage.value = newPage
  loadTransactions()
}

function handleLimitChange(newLimit) {
  limit.value = newLimit
  currentPage.value = 1
  loadTransactions()
}

onMounted(() => {
  loadTransactions()
})
</script>

<template>
  <div class="transactions-page">
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Audit & Compliance</p>
        <h1 class="hero-title">Transaction Log</h1>
        <p class="hero-subtitle">
          Comprehensive historical log of all material receipts, releases, returns, and inventory adjustments.
        </p>
      </div>

      <div class="hero-badge-widget">
        <span>Logged Entries</span>
        <strong>{{ total }}</strong>
        <small>Total audited movements</small>
      </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="panel">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <form @submit.prevent="handleFilter" style="display: flex; gap: 0.85rem; flex-wrap: wrap; width: 100%; align-items: flex-end;">
          <div style="flex: 1; min-width: 220px;">
            <label class="form-label">Search Product / Keyword</label>
            <input
              v-model="search"
              type="text"
              class="form-input"
              placeholder="e.g. Cap Seal, UBE..."
              style="width: 100%;"
            />
          </div>

          <div style="min-width: 160px;">
            <label class="form-label">Movement Type</label>
            <select v-model="selectedType" class="form-select" style="width: 100%;">
              <option value="">All Types</option>
              <option value="receipt">Stock In (Receipt)</option>
              <option value="issue">Stock Out (Issue)</option>
              <option value="adjust_out">Adjust Out</option>
              <option value="borrow">Borrow</option>
              <option value="return">Return</option>
            </select>
          </div>

          <div style="min-width: 150px;">
            <label class="form-label">From Date</label>
            <input v-model="dateFrom" type="date" class="form-input" style="width: 100%;" />
          </div>

          <div style="min-width: 150px;">
            <label class="form-label">To Date</label>
            <input v-model="dateTo" type="date" class="form-input" style="width: 100%;" />
          </div>

          <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary">Filter</button>
            <button type="button" class="btn btn-secondary" @click="handleReset">Reset</button>
          </div>
        </form>
      </div>

      <!-- Transaction Records Table -->
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Product Item</th>
              <th>Type</th>
              <th style="text-align: right;">Quantity</th>
              <th style="text-align: right;">Unit Cost</th>
              <th>Reference / PO</th>
              <th>Destination / Office</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in transactions" :key="t.transaction_id">
              <td style="font-family: var(--font-mono); font-size: 0.825rem; color: var(--text-muted);">
                {{ t.date }}
              </td>
              <td>
                <strong>{{ t.item }}</strong>
              </td>
              <td>
                <span
                  class="badge"
                  :class="t.transaction_type === 'receipt' ? 'badge-success' : t.transaction_type === 'issue' ? 'badge-info' : 'badge-warning'"
                  style="text-transform: uppercase;"
                >
                  {{ t.transaction_type }}
                </span>
              </td>
              <td style="text-align: right; font-weight: 700;">
                <span :style="{ color: t.transaction_type === 'receipt' ? 'var(--color-success)' : 'inherit' }">
                  {{ t.transaction_type === 'receipt' ? '+' : '-' }}{{ t.transaction_qty }}
                </span>
              </td>
              <td style="text-align: right; font-family: var(--font-mono); font-size: 0.85rem;">
                ₱{{ Number(t.transaction_unit_cost || 0).toFixed(2) }}
              </td>
              <td>
                <span class="badge badge-neutral" style="font-family: var(--font-mono);">
                  {{ t.reference || '—' }}
                </span>
              </td>
              <td>
                <span style="font-size: 0.85rem;">{{ t.office || 'Direct' }}</span>
              </td>
            </tr>

            <tr v-if="loading">
              <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                Loading audit transactions...
              </td>
            </tr>
            <tr v-else-if="!transactions.length">
              <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                No transaction records match the specified filters.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="total > 0"
        :current-page="currentPage"
        :total-pages="totalPages"
        :total-items="total"
        :page-size="limit"
        :page-size-options="[10, 25, 50, 100]"
        item-name="transactions"
        @update:current-page="handlePageChange"
        @update:page-size="handleLimitChange"
      />
    </div>
  </div>
</template>
