<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { dashboardApi } from '../api/dashboard'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById } from '../composables/useAutoReload'
import AdminDashboardView from './AdminDashboardView.vue'
import AppPagination from '../components/AppPagination.vue'

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

function toggleTab(tab) {
  activeTab.value = activeTab.value === tab ? null : tab
}

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
            <span class="card-redirect-badge">View Catalog →</span>
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
            <span v-if="activeTab === 'low-stock'" class="card-active-pill">● Active</span>
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
            <span v-if="activeTab === 'out-of-stock'" class="card-active-pill">● Active</span>
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
            <span v-if="activeTab === 'expiring'" class="card-active-pill">● Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.expiringCount }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.expiring.length">No details to show</template>
            <template v-else-if="activeTab === 'expiring'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>

        <!-- 5. Items Out Borrowed (Clickable button card with active highlight on 2nd row) -->
        <button
          type="button"
          class="summary-card summary-card-action"
          :class="{ 'is-active': activeTab === 'borrowed' }"
          :disabled="!dashboard.activeBorrows.length && !borrowedOutTotal"
          @click="toggleTab('borrowed')"
        >
          <div class="summary-card-header">
            <span class="card-label">ITEMS OUT (BORROWED)</span>
            <span v-if="activeTab === 'borrowed'" class="card-active-pill">● Active</span>
          </div>
          <strong class="card-value">{{ dashboard.summary.activeBorrowCount || borrowedOutTotal }}</strong>
          <small class="card-hint">
            <template v-if="!dashboard.activeBorrows.length && !borrowedOutTotal">No details to show</template>
            <template v-else-if="activeTab === 'borrowed'">▲ Viewing table (Click to hide)</template>
            <template v-else>Click to view table</template>
          </small>
        </button>
      </section>

      <!-- Active Content Drawer with Customizable Pagination (Avoids Long Scrolling) -->
      <section v-if="activeTab" class="panel" style="animation: slideUp 0.25s ease; margin-bottom: 24px;">
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
                    <router-link v-if="authStore.canManageStock" to="/stockcard" class="btn btn-sm btn-secondary">Open Stockcard</router-link>
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
                    <router-link v-if="authStore.canManageStock" to="/stockcard" class="btn btn-sm btn-primary">Stock In</router-link>
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
              <tr v-for="(tx, idx) in pRecentTx.items" :key="idx">
                <td style="font-family: var(--font-mono); font-size: 0.82rem; color: var(--text-muted);">
                  {{ tx.date }}
                </td>
                <td>
                  <strong>{{ tx.item }}</strong>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="tx.transaction_type === 'receipt' ? 'badge-success' : tx.transaction_type === 'issue' ? 'badge-info' : 'badge-neutral'"
                    style="text-transform: uppercase;"
                  >
                    {{ tx.transaction_type }}
                  </span>
                </td>
                <td style="font-weight: 700;">
                  <span :style="{ color: tx.transaction_type === 'receipt' ? 'var(--color-success)' : 'var(--text-main)' }">
                    {{ tx.transaction_type === 'receipt' ? '+' : '-' }}{{ tx.transaction_qty }}
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
