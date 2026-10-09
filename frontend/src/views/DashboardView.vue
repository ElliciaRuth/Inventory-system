<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { dashboardApi } from '../api/dashboard'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById } from '../composables/useAutoReload'
import AdminDashboardView from './AdminDashboardView.vue'
import AppPagination from '../components/AppPagination.vue'
import { typeLabel, typeBadge, isStockIn, signedQty } from '../utils/transactionTypes'

const emit = defineEmits(['update-alerts'])

const authStore = useAuthStore()
const router = useRouter()
const loading = ref(true)

// Check if user is Admin / Technical Staff (Level 4 or admin role)
const isAdminAccount = computed(() => {
  return authStore.levelId >= 4 || authStore.role?.toLowerCase().includes('technical')
})

const dashboard = ref({
  summary: {
    totalItems: 0,
    lowStockCount: 0,
    expiringCount: 0,
    outOfStockCount: 0,
    activeBorrowCount: 0,
  },
  lowStock: [],
  expiring: [],
  outOfStock: [],
  recentTransactions: [],
  activeBorrows: [],
})

const activeTab = ref(null) // 'low-stock' | 'out-of-stock' | 'expiring' | 'borrowed' | null

// A recent activity row opens that product's stockcard; staff (no stockcard access)
// get the transaction log filtered to the product instead
function openActivity(tx) {
  const productId = Number(tx.product_id) || 0
  if (authStore.levelId >= 2 && productId > 0) {
    router.push({ path: '/stockcard', query: { item_id: productId } })
  } else {
    router.push({ path: '/transactions', query: { search: tx.item } })
  }
}

const borrowedOutTotal = computed(() => {
  return (dashboard.value.activeBorrows || []).reduce((sum, b) => {
    return sum + (Number(b.net_borrowed) || Number(b.total_borrowed) || 0)
  }, 0)
})

const totalAlerts = computed(() => {
  return (
    Number(dashboard.value.summary.lowStockCount || 0) +
    Number(dashboard.value.summary.expiringCount || 0) +
    Number(dashboard.value.summary.outOfStockCount || dashboard.value.outOfStock?.length || 0)
  )
})

async function fetchDashboard() {
  if (isAdminAccount.value) {
    loading.value = false
    emit('update-alerts', 0)
    return
  }

  loading.value = true
  try {
    const res = await dashboardApi.getOverview()
    if (res.status && res.data) {
      const d = res.data
      d.recentTransactions = deduplicateById(d.recentTransactions || [], 'transaction_id')
      d.lowStock = deduplicateById(d.lowStock || [], 'item')
      d.expiring = deduplicateById(d.expiring || [], 'batch_id')
      d.outOfStock = deduplicateById(d.outOfStock || [], 'item')
      d.activeBorrows = deduplicateById(d.activeBorrows || [], 'item')
      dashboard.value = d
      emit('update-alerts', 0)
    }
  } catch (err) {
    console.error('Failed to load dashboard data', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(fetchDashboard)

// Alert rows open the stockcard of that product (plain stockcard if the id is missing)
function productStockcard(item) {
  const productId = Number(item?.product_id) || 0
  return productId > 0 ? { path: '/stockcard', query: { item_id: productId } } : '/stockcard'
}

function toggleTab(tab) {
  activeTab.value = activeTab.value === tab ? null : tab
}

// ── Phones: the details open as a sheet over the page instead of below all the cards ──
const PHONE_QUERY = '(max-width: 640px)'
const isPhone = ref(false)
let phoneQuery = null
const syncPhone = () => { isPhone.value = !!phoneQuery?.matches }
const sheetOpen = computed(() => isPhone.value && !!activeTab.value)

function closeOnEscape(event) {
  if (event.key === 'Escape' && sheetOpen.value) activeTab.value = null
}

onMounted(() => {
  phoneQuery = window.matchMedia?.(PHONE_QUERY) || null
  syncPhone()
  phoneQuery?.addEventListener?.('change', syncPhone)
  document.addEventListener('keydown', closeOnEscape)
})

onUnmounted(() => {
  phoneQuery?.removeEventListener?.('change', syncPhone)
  document.removeEventListener('keydown', closeOnEscape)
  document.body.style.overflow = ''
})

// The page behind an open sheet doesn't scroll
watch(sheetOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
})

// ── Customizable Pagination Logic (Avoids Long Scrolling) ──
const pageSizeOptions = [5, 10, 25, 50]

const lowStockPage = ref(1)
const lowStockPageSize = ref(5)

const outOfStockPage = ref(1)
const outOfStockPageSize = ref(5)

const expiringPage = ref(1)
const expiringPageSize = ref(5)

const borrowedPage = ref(1)
const borrowedPageSize = ref(5)

const recentTxPage = ref(1)
const recentTxPageSize = ref(5)

watch(lowStockPageSize, () => { lowStockPage.value = 1 })
watch(outOfStockPageSize, () => { outOfStockPage.value = 1 })
watch(expiringPageSize, () => { expiringPage.value = 1 })
watch(borrowedPageSize, () => { borrowedPage.value = 1 })
watch(recentTxPageSize, () => { recentTxPage.value = 1 })

function paginate(list, page, pageSize) {
  const total = list.length
  const totalPages = Math.max(1, Math.ceil(total / pageSize))
  const safePage = Math.min(Math.max(1, page), totalPages)
  const start = (safePage - 1) * pageSize
  const end = Math.min(start + pageSize, total)
  return {
    items: list.slice(start, end),
    page: safePage,
    totalPages,
    total,
    start: total === 0 ? 0 : start + 1,
    end,
  }
}


const pLowStock = computed(() => paginate(dashboard.value.lowStock || [], lowStockPage.value, lowStockPageSize.value))
const pOutOfStock = computed(() => paginate(dashboard.value.outOfStock || [], outOfStockPage.value, outOfStockPageSize.value))
const pExpiring = computed(() => paginate(dashboard.value.expiring || [], expiringPage.value, expiringPageSize.value))
const pBorrowed = computed(() => paginate(dashboard.value.activeBorrows || [], borrowedPage.value, borrowedPageSize.value))
const pRecentTx = computed(() => paginate(dashboard.value.recentTransactions || [], recentTxPage.value, recentTxPageSize.value))

watch(isAdminAccount, (newVal) => {
  if (!newVal) {
    fetchDashboard()
  }
})

onMounted(() => {
  fetchDashboard()
})
</script>

<template>
  <div class="dashboard-page">
    <!-- Admin Account: Exclusively renders User Management & System Administration -->
    <AdminDashboardView v-if="isAdminAccount" />

    <!-- Standard Staff / Custodians: Inventory Overview -->
    <div v-else>
      <!-- Hero Banner -->
      <section class="dashboard-hero">
        <div>
          <p class="dashboard-eyebrow">INVENTORY OVERVIEW</p>
          <h1>Welcome Back</h1>
          <p class="dashboard-subtitle">
            Track stock health, review active inventory movements, and inspect recent activity from one clean control center.
          </p>
        </div>
      </section>

      <!-- Summary Stat Cards Grid (Matches User Screenshot & Highlights active card) -->
      <section class="dashboard-summary-grid">
        <!-- 1. Total Products (Button card that redirects to products) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          @click="router.push('/products')"
          title="Click to view all products"
        >
          <div class="summary-card-header">
            <span class="card-label">TOTAL PRODUCTS</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.totalItems }}</strong>
          <small class="card-hint">Click to view products →</small>
        </button>

        <!-- 2. Low Stock Alerts (Clickable button card with active highlight) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          :class="{ 'is-active': activeTab === 'low-stock' }"
          :disabled="!dashboard.lowStock.length"
          @click="toggleTab('low-stock')"
        >
          <div class="summary-card-header">
            <span class="card-label">LOW STOCK ALERTS</span>
            <span v-if="activeTab === 'low-stock'" class="card-active-pill"><span class="pill-dot" aria-hidden="true"></span>Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.lowStockCount }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.lowStock.length">No details to show</template>
            <template v-else-if="activeTab === 'low-stock'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>

        <!-- 3. Out of Stock (Clickable button card with active highlight) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          :class="{ 'is-active': activeTab === 'out-of-stock' }"
          :disabled="!dashboard.outOfStock.length"
          @click="toggleTab('out-of-stock')"
        >
          <div class="summary-card-header">
            <span class="card-label">OUT OF STOCK</span>
            <span v-if="activeTab === 'out-of-stock'" class="card-active-pill"><span class="pill-dot" aria-hidden="true"></span>Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.outOfStockCount || dashboard.outOfStock.length }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.outOfStock.length">No details to show</template>
            <template v-else-if="activeTab === 'out-of-stock'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>

        <!-- 4. Expiring Soon (Clickable button card with active highlight) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          :class="{ 'is-active': activeTab === 'expiring' }"
          :disabled="!dashboard.expiring.length"
          @click="toggleTab('expiring')"
        >
          <div class="summary-card-header">
            <span class="card-label">EXPIRING SOON</span>
            <span v-if="activeTab === 'expiring'" class="card-active-pill"><span class="pill-dot" aria-hidden="true"></span>Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.expiringCount }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.expiring.length">No details to show</template>
            <template v-else-if="activeTab === 'expiring'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>

        <!-- 5. Items Out Borrowed (Clickable button card with active highlight) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          :class="{ 'is-active': activeTab === 'borrowed' }"
          :disabled="!dashboard.activeBorrows.length && !borrowedOutTotal"
          @click="toggleTab('borrowed')"
        >
          <div class="summary-card-header">
            <span class="card-label">ITEMS OUT (BORROWED)</span>
            <span v-if="activeTab === 'borrowed'" class="card-active-pill"><span class="pill-dot" aria-hidden="true"></span>Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.activeBorrowCount || borrowedOutTotal }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.activeBorrows.length && !borrowedOutTotal">No details to show</template>
            <template v-else-if="activeTab === 'borrowed'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>
      </section>

      <!-- Active Content Drawer with Customizable Pagination (Avoids Long Scrolling).
           On phones it is moved to <body> and shown as a bottom sheet over the page. -->
      <Teleport to="body" :disabled="!isPhone">
      <div v-if="sheetOpen" class="dash-sheet-backdrop" aria-hidden="true" @click="activeTab = null"></div>
      <section
        v-if="activeTab"
        class="panel dash-details"
        :class="{ 'is-sheet': sheetOpen }"
        :role="sheetOpen ? 'dialog' : undefined"
        :aria-modal="sheetOpen ? 'true' : undefined"
      >
        <div v-if="sheetOpen" class="dash-sheet-handle" aria-hidden="true"></div>
        <!-- Low Stock Tab Table -->
        <div v-if="activeTab === 'low-stock'">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title">Low Stock Alert Details</h2>
              <p class="panel-subtitle">Items whose current stock is at or below the reorder point.</p>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" @click="activeTab = null">✕ Close</button>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Current Stock</th>
                  <th>Reorder Point</th>
                  <th>Short By</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, idx) in pLowStock.items" :key="idx">
                  <td><strong>{{ item.item }}</strong></td>
                  <td><span class="badge badge-warning">{{ item.stock_left }}</span></td>
                  <td>{{ item.re_order_point }}</td>
                  <td>
                    <span class="badge badge-danger">
                      {{ Math.max(0, Number(item.re_order_point) - Number(item.stock_left)) }}
                    </span>
                  </td>
                  <td>
                    <router-link v-if="authStore.canManageStock" :to="productStockcard(item)" class="btn btn-sm btn-secondary">Open Stockcard</router-link>
                  </td>
                </tr>
                <tr v-if="!pLowStock.items.length">
                  <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No items to display.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
          <AppPagination
            :current-page="lowStockPage"
            :total-pages="pLowStock.totalPages"
            :total-items="pLowStock.total"
            :page-size="lowStockPageSize"
            :page-size-options="pageSizeOptions"
            item-name="items"
            @update:current-page="lowStockPage = $event"
            @update:page-size="lowStockPageSize = $event"
          />
        </div>

        <!-- Out of Stock Tab Table -->
        <div v-if="activeTab === 'out-of-stock'">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title">Out of Stock Details</h2>
              <p class="panel-subtitle">Items completely depleted and requiring replenishment.</p>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" @click="activeTab = null">✕ Close</button>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Status</th>
                  <th>Action Needed</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, idx) in pOutOfStock.items" :key="idx">
                  <td><strong>{{ item.item }}</strong></td>
                  <td><span class="badge badge-danger">Unavailable</span></td>
                  <td><span style="color: var(--color-danger); font-weight: 600;">Restock immediately</span></td>
                  <td>
                    <router-link v-if="authStore.canManageStock" :to="productStockcard(item)" class="btn btn-sm btn-primary">Stock In</router-link>
                  </td>
                </tr>
                <tr v-if="!pOutOfStock.items.length">
                  <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">No items to display.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
          <AppPagination
            :current-page="outOfStockPage"
            :total-pages="pOutOfStock.totalPages"
            :total-items="pOutOfStock.total"
            :page-size="outOfStockPageSize"
            :page-size-options="pageSizeOptions"
            item-name="items"
            @update:current-page="outOfStockPage = $event"
            @update:page-size="outOfStockPageSize = $event"
          />
        </div>

        <!-- Expiring Soon Tab Table -->
        <div v-if="activeTab === 'expiring'">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title">Expiring Soon Batches</h2>
              <p class="panel-subtitle">Batches approaching expiration threshold date.</p>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" @click="activeTab = null">✕ Close</button>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Batch #</th>
                  <th>Product</th>
                  <th>Expiration Date</th>
                  <th>Days Left</th>
                  <th>Qty Remaining</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(batch, idx) in pExpiring.items" :key="idx">
                  <td>#{{ batch.batch_id }}</td>
                  <td><strong>{{ batch.item }}</strong></td>
                  <td>{{ batch.expiration_date }}</td>
                  <td>
                    <span class="badge" :class="Number(batch.days_left) <= 7 ? 'badge-danger' : 'badge-warning'">
                      {{ batch.days_left }} days
                    </span>
                  </td>
                  <td><strong>{{ batch.remaining_qty }}</strong></td>
                </tr>
                <tr v-if="!pExpiring.items.length">
                  <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No expiring items detected.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
          <AppPagination
            :current-page="expiringPage"
            :total-pages="pExpiring.totalPages"
            :total-items="pExpiring.total"
            :page-size="expiringPageSize"
            :page-size-options="pageSizeOptions"
            item-name="items"
            @update:current-page="expiringPage = $event"
            @update:page-size="expiringPageSize = $event"
          />
        </div>

        <!-- Borrowed Items Tab Table -->
        <div v-if="activeTab === 'borrowed'">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title">Borrowed Items Details</h2>
              <p class="panel-subtitle">Items currently released on loan to university departments.</p>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" @click="activeTab = null">✕ Close</button>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Office / Unit</th>
                  <th>Borrowed</th>
                  <th>Returned</th>
                  <th>Still Out</th>
                  <th>Last Movement</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(b, idx) in pBorrowed.items" :key="idx">
                  <td><strong>{{ b.item }}</strong></td>
                  <td>{{ b.office || 'Unassigned' }}</td>
                  <td>{{ b.total_borrowed }}</td>
                  <td>{{ b.total_returned }}</td>
                  <td><span class="badge badge-warning">{{ b.net_borrowed }}</span></td>
                  <td>{{ b.last_borrowed }}</td>
                </tr>
                <tr v-if="!pBorrowed.items.length">
                  <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">No active borrowed items.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
          <AppPagination
            :current-page="borrowedPage"
            :total-pages="pBorrowed.totalPages"
            :total-items="pBorrowed.total"
            :page-size="borrowedPageSize"
            :page-size-options="pageSizeOptions"
            item-name="items"
            @update:current-page="borrowedPage = $event"
            @update:page-size="borrowedPageSize = $event"
          />
        </div>
      </section>
      </Teleport>

      <!-- Recent Transactions Feed with Customizable Pagination (Avoids Long Scrolling) -->
      <section class="panel">
        <div class="panel-header">
          <div class="panel-title-group">
            <h2 class="panel-title">Recent Inventory Activity</h2>
            <p class="panel-subtitle">Latest recorded receipts, issues, and stock adjustments.</p>
          </div>
          <router-link to="/transactions" class="btn btn-sm btn-secondary">
            View All Transactions →
          </router-link>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Date / Time</th>
                <th>Product</th>
                <th>Action Type</th>
                <th>Quantity</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(tx, idx) in pRecentTx.items"
                :key="idx"
                class="activity-row"
                tabindex="0"
                role="link"
                :title="`Open ${tx.item}`"
                @click="openActivity(tx)"
                @keydown.enter="openActivity(tx)"
              >
                <td style="font-family: var(--font-mono); font-size: 0.82rem; color: var(--text-muted);">
                  {{ tx.date }}
                </td>
                <td>
                  <strong>{{ tx.item }}</strong>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="typeBadge(tx.transaction_type)"
                    style="text-transform: uppercase;"
                  >
                    {{ typeLabel(tx.transaction_type) }}
                  </span>
                </td>
                <td style="font-weight: 700;">
                  <span :style="{ color: isStockIn(tx.transaction_type) ? 'var(--color-success)' : 'var(--text-main)' }">
                    {{ signedQty(tx.transaction_type, tx.transaction_qty) }}
                  </span>
                </td>
              </tr>
              <tr v-if="!pRecentTx.items.length">
                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                  No recent transactions recorded yet.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Customizable Pagination for Recent Activity (< 1 2 3 ... x > Page items : Go to : ) -->
        <AppPagination
          :current-page="recentTxPage"
          :total-pages="pRecentTx.totalPages"
          :total-items="pRecentTx.total"
          :page-size="recentTxPageSize"
          :page-size-options="pageSizeOptions"
          item-name="activities"
          @update:current-page="recentTxPage = $event"
          @update:page-size="recentTxPageSize = $event"
        />
      </section>
    </div>
  </div>
</template>

<style scoped>
.dash-details {
  animation: slideUp 0.25s ease;
  margin-bottom: 24px;
}

.dash-sheet-backdrop,
.dash-sheet-handle {
  display: none;
}

/* ── Phones ── */
@media (max-width: 640px) {
  /* Compact two-column cards; Total Products across the top */
  .dashboard-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 10px !important;
  }

  .dashboard-summary-grid > .summary-card:first-child {
    grid-column: 1 / -1 !important;
  }

  .dashboard-summary-grid .summary-card {
    padding: 14px 14px 12px;
    border-radius: 16px;
  }

  .dashboard-summary-grid .summary-card .card-label {
    font-size: 10.5px;
    letter-spacing: 0.06em;
  }

  .dashboard-summary-grid .summary-card strong.card-value {
    margin: 8px 0 4px;
    font-size: 1.75rem;
  }

  .dashboard-summary-grid .summary-card .card-hint {
    font-size: 0.72rem;
    line-height: 1.3;
  }

  /* The sheet opening is the "active" signal on phones */
  .dashboard-summary-grid .card-active-pill {
    display: none;
  }

  /* Details as a bottom sheet */
  .dash-sheet-backdrop {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 1190;
    background: rgba(10, 20, 10, 0.5);
    backdrop-filter: blur(2px);
    animation: sheet-fade 0.2s ease;
  }

  .dash-details.is-sheet {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1200;
    max-height: 85dvh;
    margin: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    border-radius: 20px 20px 0 0;
    padding-bottom: env(safe-area-inset-bottom);
    box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.25);
    animation: sheet-up 0.28s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .dash-sheet-handle {
    display: block;
    position: sticky;
    top: 0;
    z-index: 2;
    height: 18px;
    background: var(--bg-surface);
  }

  .dash-sheet-handle::after {
    content: '';
    position: absolute;
    top: 7px;
    left: 50%;
    width: 40px;
    height: 4px;
    margin-left: -20px;
    border-radius: 4px;
    background: var(--border-hover, var(--border-subtle));
  }

  /* Title and Close stay visible while the table scrolls */
  .dash-details.is-sheet :deep(.panel-header) {
    position: sticky;
    top: 18px;
    z-index: 1;
    background: var(--bg-surface);
    padding-top: 6px;
  }
}

@keyframes sheet-up {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}

@keyframes sheet-fade {
  from { opacity: 0; }
  to { opacity: 1; }
}

.summary-card-header {
  gap: 8px;
  min-width: 0;
}

/* The label gives way (…) so the Active pill always fits on one line */
.summary-card-header .card-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-active-pill {
  flex-shrink: 0;
  white-space: nowrap;
  gap: 5px !important;
  line-height: 1.5;
}

.pill-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  animation: pill-pulse 1.8s ease-in-out infinite;
}

@keyframes pill-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.activity-row {
  cursor: pointer;
  transition: background var(--transition-fast);
}

.activity-row:hover,
.activity-row:focus-visible {
  background: var(--bg-subtle);
  outline: none;
}

.activity-row:hover strong,
.activity-row:focus-visible strong {
  color: var(--color-primary);
  text-decoration: underline;
}
</style>
