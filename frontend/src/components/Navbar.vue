<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { useThemeStore } from '../stores/themeStore'
import { useNotificationStore } from '../stores/notificationStore'
import { triggerAutoReload, isReloading } from '../composables/useAutoReload'
import { backupsApi } from '../api/backups'

const props = defineProps({
  alertCount: {
    type: Number,
    default: 0,
  },
})

const authStore = useAuthStore()
const themeStore = useThemeStore()
const notificationStore = useNotificationStore()
const router = useRouter()
const route = useRoute()

// Ask once per browser session whether an automatic backup is due (the server decides)
function runAutoBackupOnce() {
  if (authStore.levelId < 2 || authStore.levelId > 3) return
  try {
    const key = `bsu_auto_backup_checked_${authStore.user?.id}`
    if (sessionStorage.getItem(key)) return
    sessionStorage.setItem(key, '1')
  } catch {
    // storage unavailable — still run the check
  }
  backupsApi.auto().catch(() => {})
}

const isNotifDropdownOpen = ref(false)
const dropdownRef = ref(null)
const openMenu = ref('')
const menuRef = ref(null)
// Phones / tablets: the links, user and logout move into a panel behind the ☰ button
const mobileOpen = ref(false)
const headerRef = ref(null)

// Navigation per access level
const menu = computed(() => {
  const level = authStore.levelId
  if (isAdminAccount.value) {
    return [
      { label: 'User Management', to: '/admin' },
      { label: 'Notifications', to: '/notifications', badge: notificationStore.unreadCount },
    ]
  }
  if (level === 1) {
    return [
      { label: 'Dashboard', to: '/' },
      { label: 'Items', to: '/products' },
      {
        label: 'Stock Out',
        children: [
          { label: 'Request Stock Out', to: '/stockout' },
          { label: 'My List', to: '/stockout/list' },
        ],
      },
      { label: 'Transactions', to: '/transactions' },
    ]
  }
  return [
    { label: 'Dashboard', to: '/' },
    {
      label: 'Stock',
      children: [
        { label: 'Stockcard', to: '/stockcard' },
        { label: 'Export Stockcard', to: '/export/stockcard' },
      ],
    },
    {
      label: 'Products',
      children: [
        { label: 'Product List', to: '/products' },
        { label: 'Finished Products', to: '/products/barcodes' },
        { label: 'Batch Barcodes', to: '/reports/batches' },
        { label: 'Summary Report', to: '/reports/summary' },
        { label: 'Export Summary', to: '/export/summary' },
      ],
    },
    { label: 'Requests', to: '/stockout/pending', badge: notificationStore.counts.stockoutRequests || 0 },
    { label: 'Transactions', to: '/transactions' },
    { label: 'Settings', to: '/settings', badge: notificationStore.counts.pendingUsers || 0 },
  ]
})

function isGroupActive(item) {
  return item.children?.some((c) => route.path === c.to || route.path.startsWith(`${c.to}/`))
}

function toggleMenu(label) {
  openMenu.value = openMenu.value === label ? '' : label
}

function toggleMobileMenu() {
  mobileOpen.value = !mobileOpen.value
  closeDropdown()
}

// Close menus after navigating
watch(() => route.fullPath, () => {
  openMenu.value = ''
  mobileOpen.value = false
})

function handleManualReload() {
  triggerAutoReload('manual')
}

const isAdminAccount = computed(() => {
  return authStore.levelId >= 4 || authStore.role?.toLowerCase().includes('technical')
})

const themeIcon = computed(() => {
  if (themeStore.current === 'bsu') return '🏛️'
  if (themeStore.current === 'dark') return '🌙'
  return '☀️'
})

const themeLabel = computed(() => {
  if (themeStore.current === 'bsu') return 'BSU Theme'
  if (themeStore.current === 'dark') return 'Dark Mode'
  return 'Light Mode'
})

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}

function toggleNotifDropdown() {
  isNotifDropdownOpen.value = !isNotifDropdownOpen.value
}

function closeDropdown() {
  isNotifDropdownOpen.value = false
}

function handleNotifClick(n) {
  notificationStore.markAsRead(n.id)
  closeDropdown()
  if (n.action_url) {
    router.push(n.action_url)
  }
}

function handleClickOutside(event) {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    closeDropdown()
  }
  if (menuRef.value && !menuRef.value.contains(event.target)) {
    openMenu.value = ''
  }
  if (headerRef.value && !headerRef.value.contains(event.target)) {
    mobileOpen.value = false
  }
}

onMounted(() => {
  notificationStore.fetchNotifications()
  runAutoBackupOnce()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <header ref="headerRef" class="navbar">
    <div class="navbar-inner">
      <router-link to="/" class="brand-section">
        <div class="brand-logo-badge">
          <span>BSU</span>
        </div>
        <div class="brand-title-group">
          <span class="brand-title">BSU INVENTORY</span>
          <span class="brand-subtitle">
            {{ isAdminAccount ? 'System Administration' : (authStore.officeName || 'Food Processing Center') }}
          </span>
        </div>
      </router-link>

      <nav ref="menuRef" class="desktop-nav">
        <ul class="nav-links">
          <li v-for="item in menu" :key="item.label" class="nav-item">
            <router-link v-if="item.to" :to="item.to" class="nav-link">
              <span>{{ item.label }}</span>
              <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
            </router-link>
            <template v-else>
              <button
                type="button"
                class="nav-link nav-group-btn"
                :class="{ 'router-link-active': isGroupActive(item) }"
                :aria-expanded="openMenu === item.label"
                @click.stop="toggleMenu(item.label)"
              >
                <span>{{ item.label }}</span><span class="nav-caret">▾</span>
              </button>
              <ul v-if="openMenu === item.label" class="nav-submenu" @click.stop>
                <li v-for="child in item.children" :key="child.to">
                  <router-link :to="child.to" class="nav-submenu-link">{{ child.label }}</router-link>
                </li>
              </ul>
            </template>
          </li>
        </ul>
      </nav>

      <div class="nav-actions">
        <!-- Notification Bell Dropdown Button -->
        <div ref="dropdownRef" class="notif-wrapper">
          <button
            type="button"
            class="theme-btn notif-bell-btn"
            @click.stop="toggleNotifDropdown"
            :title="`Notifications (${notificationStore.unreadCount} unread)`"
          >
            <span style="font-size: 1.15rem;">🔔</span>
            <span
              v-if="notificationStore.unreadCount > 0"
              class="bell-unread-badge"
            >
              {{ notificationStore.unreadCount > 9 ? '9+' : notificationStore.unreadCount }}
            </span>
          </button>

          <!-- Flyout Notification Dropdown -->
          <div v-if="isNotifDropdownOpen" class="notif-flyout" @click.stop>
            <div class="flyout-header">
              <div class="flyout-title-wrap">
                <strong>Notifications</strong>
                <span v-if="notificationStore.unreadCount > 0" class="flyout-count-badge">
                  {{ notificationStore.unreadCount }} new
                </span>
              </div>
              <button
                v-if="notificationStore.unreadCount > 0"
                type="button"
                class="flyout-mark-read"
                @click="notificationStore.markAllAsRead()"
              >
                Mark all read
              </button>
            </div>

            <div class="flyout-body">
              <div
                v-if="notificationStore.activeNotifications.length === 0"
                class="flyout-empty"
              >
                <span>🎉</span>
                <p>No active alerts right now.</p>
              </div>

              <div
                v-for="n in notificationStore.activeNotifications.slice(0, 5)"
                :key="n.id"
                class="flyout-item"
                :class="{ 'is-unread': !n.isRead }"
                @click="handleNotifClick(n)"
              >
                <div class="flyout-item-icon">
                  <span v-if="n.type === 'out_of_stock'">🚨</span>
                  <span v-else-if="n.type === 'low_stock'">⚠️</span>
                  <span v-else-if="n.type === 'expiring'">⏳</span>
                  <span v-else-if="n.type === 'borrow'">🔄</span>
                  <span v-else-if="n.type === 'user_registration'">👤</span>
                  <span v-else-if="n.type === 'stockout_request'">📋</span>
                  <span v-else>🔔</span>
                </div>

                <div class="flyout-item-content">
                  <div class="flyout-item-top">
                    <span class="flyout-item-title">{{ n.title }}</span>
                    <span v-if="!n.isRead" class="flyout-dot"></span>
                  </div>
                  <p class="flyout-item-msg">{{ n.message }}</p>
                  <span class="flyout-item-time">{{ n.created_at ? n.created_at.slice(0, 16) : 'Now' }}</span>
                </div>
              </div>
            </div>

            <div class="flyout-footer">
              <router-link
                to="/notifications"
                class="flyout-view-all"
                @click="closeDropdown"
              >
                <span>View all notifications</span>
                <span>→</span>
              </router-link>
            </div>
          </div>
        </div>

        <!-- Theme Toggle Button -->
        <button
          type="button"
          class="theme-btn desktop-only"
          @click="themeStore.cycleTheme()"
          :title="`Current theme: ${themeLabel}. Click to switch.`"
        >
          <span style="font-size: 1.1rem">{{ themeIcon }}</span>
        </button>

        <!-- User Profile Pill -->
        <router-link to="/change-password" class="user-chip desktop-only" title="Change password">
          <div class="user-avatar">
            {{ (authStore.userName || 'A').charAt(0).toUpperCase() }}
          </div>
          <div class="user-info">
            <span class="user-name">{{ authStore.userName }}</span>
            <span class="user-role">{{ authStore.role }}</span>
          </div>
        </router-link>

        <!-- Logout Action -->
        <button
          type="button"
          class="btn btn-sm btn-secondary desktop-only"
          @click="handleLogout"
          style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.25); color: #fff;"
        >
          Logout
        </button>

        <button
          type="button"
          class="theme-btn nav-toggle"
          :aria-expanded="mobileOpen"
          aria-controls="mobile-menu"
          :title="mobileOpen ? 'Close menu' : 'Open menu'"
          @click.stop="toggleMobileMenu"
        >
          <span style="font-size: 1.2rem; line-height: 1;">{{ mobileOpen ? '✕' : '☰' }}</span>
        </button>
      </div>
    </div>

    <!-- Phone / tablet menu panel -->
    <div v-if="mobileOpen" id="mobile-menu" class="mobile-menu">
      <router-link to="/change-password" class="mobile-user">
        <div class="user-avatar">{{ (authStore.userName || 'A').charAt(0).toUpperCase() }}</div>
        <div class="mobile-user-info">
          <strong>{{ authStore.userName }}</strong>
          <span>{{ authStore.role }} · Change password</span>
        </div>
      </router-link>

      <ul class="mobile-links">
        <template v-for="item in menu" :key="item.label">
          <li v-if="item.to">
            <router-link :to="item.to" class="mobile-link">
              <span>{{ item.label }}</span>
              <span v-if="item.badge" class="nav-badge">{{ item.badge }}</span>
            </router-link>
          </li>
          <li v-else>
            <span class="mobile-group">{{ item.label }}</span>
            <ul class="mobile-sublinks">
              <li v-for="child in item.children" :key="child.to">
                <router-link :to="child.to" class="mobile-link">{{ child.label }}</router-link>
              </li>
            </ul>
          </li>
        </template>
      </ul>

      <div class="mobile-actions">
        <button type="button" class="btn btn-secondary" @click="themeStore.cycleTheme()">
          {{ themeIcon }} {{ themeLabel }}
        </button>
        <button type="button" class="btn btn-primary" @click="handleLogout">Log out</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.nav-toggle {
  display: none;
}

.mobile-menu {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  max-height: calc(100dvh - 64px);
  overflow-y: auto;
  padding: 0.75rem 1rem calc(1rem + env(safe-area-inset-bottom));
  background: var(--bg-surface);
  color: var(--text-main);
  border-bottom: 1px solid var(--border-subtle);
  box-shadow: var(--shadow-xl);
  z-index: 999;
}

.mobile-user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  margin-bottom: 0.5rem;
  border-radius: 12px;
  background: var(--bg-subtle);
  color: inherit;
  text-decoration: none;
}

.mobile-user-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.mobile-user-info span {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.mobile-links,
.mobile-sublinks {
  list-style: none;
  margin: 0;
  padding: 0;
}

.mobile-link {
  display: flex;
  justify-content: space-between;
  align-items: center;
  min-height: 46px;
  padding: 0 0.75rem;
  border-radius: 10px;
  color: var(--text-main);
  font-weight: 600;
  text-decoration: none;
}

.mobile-link.router-link-exact-active {
  background: var(--color-primary-light);
  color: var(--color-primary);
}

.mobile-group {
  display: block;
  padding: 0.85rem 0.75rem 0.25rem;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.mobile-sublinks .mobile-link {
  padding-left: 1.25rem;
  font-weight: 500;
}

.mobile-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.6rem;
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--border-subtle);
}

.mobile-actions .btn {
  justify-content: center;
}

/* Phones and tablets: compact one-row header, everything else in the panel */
@media (max-width: 1199px) {
  .navbar-inner {
    flex-wrap: nowrap;
    height: 64px;
    padding: 0 1rem;
    gap: 0.75rem;
  }

  .desktop-nav,
  .desktop-only {
    display: none !important;
  }

  .nav-toggle {
    display: flex;
  }

  .nav-actions {
    gap: 0.5rem;
  }
}


.nav-item {
  position: relative;
}

.nav-group-btn {
  background: none;
  border: 0;
  font: inherit;
  cursor: pointer;
}

.nav-caret {
  font-size: 0.7em;
  margin-left: 4px;
  opacity: 0.8;
}

.nav-submenu {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  min-width: 210px;
  margin: 0;
  padding: 6px;
  list-style: none;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 14px;
  box-shadow: var(--shadow-xl);
  z-index: 1000;
}

.nav-submenu-link {
  display: block;
  padding: 9px 12px;
  border-radius: 8px;
  color: var(--text-main);
  font-size: 0.9rem;
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
}

.nav-submenu-link:hover,
.nav-submenu-link.router-link-active {
  background: var(--bg-subtle);
  color: var(--color-primary);
}

.user-chip {
  text-decoration: none;
  color: inherit;
}

.notif-wrapper {
  position: relative;
}

.notif-bell-btn {
  position: relative;
}

.bell-unread-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9999px;
  background: #ef4444;
  color: #fff;
  font-size: 10.5px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 6px rgba(239, 68, 68, 0.45);
  animation: pulse 2s infinite;
}

.notif-flyout {
  position: absolute;
  top: calc(100% + 12px);
  right: 0;
  width: 360px;
  max-width: calc(100vw - 32px);
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 20px;
  box-shadow: var(--shadow-xl);
  z-index: 1000;
  animation: fadeIn 0.15s cubic-bezier(0.16, 1, 0.3, 1);
  overflow: hidden;
  color: var(--text-main);
}

.flyout-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 18px;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-subtle);
}

.flyout-title-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.flyout-count-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 9999px;
  background: rgba(239, 68, 68, 0.15);
  color: #ef4444;
}

.flyout-mark-read {
  background: none;
  border: none;
  font-size: 12px;
  color: var(--color-primary);
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}

.flyout-mark-read:hover {
  text-decoration: underline;
}

.flyout-body {
  max-height: 380px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.flyout-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 32px 16px;
  text-align: center;
  gap: 8px;
}

.flyout-empty span {
  font-size: 1.8rem;
}

.flyout-empty p {
  font-size: 13px;
  color: var(--text-muted);
  margin: 0;
}

.flyout-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 18px;
  border-bottom: 1px solid var(--border-subtle);
  cursor: pointer;
  transition: background var(--transition-fast);
}

.flyout-item:last-child {
  border-bottom: none;
}

.flyout-item:hover {
  background: var(--bg-subtle);
}

.flyout-item.is-unread {
  background: rgba(239, 68, 68, 0.04);
}

.flyout-item-icon {
  font-size: 1.2rem;
  line-height: 1;
  padding-top: 2px;
  flex-shrink: 0;
}

.flyout-item-content {
  flex: 1;
  min-width: 0;
}

.flyout-item-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.flyout-item-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-main);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.flyout-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #ef4444;
  flex-shrink: 0;
}

.flyout-item-msg {
  font-size: 12px;
  color: var(--text-muted);
  line-height: 1.4;
  margin: 3px 0 4px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.flyout-item-time {
  font-size: 11px;
  color: var(--text-subtle);
}

.flyout-footer {
  padding: 10px 18px;
  background: var(--bg-subtle);
  border-top: 1px solid var(--border-subtle);
  text-align: center;
}

.flyout-view-all {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--color-primary);
  text-decoration: none;
  padding: 4px;
}

.flyout-view-all:hover {
  text-decoration: underline;
}

/* Phone overrides — kept last so they win over the base rules above */
@media (max-width: 640px) {
  .brand-title {
    font-size: 1rem;
  }

  .brand-subtitle {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Notification flyout spans the screen instead of hanging off the bell */
  .notif-flyout {
    position: fixed;
    top: 72px;
    left: 0.5rem;
    right: 0.5rem;
    width: auto;
    max-width: none;
  }
}
</style>
