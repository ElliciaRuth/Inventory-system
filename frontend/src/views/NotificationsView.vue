<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '../stores/notificationStore'
import { useAutoReload } from '../composables/useAutoReload'
import AppPagination from '../components/AppPagination.vue'
import {
  Bell, AlertCircle, AlertTriangle, Clock, RefreshCw, UserCheck, ClipboardList,
  Check, Search, Trash2, PartyPopper, ArrowRight, X,
} from 'lucide-vue-next'

const router = useRouter()
const notificationStore = useNotificationStore()

// Filter and Search State
const activeCategory = ref('all') // 'all' | 'unread' | 'out_of_stock' | 'low_stock' | 'expiring' | 'borrow' | 'user_registration' | 'stockout_request'
const searchQuery = ref('')
const currentPage = ref(1)
const pageSize = ref(10)

// Refresh notifications
async function refresh() {
  await notificationStore.fetchNotifications()
}

useAutoReload(refresh)

onMounted(() => {
  refresh()
})

// Filtered notifications list
const filteredList = computed(() => {
  let list = notificationStore.activeNotifications

  // Filter by category
  if (activeCategory.value === 'unread') {
    list = list.filter((n) => !n.isRead)
  } else if (activeCategory.value !== 'all') {
    list = list.filter((n) => n.type === activeCategory.value)
  }

  // Filter by search query
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim()
    list = list.filter((n) => {
      return (
        n.title?.toLowerCase().includes(q) ||
        n.item?.toLowerCase().includes(q) ||
        n.message?.toLowerCase().includes(q) ||
        n.category?.toLowerCase().includes(q)
      )
    })
  }

  return list
})

// Pagination
const totalCount = computed(() => filteredList.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / pageSize.value)))

const paginatedList = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredList.value.slice(start, start + pageSize.value)
})

function handlePageChange(newPage) {
  currentPage.value = newPage
}

function handleLimitChange(newLimit) {
  pageSize.value = newLimit
  currentPage.value = 1
}

function setCategory(cat) {
  activeCategory.value = cat
  currentPage.value = 1
}

function handleAction(n) {
  notificationStore.markAsRead(n.id)
  if (n.action_url) {
    router.push(n.action_url)
  }
}

// Same icons as the navbar notification flyout
function getIcon(type) {
  if (type === 'out_of_stock') return AlertCircle
  if (type === 'low_stock') return AlertTriangle
  if (type === 'expiring') return Clock
  if (type === 'borrow') return RefreshCw
  if (type === 'user_registration') return UserCheck
  if (type === 'stockout_request') return ClipboardList
  return Bell
}
</script>

<template>
  <div class="notifications-page">
    <!-- Header Hero -->
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">
          <Bell :size="14" /> System Intelligence & Audit
        </p>
        <h1 class="hero-title">Notifications & System Alerts</h1>
        <p class="hero-subtitle">
          Real-time inventory alerts, critical out-of-stock notices, expiry warnings, borrow logs, and user authorizations.
        </p>
      </div>

      <div class="hero-actions-group">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="notificationStore.unreadCount === 0"
          @click="notificationStore.markAllAsRead()"
        >
          <Check :size="15" /> Mark All Read
        </button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="notificationStore.loading"
          @click="refresh"
        >
          <RefreshCw :size="15" :class="{ 'spin-icon': notificationStore.loading }" /> Refresh
        </button>
      </div>
    </div>

    <!-- Summary Statistics Grid -->
    <div class="stats-grid">
      <!-- Total Alerts -->
      <div
        class="stat-card"
        :class="{ 'is-active': activeCategory === 'all' }"
        @click="setCategory('all')"
      >
        <div class="stat-card-header">
          <span class="stat-card-title">Active Alerts</span>
          <span class="stat-card-icon" style="background: var(--color-primary-light); color: var(--color-primary);"><Bell :size="18" /></span>
        </div>
        <strong class="stat-card-value">{{ notificationStore.activeNotifications.length }}</strong>
        <span class="stat-card-hint">
          {{ notificationStore.unreadCount }} unread
        </span>
      </div>

      <!-- Out of Stock -->
      <div
        class="stat-card variant-danger"
        :class="{ 'is-active': activeCategory === 'out_of_stock' }"
        @click="setCategory('out_of_stock')"
      >
        <div class="stat-card-header">
          <span class="stat-card-title">Out of Stock</span>
          <span class="stat-card-icon"><AlertCircle :size="18" /></span>
        </div>
        <strong class="stat-card-value">{{ notificationStore.counts.outOfStock }}</strong>
        <span class="stat-card-hint">Zero balance items</span>
      </div>

      <!-- Low Stock -->
      <div
        class="stat-card variant-warning"
        :class="{ 'is-active': activeCategory === 'low_stock' }"
        @click="setCategory('low_stock')"
      >
        <div class="stat-card-header">
          <span class="stat-card-title">Low Stock</span>
          <span class="stat-card-icon"><AlertTriangle :size="18" /></span>
        </div>
        <strong class="stat-card-value">{{ notificationStore.counts.lowStock }}</strong>
        <span class="stat-card-hint">Below reorder point</span>
      </div>

      <!-- Expiring Soon -->
      <div
        class="stat-card variant-info"
        :class="{ 'is-active': activeCategory === 'expiring' }"
        @click="setCategory('expiring')"
      >
        <div class="stat-card-header">
          <span class="stat-card-title">Expiring Soon</span>
          <span class="stat-card-icon"><Clock :size="18" /></span>
        </div>
        <strong class="stat-card-value">{{ notificationStore.counts.expiring }}</strong>
        <span class="stat-card-hint">Within threshold days</span>
      </div>
    </div>

    <!-- Main Content Panel -->
    <div class="panel">
      <!-- Toolbar & Category Filter Pills -->
      <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 16px;">
        <div class="notif-toolbar-top">
          <div class="notif-search-box">
            <Search :size="15" class="search-icon" />
            <input
              v-model="searchQuery"
              type="text"
              class="form-input notif-search-input"
              placeholder="Search notifications by keyword, item name, or category..."
            />
            <button
              v-if="searchQuery"
              type="button"
              class="clear-search-btn"
              @click="searchQuery = ''"
            >
              <X :size="14" />
            </button>
          </div>

          <div class="notif-actions-secondary">
            <button
              type="button"
              class="btn btn-secondary btn-sm"
              :disabled="notificationStore.activeNotifications.length === 0"
              @click="notificationStore.clearAll()"
            >
              <Trash2 :size="14" /> Clear All
            </button>
          </div>
        </div>

        <!-- Filter Category Tabs -->
        <div class="notif-pill-row">
          <button
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'all' }"
            @click="setCategory('all')"
          >
            <span>All</span>
            <span class="pill-badge">{{ notificationStore.activeNotifications.length }}</span>
          </button>

          <button
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'unread' }"
            @click="setCategory('unread')"
          >
            <span>Unread</span>
            <span class="pill-badge pill-badge-danger" v-if="notificationStore.unreadCount > 0">
              {{ notificationStore.unreadCount }}
            </span>
          </button>

          <button
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'out_of_stock' }"
            @click="setCategory('out_of_stock')"
          >
            <span><AlertCircle :size="14" /> Out of Stock</span>
            <span class="pill-badge">{{ notificationStore.counts.outOfStock }}</span>
          </button>

          <button
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'low_stock' }"
            @click="setCategory('low_stock')"
          >
            <span><AlertTriangle :size="14" /> Low Stock</span>
            <span class="pill-badge">{{ notificationStore.counts.lowStock }}</span>
          </button>

          <button
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'expiring' }"
            @click="setCategory('expiring')"
          >
            <span><Clock :size="14" /> Expiring</span>
            <span class="pill-badge">{{ notificationStore.counts.expiring }}</span>
          </button>

          <button
            v-if="notificationStore.counts.borrows > 0"
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'borrow' }"
            @click="setCategory('borrow')"
          >
            <span><RefreshCw :size="14" /> Borrows</span>
            <span class="pill-badge">{{ notificationStore.counts.borrows }}</span>
          </button>

          <button
            v-if="notificationStore.counts.pendingUsers > 0"
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'user_registration' }"
            @click="setCategory('user_registration')"
          >
            <span><UserCheck :size="14" /> Pending Users</span>
            <span class="pill-badge">{{ notificationStore.counts.pendingUsers }}</span>
          </button>

          <button
            v-if="notificationStore.counts.stockoutRequests > 0"
            type="button"
            class="notif-pill"
            :class="{ 'is-selected': activeCategory === 'stockout_request' }"
            @click="setCategory('stockout_request')"
          >
            <span><ClipboardList :size="14" /> Stock-Out Requests</span>
            <span class="pill-badge">{{ notificationStore.counts.stockoutRequests }}</span>
          </button>
        </div>
      </div>

      <!-- Notifications List View -->
      <div class="notif-list-container">
        <!-- Empty State -->
        <div v-if="paginatedList.length === 0" class="notif-empty-state">
          <div class="empty-icon-wrap">
            <PartyPopper :size="32" />
          </div>
          <h3 class="empty-title">All Caught Up!</h3>
          <p class="empty-desc">
            {{ searchQuery ? 'No alerts matched your search query.' : 'There are no active notifications in this category.' }}
          </p>
          <button
            v-if="activeCategory !== 'all' || searchQuery"
            type="button"
            class="btn btn-secondary btn-sm"
            @click="activeCategory = 'all'; searchQuery = ''"
          >
            Reset Filters
          </button>
        </div>

        <!-- Notification Items -->
        <div v-else class="notif-items-wrapper">
          <div
            v-for="n in paginatedList"
            :key="n.id"
            class="notif-card"
            :class="[
              `severity-${n.severity}`,
              { 'is-read': n.isRead, 'is-unread': !n.isRead }
            ]"
          >
            <!-- Severity Left Accent Indicator -->
            <div class="notif-accent-bar"></div>

            <div class="notif-card-inner">
              <!-- Icon -->
              <div class="notif-icon-badge">
                <component :is="getIcon(n.type)" :size="20" />
              </div>

              <!-- Content Area -->
              <div class="notif-content-area">
                <div class="notif-header-line">
                  <div class="notif-meta-tags">
                    <span class="notif-category-tag">{{ n.category }}</span>
                    <span
                      class="badge"
                      :class="{
                        'badge-danger': n.severity === 'danger',
                        'badge-warning': n.severity === 'warning',
                        'badge-info': n.severity === 'info' || n.severity === 'caution',
                      }"
                    >
                      {{ n.badge }}
                    </span>
                  </div>

                  <div class="notif-time-status">
                    <span v-if="!n.isRead" class="unread-dot" title="Unread"></span>
                    <span class="notif-time">{{ n.created_at ? n.created_at.slice(0, 16) : 'Now' }}</span>
                  </div>
                </div>

                <h4 class="notif-item-title">{{ n.title }}</h4>
                <p class="notif-item-message">{{ n.message }}</p>

                <!-- Actions Line -->
                <div class="notif-card-actions">
                  <button
                    v-if="n.action_url"
                    type="button"
                    class="btn btn-sm btn-primary"
                    @click="handleAction(n)"
                  >
                    <span>{{ n.action_label || 'View Details' }}</span>
                    <ArrowRight :size="14" />
                  </button>

                  <button
                    v-if="!n.isRead"
                    type="button"
                    class="btn btn-sm btn-secondary"
                    @click="notificationStore.markAsRead(n.id)"
                  >
                    Mark as Read
                  </button>

                  <button
                    type="button"
                    class="btn btn-sm btn-secondary notif-dismiss-btn"
                    title="Dismiss alert"
                    @click="notificationStore.dismiss(n.id)"
                  >
                    <X :size="14" /> Dismiss
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <AppPagination
        v-if="totalCount > 0"
        :current-page="currentPage"
        :total-pages="totalPages"
        :page-size="pageSize"
        :total-items="totalCount"
        @page-change="handlePageChange"
        @limit-change="handleLimitChange"
      />
    </div>
  </div>
</template>

<style scoped>
.notifications-page {
  animation: fadeIn 0.25s ease-in-out;
}

.hero-actions-group {
  display: flex;
  align-items: center;
  gap: 12px;
}

.spin-icon {
  display: inline-block;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  100% {
    transform: rotate(360deg);
  }
}

/* ── Toolbar & Filter Pills ─────────────────────────────── */
.notif-toolbar-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  width: 100%;
}

.notif-search-box {
  display: flex;
  align-items: center;
  position: relative;
  flex: 1;
  max-width: 600px;
}

.search-icon {
  position: absolute;
  left: 14px;
  color: var(--text-muted);
  font-size: 14px;
  pointer-events: none;
}

.notif-search-input {
  padding-left: 40px;
  padding-right: 36px;
  height: 42px;
  border-radius: 12px;
}

.clear-search-btn {
  position: absolute;
  right: 12px;
  background: none;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 14px;
  padding: 4px;
}

.notif-pill-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  width: 100%;
}

.notif-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 9999px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.notif-pill:hover {
  background: var(--bg-muted);
  color: var(--text-main);
  border-color: var(--border-hover);
}

.notif-pill.is-selected {
  background: var(--color-primary);
  color: #fff;
  border-color: var(--color-primary);
  box-shadow: 0 2px 8px var(--color-primary-glow);
}

.pill-badge {
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 9999px;
  background: rgba(0, 0, 0, 0.08);
  font-weight: 700;
}

.notif-pill.is-selected .pill-badge {
  background: rgba(255, 255, 255, 0.25);
  color: #fff;
}

.pill-badge-danger {
  background: #ef4444;
  color: #fff;
}

/* ── Notifications List ─────────────────────────────────── */
.notif-list-container {
  padding: 18px 24px;
}

.notif-items-wrapper {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.notif-card {
  position: relative;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 18px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  transition: all var(--transition-fast);
}

.notif-card:hover {
  box-shadow: var(--shadow-md);
  border-color: var(--border-hover);
  transform: translateY(-1px);
}

.notif-card.is-read {
  opacity: 0.82;
  background: var(--bg-subtle);
}

.notif-card.is-read:hover {
  opacity: 1;
}

.notif-accent-bar {
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  width: 5px;
}

.severity-danger .notif-accent-bar {
  background: #ef4444;
}

.severity-warning .notif-accent-bar {
  background: #f59e0b;
}

.severity-caution .notif-accent-bar {
  background: #f97316;
}

.severity-info .notif-accent-bar {
  background: #0284c7;
}

.notif-card-inner {
  display: flex;
  gap: 18px;
  padding: 18px 20px 18px 24px;
  align-items: flex-start;
}

.notif-icon-badge {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-primary);
  flex-shrink: 0;
}

.severity-danger .notif-icon-badge {
  background: rgba(239, 68, 68, 0.12);
  color: var(--color-danger);
}

.severity-warning .notif-icon-badge {
  background: rgba(245, 158, 11, 0.12);
  color: var(--color-warning);
}

.severity-info .notif-icon-badge,
.severity-caution .notif-icon-badge {
  color: var(--color-info);
}

.notif-content-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.notif-header-line {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.notif-meta-tags {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.notif-category-tag {
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
}

.notif-time-status {
  display: flex;
  align-items: center;
  gap: 8px;
}

.unread-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.3);
}

.notif-time {
  font-size: 12px;
  color: var(--text-subtle);
}

.notif-item-title {
  font-size: 15.5px;
  font-weight: 700;
  color: var(--text-main);
  margin: 2px 0 0;
}

.notif-item-message {
  font-size: 13.5px;
  color: var(--text-muted);
  line-height: 1.5;
  margin: 0;
}

.notif-card-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
}

.notif-dismiss-btn {
  opacity: 0.75;
}

.notif-dismiss-btn:hover {
  opacity: 1;
}

/* ── Empty State ─────────────────────────────────────────── */
.notif-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 24px;
  text-align: center;
}

.empty-icon-wrap {
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-primary);
  margin-bottom: 16px;
}

.empty-title {
  font-size: 1.35rem;
  font-weight: 800;
  margin-bottom: 6px;
}

.empty-desc {
  font-size: 14px;
  color: var(--text-muted);
  max-width: 440px;
  margin-bottom: 20px;
}

@media (max-width: 768px) {
  .notif-toolbar-top {
    flex-direction: column;
    align-items: stretch;
  }
  .notif-search-box {
    max-width: 100%;
  }
  .notif-card-inner {
    flex-direction: column;
  }
}
</style>
