<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Download, ArrowLeftRight, Pencil, FileSpreadsheet, AlertTriangle, AlertOctagon, Clock, Archive } from 'lucide-vue-next'
import { stockApi } from '../api/stock'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById } from '../composables/useAutoReload'
import StockModal from '../components/StockModal.vue'
import EditStockcardModal from '../components/EditStockcardModal.vue'
import ImportStockcardsModal from '../components/ImportStockcardsModal.vue'
import ProductSearch from '../components/ProductSearch.vue'
import { toast } from '../composables/useToast'
import AppPagination from '../components/AppPagination.vue'
import { typeLabel, typeBadge } from '../utils/transactionTypes'

const route = useRoute()
const authStore = useAuthStore()
const isImportOpen = ref(false)
const items = ref([])
// ?item_id= lets other pages (batches, notifications) open a specific product
const selectedItemId = ref(Number(route.query.item_id) || 0)
const itemInfo = ref({})
const batches = ref([]) // this product's batches that have an expiration date

// ── Expiry warnings ──
// Tiers follow the product's own settings; the last 3 days always count as critical
const EXPIRY_REMINDER_DAYS = 3
const warnDays = computed(() => Number(itemInfo.value.expiry_warning_days) || 30)
const dangerDays = computed(() => Math.max(Number(itemInfo.value.expiry_danger_days) || 7, EXPIRY_REMINDER_DAYS))

function shortDate(value) {
  if (!value) return ''
  const d = new Date(`${String(value).slice(0, 10)}T00:00:00`)
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function qtyText(value) {
  const n = Number(value || 0)
  return Number.isInteger(n) ? String(n) : n.toFixed(2)
}

// tone: 'expired' | 'critical' | 'warning' | 'ok'
function expiryStatus(batch) {
  const days = Number(batch.days_left)
  if (days < 0) return { tone: 'expired', label: `Expired ${-days} day${days === -1 ? '' : 's'} ago` }
  if (days === 0) return { tone: 'critical', label: 'Expires today' }
  if (days <= dangerDays.value) return { tone: 'critical', label: days === 1 ? '1 day left' : `${days} days left` }
  if (days <= warnDays.value) return { tone: 'warning', label: `${days} days left` }
  return { tone: 'ok', label: `${days} days left` }
}

const batchById = computed(() => Object.fromEntries(batches.value.map((b) => [Number(b.batch_id), b])))

// Batches still in stock that are expired or inside the warning window, worst first
const TONE_RANK = { expired: 0, critical: 1, warning: 2, ok: 3 }
const alertBatches = computed(() =>
  batches.value
    .filter((b) => Number(b.current_qty) > 0 && expiryStatus(b).tone !== 'ok')
    .sort((a, b) => TONE_RANK[expiryStatus(a).tone] - TONE_RANK[expiryStatus(b).tone] || Number(a.days_left) - Number(b.days_left))
)
const expiredCount = computed(() => alertBatches.value.filter((b) => expiryStatus(b).tone === 'expired').length)
const soonCount = computed(() => alertBatches.value.length - expiredCount.value)
const bannerTone = computed(() =>
  alertBatches.value.some((b) => ['expired', 'critical'].includes(expiryStatus(b).tone)) ? 'is-danger' : 'is-warning'
)

// Soonest-expiring batch that still has stock (expired ones included)
const nearestExpiry = computed(() => batches.value.find((b) => Number(b.current_qty) > 0) || null)

// Ledger rows: receipts/returns created the batch, so they carry its expiry
function rowBatch(entry) {
  if (!['receipt', 'return'].includes(entry.transaction_type)) return null
  return batchById.value[Number(entry.batch_id)] || null
}

function rowTone(entry) {
  const batch = rowBatch(entry)
  if (!batch || Number(batch.current_qty) <= 0) return ''
  const tone = expiryStatus(batch).tone
  return tone === 'ok' ? '' : `ledger-row-${tone}`
}
const stockcard = ref([])
const totalPages = ref(1)
const currentPage = ref(1)
const total = ref(0)
const pageSize = ref(15)
const loading = ref(true)
const filterType = ref('latest')
const filterYear = ref(0)
const filterMonth = ref('')
const search = ref('')

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const thisYear = new Date().getFullYear()
const YEARS = Array.from({ length: thisYear - 2019 }, (_, i) => thisYear - i)

function peso(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

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
      year: filterYear.value || undefined,
      month: filterMonth.value || undefined,
      page: currentPage.value,
      limit: pageSize.value,
      search: search.value,
    })

    if (res.status && res.data) {
      items.value = deduplicateById(res.data.items || [], 'product_id')
      itemInfo.value = res.data.itemInfo || {}
      batches.value = res.data.batches || []
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

// A notification or link opened while already on this page switches the product
watch(() => route.query.item_id, (id) => {
  if (Number(id) > 0) selectedItemId.value = Number(id)
})

watch(selectedItemId, () => {
  currentPage.value = 1
  loadStockcard()
})

watch([filterType, filterYear, filterMonth], () => {
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
          <Download :size="15" />
          <span>Export Stock Card</span>
        </router-link>
        <button
          v-if="authStore.levelId === 2 || authStore.levelId === 3"
          type="button"
          class="btn btn-secondary"
          title="Import Appendix 58 stock cards from an Excel file"
          @click="isImportOpen = true"
        >
          <FileSpreadsheet :size="15" />
          <span>Import from Excel</span>
        </button>
        <button
          type="button"
          class="btn btn-primary"
          @click="isStockModalOpen = true"
        >
          <ArrowLeftRight :size="15" />
          <span>Record Stock In / Out</span>
        </button>
      </div>
    </div>

    <!-- Product Selection & Metadata Card -->
    <div class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <div class="item-picker">
          <label class="form-label" style="display: block; margin-bottom: 0.35rem;">Stock Item</label>
          <ProductSearch v-model="selectedItemId" :items="items" @scan-error="toast($event, 'error')" />
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
          <div>
            <label class="form-label" style="display: block; margin-bottom: 0.35rem;">Month</label>
            <select v-model="filterMonth" class="form-select">
              <option value="">All months</option>
              <option v-for="(m, i) in MONTHS" :key="m" :value="String(i + 1).padStart(2, '0')">{{ m }}</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="display: block; margin-bottom: 0.35rem;">Year</label>
            <select v-model.number="filterYear" class="form-select">
              <option :value="0">All years</option>
              <option v-for="y in YEARS" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
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

        <div>
          <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Nearest Expiry</span>
          <div v-if="nearestExpiry" class="nearest-expiry">
            <span class="expiry-chip" :class="`is-${expiryStatus(nearestExpiry).tone}`">
              {{ expiryStatus(nearestExpiry).label }}
            </span>
            <span class="nearest-expiry-date">{{ shortDate(nearestExpiry.expiration_date) }}</span>
          </div>
          <div v-else style="font-size: 0.85rem; color: var(--text-muted);">No dated stock</div>
        </div>
      </div>

      <!-- Viewing an archived product: history only -->
      <div v-if="itemInfo.archived_at" class="archived-banner" role="status">
        <Archive :size="18" />
        <div>
          <strong>This product is archived.</strong>
          <span>
            Its history is shown below, but no stock can be recorded for it{{ itemInfo.archive_reason ? ` (reason: ${itemInfo.archive_reason})` : '' }}.
            Restore it from Products → Archived to use it again.
          </span>
        </div>
        <router-link to="/products" class="btn btn-sm btn-secondary">Go to Products</router-link>
      </div>

      <!-- Expiry warning: batches still in stock that are expired or nearing expiry -->
      <div v-if="alertBatches.length" class="expiry-banner" :class="bannerTone" role="alert">
        <div class="expiry-banner-head">
          <component :is="bannerTone === 'is-danger' ? AlertOctagon : AlertTriangle" :size="20" class="expiry-banner-icon" />
          <div>
            <strong>
              <template v-if="expiredCount">{{ expiredCount }} expired batch{{ expiredCount === 1 ? '' : 'es' }} still in stock</template>
              <template v-if="expiredCount && soonCount"> · </template>
              <template v-if="soonCount">{{ soonCount }} batch{{ soonCount === 1 ? '' : 'es' }} expiring within {{ warnDays }} days</template>
            </strong>
            <p>
              Issue the batches that expire first.
              <template v-if="expiredCount">Expired stock should be taken out with an Adjust Out, reason "Expired".</template>
            </p>
          </div>
        </div>
        <ul class="expiry-banner-list">
          <li v-for="b in alertBatches" :key="b.batch_id" :class="`is-${expiryStatus(b).tone}`">
            <span class="expiry-chip" :class="`is-${expiryStatus(b).tone}`">
              <component :is="expiryStatus(b).tone === 'expired' ? AlertOctagon : Clock" :size="12" />
              {{ expiryStatus(b).label }}
            </span>
            <span class="expiry-batch-no">{{ b.batch_no || `Batch #${b.batch_id}` }}</span>
            <span class="expiry-batch-meta">
              <strong>{{ qtyText(b.current_qty) }} {{ itemInfo.unit_name }}</strong> left · expires {{ shortDate(b.expiration_date) }}
              <template v-if="b.manufacturing_date"> · made {{ shortDate(b.manufacturing_date) }}</template>
            </span>
          </li>
        </ul>
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
              <th style="text-align: right;">Price</th>
              <th>Expiry</th>
              <th>Action Type</th>
              <th style="text-align: center; width: 95px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in stockcard" :key="entry.transaction_id" :class="rowTone(entry)">
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
              <td style="text-align: right; white-space: nowrap;">
                ₱{{ peso(entry.transaction_unit_cost || entry.copy_unit_cost) }}
                <div v-if="entry.copy_label" style="font-size: 0.75rem; color: var(--text-muted);">{{ entry.copy_label }}</div>
              </td>
              <td class="ledger-expiry">
                <template v-if="rowBatch(entry)">
                  <span class="ledger-expiry-date">{{ shortDate(rowBatch(entry).expiration_date) }}</span>
                  <span
                    v-if="Number(rowBatch(entry).current_qty) <= 0"
                    class="ledger-expiry-note"
                    title="This batch has no stock left"
                  >Used up</span>
                  <span v-else class="expiry-chip" :class="`is-${expiryStatus(rowBatch(entry)).tone}`">
                    {{ expiryStatus(rowBatch(entry)).label }}
                  </span>
                </template>
                <span v-else style="color: var(--text-muted);">—</span>
              </td>
              <td>
                <span
                  class="badge"
                  :class="typeBadge(entry.transaction_type)"
                  style="text-transform: uppercase;"
                >
                  {{ typeLabel(entry.transaction_type) }}
                </span>
              </td>
              <td style="text-align: center;">
                <button
                  type="button"
                  class="btn btn-sm btn-secondary"
                  style="padding: 4px 10px; font-size: 12px; gap: 5px;"
                  title="Edit or correct this transaction entry"
                  @click="openEditModal(entry)"
                >
                  <Pencil :size="13" />
                  <span>Edit</span>
                </button>
              </td>
            </tr>

            <tr v-if="loading">
              <td colspan="10" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                Loading ledger entries...
              </td>
            </tr>
            <tr v-else-if="!stockcard.length">
              <td colspan="10" style="text-align: center; padding: 3rem; color: var(--text-muted);">
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

    <!-- Excel stock card import (managers) -->
    <ImportStockcardsModal
      :is-open="isImportOpen"
      @close="isImportOpen = false"
      @imported="selectedItemId = 0; loadStockcard()"
    />
  </div>
</template>

<style scoped>
.archived-banner {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin: 1rem 1.5rem 0;
  padding: 0.85rem 1rem;
  border: 1px solid var(--border-subtle);
  border-left: 5px solid var(--text-muted);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  color: var(--text-muted);
}

.archived-banner div {
  flex: 1;
  min-width: 220px;
  display: flex;
  flex-direction: column;
  font-size: 0.85rem;
}

.archived-banner strong {
  color: var(--text-main);
}

/* ── Expiry warnings ── */
.expiry-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.1rem 0.55rem;
  border-radius: var(--radius-full);
  font-size: 0.72rem;
  font-weight: 800;
  white-space: nowrap;
}

.expiry-chip.is-expired {
  background: var(--color-danger);
  color: #fff;
}

.expiry-chip.is-critical {
  background: var(--color-danger-bg);
  color: var(--color-danger);
  border: 1px solid var(--color-danger);
}

.expiry-chip.is-warning {
  background: var(--color-warning-bg);
  color: var(--color-warning);
}

.expiry-chip.is-ok {
  background: var(--bg-subtle);
  color: var(--text-muted);
}

.nearest-expiry {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
  margin-top: 0.15rem;
}

.nearest-expiry-date {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.expiry-banner {
  margin: 1rem 1.5rem;
  padding: 1rem 1.15rem;
  border: 1px solid;
  border-left-width: 5px;
  border-radius: var(--radius-md);
}

.expiry-banner.is-danger {
  border-color: var(--color-danger);
  background: var(--color-danger-bg);
}

.expiry-banner.is-warning {
  border-color: var(--color-warning);
  background: var(--color-warning-bg);
}

.expiry-banner-head {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.expiry-banner-icon {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.expiry-banner.is-danger .expiry-banner-icon,
.expiry-banner.is-danger .expiry-banner-head strong {
  color: var(--color-danger);
}

.expiry-banner.is-warning .expiry-banner-icon,
.expiry-banner.is-warning .expiry-banner-head strong {
  color: var(--color-warning);
}

.expiry-banner-head strong {
  font-size: 0.95rem;
}

.expiry-banner-head p {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--text-main);
}

.expiry-banner-list {
  list-style: none;
  margin: 0.75rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.4rem;
}

.expiry-banner-list li {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.35rem 0.75rem;
  padding: 0.5rem 0.75rem;
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  font-size: 0.82rem;
}

.expiry-batch-no {
  font-family: var(--font-mono);
  font-size: 0.78rem;
  font-weight: 700;
}

.expiry-batch-meta {
  color: var(--text-muted);
}

.expiry-batch-meta strong {
  color: var(--text-main);
}

/* Ledger rows whose batch still has stock and is expired / nearing expiry */
.ledger-row-expired td {
  background: var(--color-danger-bg);
}

.ledger-row-critical td {
  background: color-mix(in srgb, var(--color-danger-bg) 55%, transparent);
}

.ledger-row-warning td {
  background: color-mix(in srgb, var(--color-warning-bg) 60%, transparent);
}

.ledger-row-expired td:first-child,
.ledger-row-critical td:first-child {
  box-shadow: inset 4px 0 0 var(--color-danger);
}

.ledger-row-warning td:first-child {
  box-shadow: inset 4px 0 0 var(--color-warning);
}

.ledger-expiry {
  white-space: nowrap;
}

.ledger-expiry-date {
  display: block;
  font-size: 0.8rem;
  margin-bottom: 0.15rem;
}

.ledger-expiry-note {
  font-size: 0.72rem;
  color: var(--text-muted);
}

@media (max-width: 640px) {
  .expiry-banner {
    margin: 0.75rem;
  }
}

.item-picker {
  flex: 1;
  min-width: 0;
  max-width: 560px;
}

@media (max-width: 760px) {
  .item-picker {
    max-width: none;
    flex-basis: 100%;
  }
}
</style>
