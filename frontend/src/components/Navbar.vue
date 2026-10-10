<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { useThemeStore } from '../stores/themeStore'
import { useNotificationStore } from '../stores/notificationStore'
import { useAutoReload } from '../composables/useAutoReload'
import { backupsApi } from '../api/backups'
import { maskEmail } from '../utils/maskEmail'
import bsuLogo from '../assets/images/bsu-logo.png'
import bakeryLogo from '../assets/images/bakery-logo.png'
import fpcLogo from '../assets/images/fpc-logo.png'

import {
  LayoutDashboard,
  Boxes,
  FileSpreadsheet,
  Package,
  ShoppingBag,
  Barcode,
  BarChart3,
  Inbox,
  Receipt,
  Settings,
  Users,
  Lock,
  LogOut,
  Bell,
  Menu,
  X,
  ChevronDown,
  Sun,
  Moon,
  Palette,
  AlertCircle,
  AlertTriangle,
  Clock,
  RefreshCw,
  UserCheck,
  ClipboardList,
  ArrowRight,
  ShieldCheck,
  Building2,
  Shield,
  User,
  History,
  CheckCircle2,
  XCircle,
  ClipboardCheck,
  Handshake,
  CheckCheck,
  CircleHelp,
} from 'lucide-vue-next'

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

// Ask once per browser session whether an automatic backup is due
function runAutoBackupOnce() {
  if (authStore.levelId < 2 || authStore.levelId > 3) return
  try {
    const key = `bsu_auto_backup_checked_${authStore.user?.id}`
    if (sessionStorage.getItem(key)) return
    sessionStorage.setItem(key, '1')
  } catch {
    // storage unavailable
  }
  backupsApi.auto().catch(() => {})
}

// Mega Menu card state
const isMegaMenuOpen = ref(false)
const megaMenuRef = ref(null)

function toggleMegaMenu() {
  isMegaMenuOpen.value = !isMegaMenuOpen.value
  if (isMegaMenuOpen.value) {
    isNotifDropdownOpen.value = false
  }
}

function closeMegaMenu() {
  isMegaMenuOpen.value = false
}

// Notification dropdown state
const isNotifDropdownOpen = ref(false)
const dropdownRef = ref(null)

// The bell has three tabs: stock-out requests (and applicants), expiring/expired batches, other stock alerts
const REQUEST_TYPES = ['stockout_request', 'stockout_decision', 'user_registration']
const EXPIRY_TYPES = ['expiring']
const bellTab = ref('requests')
const requestNotifs = computed(() => notificationStore.activeNotifications.filter((n) => REQUEST_TYPES.includes(n.type)))
// Most urgent first: already expired, then the 3-day reminders, then the rest (soonest first from the server)
const expiryRank = (n) => (n.details?.expired ? 0 : n.details?.reminder ? 1 : 2)
const expiryNotifs = computed(() =>
  notificationStore.activeNotifications
    .filter((n) => EXPIRY_TYPES.includes(n.type))
    .sort((a, b) => expiryRank(a) - expiryRank(b))
)
const alertNotifs = computed(() =>
  notificationStore.activeNotifications.filter((n) => !REQUEST_TYPES.includes(n.type) && !EXPIRY_TYPES.includes(n.type))
)
const unreadIn = (list) => list.filter((n) => !n.isRead).length

const BELL_TABS = computed(() => [
  { key: 'requests', label: 'Requests', color: '#f97316', list: requestNotifs.value, empty: 'No requests or decisions right now.' },
  { key: 'expiry', label: 'Expiry', color: '#d97706', list: expiryNotifs.value, empty: 'Nothing expiring or expired right now.' },
  { key: 'alerts', label: 'Stock Alerts', color: '#ef4444', list: alertNotifs.value, empty: 'No stock alerts right now.' },
])
const activeBellTab = computed(() => BELL_TABS.value.find((t) => t.key === bellTab.value) || BELL_TABS.value[0])
const BELL_LIMIT = 8
const bellList = computed(() => activeBellTab.value.list.slice(0, BELL_LIMIT))
const bellMore = computed(() => Math.max(0, activeBellTab.value.list.length - BELL_LIMIT))

// Stock alerts describe the stock right now: product name + one figure, no time or stock phrase.
// Requests and decisions are events: title, one line of message and when it happened.
const STATE_TYPES = ['out_of_stock', 'low_stock', 'expiring', 'borrow']
const isStateAlert = (n) => STATE_TYPES.includes(n.type)
const rowTitle = (n) => (isStateAlert(n) ? n.item || n.title : n.title)
const rowDetail = (n) => {
  if (!isStateAlert(n)) return n.message
  // Expiry alerts carry the batch and date in the message; the others say nothing new
  return n.type === 'expiring' ? n.message : ''
}

function toggleNotifDropdown() {
  isNotifDropdownOpen.value = !isNotifDropdownOpen.value
  if (isNotifDropdownOpen.value) {
    isMegaMenuOpen.value = false
    // Open on the first tab with something new (requests, then expiry, then alerts),
    // otherwise on the first tab that has anything
    const tabs = BELL_TABS.value
    const pick = tabs.find((t) => unreadIn(t.list) > 0) || tabs.find((t) => t.list.length) || tabs[0]
    bellTab.value = pick.key
  }
}

// What each notification is, as a coloured tag
function notifKind(n) {
  switch (n.type) {
    case 'stockout_request': return { label: 'New request', tone: 'request' }
    case 'stockout_decision': return n.severity === 'danger' ? { label: 'Rejected', tone: 'rejected' } : { label: 'Accepted', tone: 'accepted' }
    case 'user_registration': return { label: 'New applicant', tone: 'applicant' }
    case 'out_of_stock': return { label: 'Out of stock', tone: 'danger' }
    case 'low_stock': return { label: 'Low stock', tone: 'warning' }
    case 'expiring':
      if (n.details?.expired) return { label: 'Expired', tone: 'danger' }
      return n.details?.reminder ? { label: 'Expiry reminder', tone: 'danger' } : { label: 'Expiring', tone: 'caution' }
    case 'borrow': return { label: 'Borrowed', tone: 'info' }
    default: return { label: 'Alert', tone: 'info' }
  }
}

function timeAgo(datetime) {
  if (!datetime) return 'Now'
  const t = new Date(String(datetime).replace(' ', 'T')).getTime()
  if (!t) return String(datetime).slice(0, 16)
  const mins = Math.round((Date.now() - t) / 60000)
  if (mins < 1) return 'Just now'
  if (mins < 60) return `${mins} min ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours} hr${hours === 1 ? '' : 's'} ago`
  const days = Math.round(hours / 24)
  return days < 7 ? `${days} day${days === 1 ? '' : 's'} ago` : String(datetime).slice(0, 10)
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

function getNotifIcon(type, severity = '', details = null) {
  // Outcome of the user's own stock-out request
  if (type === 'stockout_decision') return severity === 'danger' ? XCircle : CheckCircle2
  if (type === 'out_of_stock') return AlertCircle
  if (type === 'low_stock') return AlertTriangle
  if (type === 'expiring') return details?.expired ? AlertCircle : Clock
  if (type === 'borrow') return RefreshCw
  if (type === 'user_registration') return UserCheck
  if (type === 'stockout_request') return ClipboardList
  return Bell
}

// Auto close on route change
watch(() => route.fullPath, () => {
  closeMegaMenu()
  closeDropdown()
})

const isAdminAccount = computed(() => {
  return authStore.levelId >= 4 || authStore.role?.toLowerCase().includes('technical')
})

const userOfficeId = computed(() => {
  return Number(authStore.user?.user_office_id || 0)
})

const userOfficeName = computed(() => {
  return String(authStore.user?.office_name || authStore.officeName || '').trim().toLowerCase()
})

const userRole = computed(() => {
  return String(authStore.user?.role || authStore.role || '').trim().toLowerCase()
})

// Brand context
const isBakeryAccount = computed(() => {
  return userOfficeId.value === 1 || 
         userOfficeName.value.includes('bakery') || 
         userRole.value.includes('bakery')
})

const isFpcAccount = computed(() => {
  return userOfficeId.value === 2 || 
         userOfficeName.value.includes('fpc') || 
         userOfficeName.value.includes('food processing') || 
         userRole.value.includes('fpc')
})

const showBsuLogo = computed(() => true)

const showBakeryLogo = computed(() => {
  if (isBakeryAccount.value) return true
  if (isFpcAccount.value) return false
  if (isAdminAccount.value) return true
  return false
})

const showFpcLogo = computed(() => {
  if (isFpcAccount.value) return true
  if (isBakeryAccount.value) return false
  if (isAdminAccount.value) return true
  return false
})

const brandSubtitle = computed(() => {
  if (isAdminAccount.value) {
    if (isBakeryAccount.value) return 'Bakery · Administration'
    if (isFpcAccount.value) return 'FPC · Administration'
    return 'System Administration'
  }
  if (isBakeryAccount.value) return 'Bakery Project'
  if (isFpcAccount.value) return 'Food Processing Center'
  return authStore.officeName || 'BSU Inventory'
})

// Mega Menu: each column is a stack of titled groups, kept roughly the same height.
// Every group has one colour, shared by all of its icons
const menuColumns = computed(() => {
  const level = authStore.levelId

  // Admin access
  if (isAdminAccount.value) {
    return [
      [{ title: 'OVERVIEW', color: '#3b82f6', items: [{ label: 'Dashboard', to: '/', icon: LayoutDashboard }] }],
      [{ title: 'USER MANAGEMENT', color: '#8b5cf6', items: [{ label: 'User Management', to: '/admin', icon: Users }] }],
      [{ title: 'SYSTEM SETTINGS', color: '#64748b', items: [{ label: 'Settings', to: '/settings', icon: Settings, badge: notificationStore.counts.pendingUsers || 0 }] }],
    ]
  }

  // Level 1: Staff
  if (level === 1) {
    return [
      [
        {
          title: 'OVERVIEW',
          color: '#3b82f6',
          items: [
            { label: 'Dashboard', to: '/', icon: LayoutDashboard },
            { label: 'Transactions', to: '/transactions', icon: Receipt },
          ],
        },
      ],
      [
        {
          title: 'STOCK OUT REQUESTS',
          color: '#f97316',
          items: [
            { label: 'Request Stock Out', to: '/stockout', icon: Inbox },
            { label: 'My Requests List', to: '/stockout/list', icon: ClipboardList },
            { label: 'Request History', to: '/stockout/history', icon: History },
          ],
        },
      ],
      [
        {
          title: 'ITEMS',
          color: '#f59e0b',
          items: [
            { label: 'Item Catalog', to: '/products', icon: Package },
          ],
        },
      ],
    ]
  }

  // Levels 2 & 3: Custodian & Manager share the same menu, so managers can
  // accept/reject stock-out requests exactly like custodians.
  return [
    [
      {
        title: 'OVERVIEW',
        color: '#3b82f6',
        items: [
          { label: 'Dashboard', to: '/', icon: LayoutDashboard },
          { label: 'Transactions', to: '/transactions', icon: Receipt },
        ],
      },
      {
        title: 'STOCK OUT REQUESTS',
        color: '#f97316',
        items: [
          {
            label: 'Pending Requests',
            to: '/stockout/pending',
            icon: Inbox,
            badge: notificationStore.counts.stockoutRequests || 0,
          },
          { label: 'Request History', to: '/stockout/history', icon: History },
        ],
      },
    ],
    [
      {
        title: 'INVENTORY & STOCK',
        color: '#14b8a6',
        items: [
          { label: 'Stockcard Ledger', to: '/stockcard', icon: Boxes },
          { label: 'Export Stockcard', to: '/export/stockcard', icon: FileSpreadsheet },
          { label: 'Physical Count', to: '/stock/count', icon: ClipboardCheck },
          { label: 'Borrowed Items', to: '/stock/borrows', icon: Handshake },
        ],
      },
      {
        title: 'REPORTS',
        color: '#8b5cf6',
        items: [
          { label: 'Summary Report', to: '/reports/summary', icon: BarChart3 },
          { label: 'Export Summary', to: '/export/summary', icon: FileSpreadsheet },
        ],
      },
    ],
    [
      {
        title: 'PRODUCTS & CATALOG',
        color: '#f59e0b',
        items: [
          { label: 'Product List', to: '/products', icon: Package },
          { label: 'Finished Products', to: '/products/barcodes', icon: ShoppingBag },
          { label: 'Batch Barcodes', to: '/reports/batches', icon: Barcode },
        ],
      },
      // Managers only: backups, users, entities, units, references, product types, offices
      ...(level === 3
        ? [
            {
              title: 'MANAGEMENT',
              color: '#64748b',
              items: [
                {
                  label: 'Others Management',
                  to: '/settings',
                  icon: Settings,
                  badge: notificationStore.counts.pendingUsers || 0,
                },
                { label: 'Audit Log', to: '/audit-log', icon: ShieldCheck },
              ],
            },
          ]
        : []),
    ],
  ]
})

const totalMenuBadges = computed(() => {
  if (isAdminAccount.value) return 0
  // Pending applicants are handled in Others Management, which only managers have
  return (notificationStore.counts.stockoutRequests || 0) +
         (authStore.levelId === 3 ? notificationStore.counts.pendingUsers || 0 : 0)
})

const themeIconComponent = computed(() => {
  if (themeStore.current === 'bsu') return Palette
  if (themeStore.current === 'dark') return Moon
  return Sun
})

const themeLabel = computed(() => {
  if (themeStore.current === 'bsu') return 'BSU Theme'
  if (themeStore.current === 'dark') return 'Dark Mode'
  return 'Light Mode'
})

async function handleLogout() {
  closeMegaMenu()
  await authStore.logout()
  router.push('/login')
}

function handleClickOutside(event) {
  if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
    closeDropdown()
  }
  // The menu button uses @click.stop, so any click reaching here outside the card closes the menu
  if (isMegaMenuOpen.value && megaMenuRef.value && !megaMenuRef.value.contains(event.target)) {
    closeMegaMenu()
  }
}

function handleKeyDown(e) {
  if (e.key === 'Escape') {
    closeMegaMenu()
    closeDropdown()
  }
}

// Keep the bell and menu badges in sync: every 15s, on tab focus, on page change, when the
// bell is opened, and whenever a view calls triggerAutoReload (accept/reject, stock movement…)
function refreshNotifications() {
  if (isAdminAccount.value) return
  return notificationStore.fetchNotifications()
}

useAutoReload(refreshNotifications, { intervalMs: 15000 })
watch(() => route.path, refreshNotifications)
watch(isNotifDropdownOpen, (open) => open && refreshNotifications())

onMounted(() => {
  refreshNotifications()
  runAutoBackupOnce()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeyDown)
})
</script>

<template>
  <header class="navbar">
    <div class="navbar-inner">
      <!-- Left: Brand Section with Logos and Title -->
      <router-link to="/" class="brand-section">
        <div class="brand-logos-container">
          <!-- BSU Logo (Primary institution seal) -->
          <div class="nav-logo-badge nav-logo-bsu" title="Benguet State University">
            <img
              :src="bsuLogo"
              alt="Benguet State University Logo"
              class="nav-logo-img"
            />
          </div>

          <!-- Bakery Logo (Displayed for Bakery account or Global Admin) -->
          <div
            v-if="showBakeryLogo"
            class="nav-logo-badge nav-logo-bakery"
            title="BSU Bakery Project"
          >
            <img
              :src="bakeryLogo"
              alt="BSU Bakery Project Logo"
              class="nav-logo-img"
            />
          </div>

          <!-- FPC Logo (Displayed for FPC account or Global Admin) -->
          <div
            v-if="showFpcLogo"
            class="nav-logo-badge nav-logo-fpc"
            title="BSU Food Processing Center"
          >
            <img
              :src="fpcLogo"
              alt="BSU Food Processing Center Logo"
              class="nav-logo-img"
            />
          </div>
        </div>

        <div class="brand-title-group">
          <span class="brand-title">BSU INVENTORY</span>
          <span class="brand-subtitle">{{ brandSubtitle }}</span>
        </div>
      </router-link>

      <!-- Right: Unified, Symmetrical Action Controls (Desktop & Mobile) -->
      <div class="nav-actions">
        <!-- Notification Bell with Flyout Dropdown (not shown to the admin account) -->
        <div v-if="!isAdminAccount" ref="dropdownRef" class="notif-wrapper">
          <button
            type="button"
            class="theme-btn notif-bell-btn"
            :class="{ 'is-active': isNotifDropdownOpen }"
            @click.stop="toggleNotifDropdown"
            :title="`Notifications (${notificationStore.unreadCount} unread)`"
            aria-label="View notifications"
          >
            <Bell :size="18" />
            <span
              v-if="notificationStore.unreadCount > 0"
              class="bell-unread-badge"
            >
              {{ notificationStore.unreadCount > 9 ? '9+' : notificationStore.unreadCount }}
            </span>
          </button>

          <!-- Notification Flyout -->
          <div v-if="isNotifDropdownOpen" class="notif-flyout" @click.stop>
            <div class="flyout-header">
              <div class="flyout-title-wrap">
                <span class="flyout-title-icon"><Bell :size="16" /></span>
                <span class="flyout-title-text">
                  <strong>Notifications</strong>
                  <span class="flyout-count" :class="{ 'is-clear': notificationStore.unreadCount === 0 }">
                    {{ notificationStore.unreadCount > 0 ? `${notificationStore.unreadCount} unread` : 'All caught up' }}
                  </span>
                </span>
              </div>
              <button
                v-if="notificationStore.unreadCount > 0"
                type="button"
                class="flyout-mark-read"
                title="Mark all as read"
                @click="notificationStore.markAllAsRead()"
              >
                <CheckCheck :size="14" />
                <span>Mark all read</span>
              </button>
            </div>

            <!-- Requests | Expiry | Stock alerts -->
            <div class="flyout-tabs" role="tablist">
              <button
                v-for="tab in BELL_TABS"
                :key="tab.key"
                type="button"
                role="tab"
                class="flyout-tab"
                :class="{ 'is-active': bellTab === tab.key }"
                :style="{ '--tab-color': tab.color }"
                :aria-selected="bellTab === tab.key"
                @click="bellTab = tab.key"
              >
                <span>{{ tab.label }}</span>
                <span v-if="unreadIn(tab.list)" class="flyout-tab-count">{{ unreadIn(tab.list) }}</span>
              </button>
            </div>

            <div class="flyout-body">
              <div v-if="bellList.length === 0" class="flyout-empty">
                <Inbox :size="32" class="empty-icon" />
                <p>{{ activeBellTab.empty }}</p>
              </div>

              <button
                v-for="n in bellList"
                :key="n.id"
                type="button"
                class="flyout-item"
                :class="[`tone-${notifKind(n).tone}`, { 'is-unread': !n.isRead }]"
                @click="handleNotifClick(n)"
              >
                <span class="flyout-item-icon">
                  <component :is="getNotifIcon(n.type, n.severity, n.details)" :size="15" />
                </span>

                <span class="flyout-item-content">
                  <span class="flyout-item-line">
                    <span class="flyout-item-title">{{ rowTitle(n) }}</span>
                    <span v-if="isStateAlert(n) && n.badge" class="flyout-item-figure">{{ n.badge }}</span>
                    <span v-else-if="!isStateAlert(n)" class="flyout-item-time">{{ timeAgo(n.created_at) }}</span>
                  </span>
                  <span class="flyout-item-sub">
                    <span class="flyout-kind">{{ notifKind(n).label }}</span>
                    <template v-if="rowDetail(n)">
                      <span class="flyout-sep">·</span>
                      <span class="flyout-item-msg">{{ rowDetail(n) }}</span>
                    </template>
                  </span>
                </span>

                <span v-if="!n.isRead" class="flyout-dot" title="Unread" aria-label="Unread"></span>
              </button>
            </div>

            <div class="flyout-footer">
              <router-link
                to="/notifications"
                class="flyout-view-all"
                @click="closeDropdown"
              >
                <span>{{ bellMore ? `View all ${activeBellTab.list.length} ${activeBellTab.label.toLowerCase()}` : 'View all notifications' }}</span>
                <ArrowRight :size="13" />
              </router-link>
            </div>
          </div>
        </div>

        <!-- Quick Theme Toggle (Desktop only shortcut) -->
        <button
          type="button"
          class="theme-btn desktop-only"
          @click="themeStore.cycleTheme()"
          :title="`Current theme: ${themeLabel}. Click to switch.`"
          aria-label="Switch color theme"
        >
          <component :is="themeIconComponent" :size="18" />
        </button>

        <!-- Help & User Guide (phones: in the menu card) -->
        <router-link
          to="/help"
          class="theme-btn desktop-only"
          :class="{ 'is-active': route.path === '/help' }"
          title="Help & User Guide"
          aria-label="Help and user guide"
          @click="closeMegaMenu"
        >
          <CircleHelp :size="18" />
        </router-link>

        <!-- Signed-in user chip → Edit Profile (avatar only on phones) -->
        <router-link
          to="/profile"
          class="user-chip"
          :class="{ 'is-active': route.path === '/profile' }"
          title="Edit profile"
          aria-label="Edit profile"
          @click="closeMegaMenu"
        >
          <div class="user-avatar">
            {{ (authStore.userName || 'A').charAt(0).toUpperCase() }}
          </div>
          <div class="user-info desktop-only">
            <span class="user-name">{{ authStore.user?.name || authStore.userName }}</span>
            <span class="user-role">{{ authStore.role }}</span>
          </div>
        </router-link>

        <!-- Dedicated Menu Button (Triggers Velt Mega Menu Card) -->
        <button
          type="button"
          class="nav-menu-btn"
          :class="{ 'is-active': isMegaMenuOpen }"
          @click.stop="toggleMegaMenu"
          :aria-expanded="isMegaMenuOpen"
          aria-label="Toggle navigation menu"
          title="Menu"
        >
          <component :is="isMegaMenuOpen ? X : Menu" :size="18" class="menu-icon" />
          <span class="menu-btn-text desktop-only">Menu</span>
          <ChevronDown :size="13" class="menu-chevron desktop-only" :class="{ 'is-flipped': isMegaMenuOpen }" />
          <span v-if="totalMenuBadges > 0" class="menu-badge-dot"></span>
        </button>
      </div>
    </div>

    <!-- Backdrop Overlay for Outside Click (teleported: .navbar's backdrop-filter would otherwise trap position: fixed) -->
    <Teleport to="body">
      <div
        v-if="isMegaMenuOpen"
        class="mega-menu-backdrop"
        @click="closeMegaMenu"
        aria-hidden="true"
      ></div>
    </Teleport>

    <!-- VELT MEGA MENU FLOATING CARD -->
    <transition name="mega-menu">
      <div
        v-if="isMegaMenuOpen"
        ref="megaMenuRef"
        class="mega-menu-card"
        :class="{ 'is-account-only': isAdminAccount }"
        role="dialog"
        aria-label="Navigation Menu"
      >
        <div class="mega-menu-grid">
          <!-- Left/Center Categorized Columns (ASYNC / REALTIME / PLATFORM style); admin only gets the account card -->
          <div v-if="!isAdminAccount" class="mega-columns-wrap">
            <div
              v-for="(col, c) in menuColumns"
              :key="c"
              class="mega-col"
            >
              <div v-for="group in col" :key="group.title" class="mega-group">
                <span class="mega-col-title" :style="{ '--group-color': group.color }">{{ group.title }}</span>
                <ul class="mega-item-list">
                  <li v-for="item in group.items" :key="item.to" class="mega-item">
                    <router-link
                      :to="item.to"
                      class="mega-link"
                      @click="closeMegaMenu"
                    >
                      <span
                        class="mega-item-icon-box"
                        :style="{ color: group.color }"
                      >
                        <component :is="item.icon" :size="18" stroke-width="2" />
                      </span>
                      <span class="mega-link-label">{{ item.label }}</span>
                      <span v-if="item.badge" class="mega-badge-pill">
                        {{ item.badge }}
                      </span>
                    </router-link>
                  </li>
                </ul>
              </div>
            </div>
          </div>

          <!-- Right: User Account & System Status Card -->
          <div class="mega-account-panel">
            <!-- Account Profile Header -->
            <div class="account-profile-card">
              <div class="account-avatar">
                {{ (authStore.userName || 'U').charAt(0).toUpperCase() }}
              </div>
              <div class="account-details">
                <strong class="account-name">{{ authStore.user?.name || authStore.userName }}</strong>
                <span class="account-username">@{{ authStore.userName }}</span>
                <div class="account-tags">
                  <span class="badge-role">{{ authStore.role || 'Staff' }}</span>
                  <span class="badge-status">
                    <span class="status-dot"></span>
                    <span>Active</span>
                  </span>
                </div>
              </div>
            </div>

            <!-- Office & Access Meta Info -->
            <div class="account-meta-box">
              <div class="meta-row">
                <span class="meta-label">
                  <Building2 :size="13" class="meta-icon" />
                  <span>Office:</span>
                </span>
                <span class="meta-value">{{ authStore.officeName || 'BSU Inventory' }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-label">
                  <Shield :size="13" class="meta-icon" />
                  <span>Privilege:</span>
                </span>
                <span class="meta-value">Level {{ authStore.levelId }}</span>
              </div>
              <div v-if="authStore.user?.email" class="meta-row">
                <span class="meta-label">
                  <User :size="13" class="meta-icon" />
                  <span>Email:</span>
                </span>
                <span class="meta-value" :title="maskEmail(authStore.user.email)">{{ maskEmail(authStore.user.email) }}</span>
              </div>
            </div>

            <!-- Quick Theme Switcher Pill -->
            <button
              type="button"
              class="feature-theme-strip"
              @click="themeStore.cycleTheme()"
              title="Click to cycle color theme"
            >
              <div class="theme-strip-info">
                <component :is="themeIconComponent" :size="15" class="theme-strip-icon" />
                <span>Theme: <strong>{{ themeLabel }}</strong></span>
              </div>
              <span class="theme-strip-action">Switch</span>
            </button>

            <!-- Bottom Action Buttons -->
            <div class="feature-action-buttons">
              <router-link to="/help" class="feature-btn-secondary" @click="closeMegaMenu">
                <CircleHelp :size="14" />
                <span>Help &amp; User Guide</span>
              </router-link>
              <button
                type="button"
                class="feature-btn-primary"
                @click="handleLogout"
              >
                <LogOut :size="14" />
                <span>Sign Out</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </transition>
  </header>
</template>

<style scoped>
/* =========================================================
   NAVBAR CORE & BRANDING
   ========================================================= */

.navbar {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--color-nav-bg);
  color: var(--color-nav-text);
  box-shadow: var(--shadow-md);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.navbar-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  max-width: 1360px;
  height: 72px;
  margin: 0 auto;
  padding: 0 1.5rem;
  box-sizing: border-box;
}

/* Brand Section */
.brand-section {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  text-decoration: none;
  min-width: 0;
}

.brand-logos-container {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}

.nav-logo-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  /* Transparent PNG logos: no white frame, shadow follows the logo's own shape */
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
  transition: transform var(--transition-fast, 0.2s ease), filter var(--transition-fast, 0.2s ease);
  flex-shrink: 0;
}

.nav-logo-badge:hover {
  transform: scale(1.08);
  filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.4));
}

.nav-logo-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

.brand-title-group {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.brand-title {
  font-family: var(--font-display);
  font-size: 1.18rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #ffffff;
  line-height: 1.15;
  white-space: nowrap;
}

.brand-subtitle {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(255, 255, 255, 0.8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 260px;
}

/* =========================================================
   NAVBAR ACTIONS (BELL, THEME, USER, MENU)
   ========================================================= */

.nav-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}

/* Common Icon Button Style */
.theme-btn {
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md, 12px);
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.28);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s ease);
  box-sizing: border-box;
}

.theme-btn:hover {
  background: rgba(255, 255, 255, 0.28);
  transform: translateY(-1px);
}

.theme-btn.is-active {
  background: rgba(255, 255, 255, 0.35);
  border-color: #ffffff;
}

/* User Profile Chip */
.user-chip {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.35rem 0.85rem;
  border-radius: var(--radius-full, 9999px);
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.25);
  color: #ffffff;
  text-decoration: none;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s ease);
}

.user-chip:hover,
.user-chip.is-active {
  background: rgba(255, 255, 255, 0.24);
  border-color: rgba(255, 255, 255, 0.45);
}

.user-chip:hover {
  transform: translateY(-1px);
}

.user-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--color-accent, #e6d628);
  color: #12200f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.78rem;
  font-weight: 800;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.user-info {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.user-name {
  font-weight: 700;
  font-size: 0.825rem;
  white-space: nowrap;
}

.user-role {
  font-size: 0.68rem;
  color: rgba(255, 255, 255, 0.75);
  text-transform: capitalize;
}

/* Dedicated Menu Button */
.nav-menu-btn {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  height: 40px;
  padding: 0 0.95rem;
  border-radius: var(--radius-md, 12px);
  background: rgba(255, 255, 255, 0.18);
  border: 1px solid rgba(255, 255, 255, 0.32);
  color: #ffffff;
  font-weight: 700;
  font-size: 0.88rem;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s ease);
  position: relative;
  box-sizing: border-box;
}

.nav-menu-btn:hover {
  background: rgba(255, 255, 255, 0.3);
  transform: translateY(-1px);
}

.nav-menu-btn.is-active {
  background: rgba(255, 255, 255, 0.38);
  border-color: #ffffff;
}

.menu-chevron {
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

.menu-chevron.is-flipped {
  transform: rotate(180deg);
}

.menu-badge-dot {
  position: absolute;
  top: 4px;
  right: 4px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  border: 2px solid #1a5209;
  animation: pulse 2s infinite;
}

/* Notification Bell Dropdown */
.notif-wrapper {
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
  font-size: 10px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 6px rgba(239, 68, 68, 0.45);
  animation: pulse 2s infinite;
}

.notif-flyout {
  position: absolute;
  top: calc(100% + 10px);
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
  gap: 12px;
  padding: 14px 16px 12px;
  /* A light wash of the theme colour, fading out */
  background: linear-gradient(180deg, color-mix(in srgb, var(--color-primary) 9%, transparent), transparent);
}

.flyout-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.flyout-title-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border-radius: 10px;
  background: var(--color-primary-light);
  color: var(--color-primary);
}

.flyout-title-text {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.flyout-title-text strong {
  font-size: 15px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--text-main);
  letter-spacing: -0.01em;
}

.flyout-count {
  align-self: flex-start;
  padding: 1px 8px;
  border-radius: 9999px;
  background: rgba(239, 68, 68, 0.12);
  color: #dc2626;
  font-size: 11px;
  font-weight: 700;
}

.flyout-count.is-clear {
  background: var(--color-success-bg, rgba(22, 163, 74, 0.12));
  color: var(--color-success, #16a34a);
}

.flyout-mark-read {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  flex-shrink: 0;
  padding: 6px 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 9999px;
  background: var(--bg-surface);
  color: var(--color-primary);
  font: inherit;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: background var(--transition-fast), border-color var(--transition-fast);
}

.flyout-mark-read:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
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
  color: var(--text-muted);
}

.empty-icon {
  opacity: 0.6;
}

/* Tabs: Requests | Expiry | Stock Alerts, each with its own accent */
.flyout-tabs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  padding: 0 8px;
  border-bottom: 1px solid var(--border-subtle);
}

.flyout-tab {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-width: 0;
  padding: 10px 4px 11px;
  border: 0;
  background: none;
  color: var(--text-muted);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: color var(--transition-fast), background var(--transition-fast);
}

.flyout-tab:hover {
  color: var(--text-main);
  background: color-mix(in srgb, var(--tab-color) 6%, transparent);
}

.flyout-tab.is-active {
  color: var(--tab-color);
  font-weight: 700;
}

.flyout-tab.is-active::after {
  content: '';
  position: absolute;
  left: 18%;
  right: 18%;
  bottom: -1px;
  height: 3px;
  border-radius: 3px 3px 0 0;
  background: var(--tab-color);
}

.flyout-tab:focus-visible {
  outline: 2px solid var(--tab-color);
  outline-offset: -2px;
  border-radius: 6px;
}

/* Unread count in the tab's own colour, soft until the tab is open */
.flyout-tab-count {
  min-width: 19px;
  padding: 0 6px;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--tab-color) 14%, transparent);
  color: var(--tab-color);
  font-size: 11px;
  font-weight: 800;
  line-height: 18px;
  text-align: center;
}

.flyout-tab.is-active .flyout-tab-count {
  background: var(--tab-color);
  color: #fff;
}

@media (max-width: 380px) {
  .flyout-tabs {
    padding: 0 4px;
  }

  .flyout-tab {
    font-size: 12px;
    gap: 4px;
  }
}

.flyout-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 11px;
  width: 100%;
  padding: 9px 14px 9px 16px;
  border: 0;
  border-bottom: 1px solid var(--border-subtle);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: background var(--transition-fast);
}

/* One colour per kind (word, icon tile, unread dot) */
.flyout-item.tone-request   { --kind-color: #f97316; --kind-bg: rgba(249, 115, 22, 0.12); }
.flyout-item.tone-accepted  { --kind-color: #16a34a; --kind-bg: rgba(22, 163, 74, 0.12); }
.flyout-item.tone-rejected  { --kind-color: #dc2626; --kind-bg: rgba(220, 38, 38, 0.12); }
.flyout-item.tone-applicant { --kind-color: #8b5cf6; --kind-bg: rgba(139, 92, 246, 0.12); }
.flyout-item.tone-danger    { --kind-color: #ef4444; --kind-bg: rgba(239, 68, 68, 0.12); }
.flyout-item.tone-warning   { --kind-color: #d97706; --kind-bg: rgba(217, 119, 6, 0.12); }
.flyout-item.tone-caution   { --kind-color: #ea580c; --kind-bg: rgba(234, 88, 12, 0.12); }
.flyout-item.tone-info      { --kind-color: #0284c7; --kind-bg: rgba(2, 132, 199, 0.12); }

.flyout-item:last-child {
  border-bottom: none;
}

.flyout-item:hover,
.flyout-item:focus-visible {
  background: var(--bg-subtle);
  outline: none;
}

/* Unread: a thin coloured edge instead of shading the whole row */
.flyout-item.is-unread::before {
  content: '';
  position: absolute;
  left: 0;
  top: 8px;
  bottom: 8px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--kind-color, var(--color-primary));
}

.flyout-item-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: var(--kind-bg, var(--bg-subtle));
  color: var(--kind-color, var(--color-primary));
  flex-shrink: 0;
}

.flyout-item-content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.flyout-item-line {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.flyout-item-title {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--text-main);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.flyout-item:not(.is-unread) .flyout-item-title {
  font-weight: 600;
}

/* The one number that matters for a stock alert: 0 Units, 5 / 10 left, 2d left… */
.flyout-item-figure {
  flex-shrink: 0;
  padding: 1px 7px;
  border-radius: 6px;
  background: var(--kind-bg, var(--bg-subtle));
  color: var(--kind-color, var(--text-muted));
  font-size: 11px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.flyout-item-time {
  flex-shrink: 0;
  font-size: 11px;
  color: var(--text-subtle);
  white-space: nowrap;
}

.flyout-item-sub {
  display: flex;
  align-items: baseline;
  gap: 5px;
  min-width: 0;
  font-size: 11.5px;
  line-height: 1.35;
}

.flyout-kind {
  flex-shrink: 0;
  color: var(--kind-color, var(--color-primary));
  font-weight: 700;
  white-space: nowrap;
}

.flyout-sep {
  color: var(--text-subtle);
}

.flyout-item-msg {
  min-width: 0;
  color: var(--text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.flyout-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--kind-color, #ef4444);
  flex-shrink: 0;
}

.flyout-footer {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px 14px;
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

/* =========================================================
   VELT MEGA MENU DESIGN
   ========================================================= */

.mega-menu-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(10, 25, 8, 0.45);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  z-index: 99;
}

.mega-menu-card {
  position: absolute;
  top: calc(100% + 10px);
  right: 1.5rem;
  width: min(1080px, calc(100vw - 3rem));
  background: var(--bg-surface);
  color: var(--text-main);
  border: 1px solid var(--border-subtle);
  border-radius: 24px;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28), 0 4px 16px rgba(0, 0, 0, 0.12);
  z-index: 100;
  padding: 26px 28px;
  box-sizing: border-box;
  overflow: hidden;
}

/* Admin: menu holds only the account card */
@media (min-width: 769px) {
  .mega-menu-card.is-account-only {
    width: min(340px, calc(100vw - 3rem));
    padding: 14px;
  }
}

.is-account-only .mega-menu-grid {
  grid-template-columns: 1fr;
}

/* Animation */
.mega-menu-enter-active,
.mega-menu-leave-active {
  transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1), transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

.mega-menu-enter-from,
.mega-menu-leave-to {
  opacity: 0;
  transform: translateY(-8px) scale(0.985);
}

.mega-menu-grid {
  display: grid;
  grid-template-columns: 1fr 290px;
  gap: 28px;
  align-items: stretch;
}

/* Left / Center Columns */
.mega-columns-wrap {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
}

.mega-col {
  display: flex;
  flex-direction: column;
  gap: 22px;
  min-width: 0;
  padding: 0 14px;
}

/* Thin dividers between the three columns on wide screens */
@media (min-width: 1025px) {
  .mega-col:first-child {
    padding-left: 0;
  }

  .mega-col:last-child {
    padding-right: 0;
  }

  .mega-col + .mega-col {
    border-left: 1px solid var(--border-subtle);
  }
}

.mega-group {
  display: flex;
  flex-direction: column;
}

.mega-col-title {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 8px;
  padding-left: 10px;
}

/* A dot in the section's colour, matching its icons */
.mega-col-title::before {
  content: '';
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  border-radius: 2px;
  background: var(--group-color, var(--color-primary));
}

.mega-item-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.mega-item {
  width: 100%;
}

.mega-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 7px 10px;
  border-radius: 12px;
  color: var(--text-main);
  text-decoration: none;
  font-weight: 600;
  font-size: 0.9rem;
  transition: background var(--transition-fast, 0.2s), color var(--transition-fast, 0.2s);
}

.mega-link:hover {
  background: var(--bg-subtle);
  color: var(--color-primary);
}

.mega-link.router-link-exact-active {
  background: var(--color-primary-light);
  color: var(--color-primary);
  font-weight: 700;
}

/* Soft tinted tile in the item's own colour */
.mega-item-icon-box {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 9px;
  flex-shrink: 0;
  overflow: hidden;
}

.mega-item-icon-box::before {
  content: '';
  position: absolute;
  inset: 0;
  background: currentColor;
  opacity: 0.12;
}

.mega-item-icon-box :deep(svg) {
  position: relative;
}

.mega-link-label {
  flex: 1;
  min-width: 0;
  line-height: 1.25;
}

.mega-badge-pill {
  border-radius: 9999px;
  background: #ef4444;
  color: #ffffff;
  font-size: 0.7rem;
  font-weight: 800;
  padding: 1px 7px;
  margin-left: auto;
  box-shadow: 0 2px 6px rgba(239, 68, 68, 0.35);
}

/* =========================================================
   RIGHT ACCOUNT & SYSTEM STATUS PANEL
   ========================================================= */

.mega-account-panel {
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  border-radius: 20px;
  padding: 18px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 14px;
  min-width: 280px;
}

.account-profile-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 14px;
  text-decoration: none;
  color: var(--text-main);
}

.account-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--color-primary), #2d8212);
  color: #ffffff;
  font-size: 1.15rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

html[data-theme='bsu'] .account-avatar {
  background: linear-gradient(135deg, #1A5209, #25700e);
}

.account-details {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.account-name {
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--text-main);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-username {
  font-size: 0.75rem;
  color: var(--text-muted);
}

.account-tags {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
  flex-wrap: wrap;
}

.badge-role {
  font-size: 0.68rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 6px;
  background: var(--color-primary-light, rgba(26, 82, 9, 0.1));
  color: var(--color-primary);
  border: 1px solid rgba(26, 82, 9, 0.2);
}

.badge-status {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--color-success, #16a34a);
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-success, #16a34a);
  animation: pulse-dot 2s infinite ease-in-out;
}

@keyframes pulse-dot {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.55; transform: scale(1.15); }
}

/* Office & Access Meta Box */
.account-meta-box {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 14px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 12px;
  font-size: 0.8rem;
}

.meta-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.meta-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-muted);
  font-weight: 500;
  font-size: 0.78rem;
  flex-shrink: 0;
}

.meta-icon {
  color: var(--color-primary);
  opacity: 0.85;
}

.meta-value {
  color: var(--text-main);
  font-weight: 700;
  font-size: 0.8rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
  text-align: right;
}

/* Theme Strip */
.feature-theme-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 8px 12px;
  border-radius: 10px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  color: var(--text-main);
  font-size: 0.82rem;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s);
}

.feature-theme-strip:hover {
  border-color: var(--color-primary);
}

.theme-strip-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.theme-strip-icon {
  color: var(--color-accent, #e6d628);
}

.theme-strip-action {
  font-size: 0.7rem;
  font-weight: 800;
  color: var(--color-primary);
  text-transform: uppercase;
}

/* Action Buttons (Learn More & View Docs style) */
.feature-action-buttons {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  align-items: center;
}



.feature-btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 38px;
  border-radius: 9999px;
  background: #4f46e5;
  border: none;
  color: #ffffff;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s);
  box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

html[data-theme='bsu'] .feature-btn-primary {
  background: var(--color-primary);
  box-shadow: 0 4px 12px rgba(26, 82, 9, 0.35);
}

.feature-btn-secondary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 38px;
  border-radius: 9999px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  color: var(--text-main);
  font-size: 0.82rem;
  font-weight: 700;
  transition: all var(--transition-fast, 0.2s);
}
.feature-btn-secondary:hover {
  border-color: var(--border-hover);
  background: var(--bg-muted);
}
.feature-btn-primary:hover {
  transform: translateY(-1px);
  filter: brightness(1.1);
}

.badge-mini {
  background: #ef4444;
  color: #fff;
  font-size: 0.65rem;
  font-weight: 800;
  border-radius: 9999px;
  padding: 1px 5px;
}

/* =========================================================
   RESPONSIVE & MOBILE VIEW PLACEMENT
   ========================================================= */

@media (max-width: 1024px) {
  .mega-menu-grid {
    grid-template-columns: 1fr;
  }

  .mega-columns-wrap {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px 20px;
  }

  .mega-col {
    padding: 0;
  }

  .desktop-only {
    display: none !important;
  }
  .user-chip {
    padding: 0.3rem;
  }
}

/* Clean, Balanced, Bespoke Mobile View Placement */
@media (max-width: 768px) {
  .navbar-inner {
    height: 64px;
    padding: 0 0.85rem;
    gap: 0.5rem;
    flex-wrap: nowrap;
  }

  .brand-section {
    flex: 1;
    min-width: 0;
    gap: 0.5rem;
    overflow: hidden;
  }

  .brand-logos-container {
    gap: 0.3rem;
    flex-shrink: 0;
  }

  .nav-logo-badge {
    width: 32px;
    height: 32px;
  }

  .brand-title-group {
    min-width: 0;
    overflow: hidden;
  }

  .brand-title {
    font-size: 0.95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .brand-subtitle {
    font-size: 0.65rem;
    max-width: 110px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Symmetrical, unified mobile actions dock */
  .nav-actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(0, 0, 0, 0.22);
    padding: 3px 4px;
    border-radius: 9999px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    flex-shrink: 0;
  }

  .notif-wrapper {
    display: flex;
    align-items: center;
    position: relative;
  }

  .theme-btn.notif-bell-btn,
  .nav-menu-btn {
    width: 36px;
    height: 36px;
    min-width: 36px;
    min-height: 36px;
    padding: 0;
    margin: 0;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.16);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    position: relative;
    box-sizing: border-box;
    transition: all 0.2s ease;
  }

  .theme-btn.notif-bell-btn:hover,
  .nav-menu-btn:hover {
    background: rgba(255, 255, 255, 0.24);
    transform: none;
  }

  .theme-btn.notif-bell-btn.is-active,
  .nav-menu-btn.is-active {
    background: rgba(255, 255, 255, 0.35);
    border-color: #ffffff;
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.3);
  }

  .bell-unread-badge {
    top: -2px;
    right: -2px;
    min-width: 16px;
    height: 16px;
    font-size: 9px;
    padding: 0 3px;
  }

  .menu-badge-dot {
    top: 2px;
    right: 2px;
    width: 7px;
    height: 7px;
  }

  /* Full-width floating card on mobile */
  .mega-menu-card {
    position: fixed;
    top: 68px;
    left: 0.75rem;
    right: 0.75rem;
    width: auto;
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
    padding: 20px 16px;
    border-radius: 20px;
  }

  .mega-columns-wrap {
    grid-template-columns: 1fr;
    gap: 18px;
  }

  .mega-col {
    gap: 18px;
  }

  /* Flyout dropdown stays aligned on mobile */
  .notif-flyout {
    position: fixed;
    top: 68px;
    left: 0.75rem;
    right: 0.75rem;
    width: auto;
    max-width: none;
  }
}
</style>
