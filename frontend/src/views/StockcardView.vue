<script setup>
import { ref, onMounted, watch } from 'vue'
import { stockApi } from '../api/stock'
import { useAutoReload, deduplicateById } from '../composables/useAutoReload'
import StockModal from '../components/StockModal.vue'
import EditStockcardModal from '../components/EditStockcardModal.vue'
import AppPagination from '../components/AppPagination.vue'

const items = ref([])
const selectedItemId = ref(0)
const itemInfo = ref({})
const stockcard = ref([])
const totalPages = ref(1)
const currentPage = ref(1)
const total = ref(0)
const pageSize = ref(15)
const loading = ref(true)
const filterType = ref('latest')
const search = ref('')

const isStockModalOpen = ref(false)
const isEditModalOpen = ref(false)
const selectedTransactionToEdit = ref(null)

function openEditModal(entry) {
  selectedTransactionToEdit.value = entry
  isEditModalOpen.value = true
}

function handleTransactionSaved() {
  loadStockcard()
}

function handleTransactionDeleted() {
  loadStockcard()
}

async function loadStockcard() {
  loading.value = true
  try {
    const res = await stockApi.getStockcard({
      item_id: selectedItemId.value,
      filter_type: filterType.value,
      page: currentPage.value,
      limit: pageSize.value,
      search: search.value,
    })

    if (res.status && res.data) {
      items.value = deduplicateById(res.data.items || [], 'product_id')
      itemInfo.value = res.data.itemInfo || {}
      stockcard.value = deduplicateById(res.data.stockcard || [], 'transaction_id')
      totalPages.value = res.data.totalPages || 1
      total.value = res.data.total || 0
      selectedItemId.value = res.data.itemId || 0
    }
  } catch (err) {
    console.error('Failed to load stockcard', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(loadStockcard)

watch(selectedItemId, () => {
  currentPage.value = 1
  loadStockcard()
})

watch(filterType, () => {
  currentPage.value = 1
  loadStockcard()
})

function handlePageChange(newPage) {
  currentPage.value = newPage
  loadStockcard()
}

function handleLimitChange(newLimit) {
  pageSize.value = newLimit
  currentPage.value = 1
  loadStockcard()
}

onMounted(() => {
  loadStockcard()
})
</script>

<template>
  <div class="stockcard-page">
    <!-- Header Hero -->
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Audited Inventory Ledger</p>
        <h1 class="hero-title">Stockcard Ledger</h1>
        <p class="hero-subtitle">
          Official perpetual inventory record with running balances, verified receipts, and issues.
        </p>
      </div>

      <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <router-link
          to="/export/stockcard"
          class="btn btn-secondary"
          title="Export formatted stock card as PDF or Word"
        >
          <span>📥</span> Export Stock Card
        </router-link>
        <button
          type="button"
          class="btn btn-primary"
          @click="isStockModalOpen = true"
        >
          <span>⇄</span> Record Stock In / Out
        </button>
      </div>
    </div>

    <!-- Product Selection & Metadata Card -->
    <div class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <div style="flex: 1; max-width: 500px;">
          <label class="form-label" style="display: block; margin-bottom: 0.35rem;">Select Stock Item</label>
          <select v-model.number="selectedItemId" class="form-select" style="width: 100%; font-weight: 600;">
            <option v-for="item in items" :key="item.product_id" :value="Number(item.product_id)">
              [{{ item.product_no }}] {{ item.product }} - {{ item.stock_no || '' }}
            </option>
          </select>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: flex-end;">
          <div>
            <label class="form-label" style="display: block; margin-bottom: 0.35rem;">Order</label>
            <select v-model="filterType" class="form-select">
              <option value="latest">Latest First (DESC)</option>
              <option value="oldest">Oldest First (ASC)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Item Profile Details Grid -->
      <div v-if="itemInfo.item_name" style="padding: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; border-bottom: 1px solid var(--border-subtle);">
        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Item Name</span>
          <div style="font-weight: 800; font-size: 1.15rem; color: var(--text-main);">{{ itemInfo.item_name }}</div>
          <div style="font-size: 0.8rem; color: var(--text-subtle);">{{ itemInfo.description || 'Standard specification' }}</div>
        </div>

        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Stock No.</span>
          <div style="font-family: var(--font-mono); font-weight: 700; color: var(--color-primary);">
            {{ itemInfo.stock_no || 'N/A' }}
          </div>
        </div>

        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Unit & Packaging</span>
          <div style="font-weight: 600; color: var(--text-main);">
            {{ itemInfo.unit_name || 'pcs' }} ({{ itemInfo.measurement || 'Single' }})
          </div>
        </div>

        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Entity & Fund Cluster</span>
          <div style="font-size: 0.85rem; color: var(--text-main); font-weight: 600;">
            {{ itemInfo.entity_name }} (Fund {{ itemInfo.fund_cluster || '06' }})
          </div>
        </div>

        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Reorder Point</span>
          <div>
            <span class="badge badge-warning" style="font-weight: 700;">
              {{ itemInfo.re_order_point }} {{ itemInfo.unit_name }}
            </span>
          </div>
        </div>
      </div>

      <!-- Ledger Table -->
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Reference / PO</th>
              <th>Office / Source / Purpose</th>
              <th style="text-align: right; color: var(--color-success);">Receipt Qty</th>
              <th style="text-align: right; color: var(--color-primary);">Issue Qty</th>
              <th style="text-align: right;">Balance Qty</th>
              <th>Action Type</th>
              <th style="text-align: center; width: 95px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in stockcard" :key="entry.transaction_id">
              <td style="font-family: var(--font-mono); font-size: 0.825rem; color: var(--text-muted);">
                {{ entry.date ? entry.date.split(' ')[0] : '—' }}
              </td>
              <td>
                <span class="badge badge-neutral" style="font-family: var(--font-mono);">
                  {{ entry.reference || 'N/A' }}
                </span>
              </td>
              <td>
                <span style="font-weight: 500;">{{ entry.office || 'Direct' }}</span>
              </td>
              <td style="text-align: right; font-weight: 700; color: var(--color-success);">
                {{ Number(entry.receipt_qty) > 0 ? '+' + entry.receipt_qty : '—' }}
              </td>
              <td style="text-align: right; font-weight: 700; color: var(--color-danger);">
                {{ Number(entry.issue_qty) > 0 ? '-' + entry.issue_qty : '—' }}
              </td>
              <td style="text-align: right; font-family: var(--font-mono); font-weight: 800; font-size: 0.95rem;">
                {{ entry.balance }}
              </td>
              <td>
                <span
                  class="badge"
                  :class="entry.transaction_type === 'receipt' ? 'badge-success' : 'badge-info'"
                  style="text-transform: uppercase;"
                >
                  {{ entry.transaction_type }}
                </span>
              </td>
              <td style="text-align: center;">
                <button
                  type="button"
                  class="btn btn-sm btn-secondary"
                  style="padding: 4px 10px; font-size: 12px; gap: 4px;"
                  title="Edit or correct this transaction entry"
                  @click="openEditModal(entry)"
                >
                  <span>✏️</span> Edit
                </button>
              </td>
            </tr>

            <tr v-if="loading">
              <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                Loading ledger entries...
              </td>
            </tr>
            <tr v-else-if="!stockcard.length">
              <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                No stock card transactions recorded for this item.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="total > 0 || totalPages > 1"
        :current-page="currentPage"
        :total-pages="totalPages"
        :total-items="total"
        :page-size="pageSize"
        :page-size-options="[5, 10, 15, 25, 50, 100]"
        item-name="transactions"
        @update:current-page="handlePageChange"
        @update:page-size="handleLimitChange"
      />
    </div>

    <!-- Stock Movement Modal -->
    <StockModal
      :is-open="isStockModalOpen"
      :selected-product-id="selectedItemId"
      @close="isStockModalOpen = false"
      @saved="loadStockcard"
    />

    <!-- Edit Stockcard Entry Modal -->
    <EditStockcardModal
      :is-open="isEditModalOpen"
      :transaction="selectedTransactionToEdit"
      :product-name="itemInfo.item_name"
      :current-stock="Number(stockcard[0]?.balance) || 0"
      @close="isEditModalOpen = false"
      @saved="handleTransactionSaved"
      @deleted="handleTransactionDeleted"
    />
  </div>
</template>
