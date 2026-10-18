import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import { useAuthStore } from '../stores/authStore'
import { onAuthFailure } from '../api/client'
import { toast } from '../composables/useToast'

// The sign-in page and the dashboard come with the app; every other page is downloaded
// the first time it is opened, so the first load stays small
const ProductsView = () => import('../views/ProductsView.vue')
const StockcardView = () => import('../views/StockcardView.vue')
const TransactionsView = () => import('../views/TransactionsView.vue')
const AdminDashboardView = () => import('../views/AdminDashboardView.vue')
const RegisterView = () => import('../views/RegisterView.vue')
const AccountSetupView = () => import('../views/AccountSetupView.vue')
const ExportStockcardView = () => import('../views/ExportStockcardView.vue')
const NotificationsView = () => import('../views/NotificationsView.vue')
const StockoutView = () => import('../views/StockoutView.vue')
const StockoutListView = () => import('../views/StockoutListView.vue')
const StockoutPendingView = () => import('../views/StockoutPendingView.vue')
const StockoutHistoryView = () => import('../views/StockoutHistoryView.vue')
const SettingsView = () => import('../views/SettingsView.vue')
const BatchesView = () => import('../views/BatchesView.vue')
const SummaryReportView = () => import('../views/SummaryReportView.vue')
const ExportSummaryView = () => import('../views/ExportSummaryView.vue')
const FinishedBarcodesView = () => import('../views/FinishedBarcodesView.vue')
const ForgotPasswordView = () => import('../views/ForgotPasswordView.vue')
const ProfileView = () => import('../views/ProfileView.vue')
const AuditLogView = () => import('../views/AuditLogView.vue')
const PhysicalCountView = () => import('../views/PhysicalCountView.vue')
const BorrowsView = () => import('../views/BorrowsView.vue')
const HelpView = () => import('../views/HelpView.vue')

// meta.public   — reachable without logging in
// meta.minLevel — lowest access level allowed (mirrors the backend route filters)
// meta.maxLevel — highest access level allowed (Technical Staff, level 4, only
//                 manages users, so inventory pages are hidden from them)
// Levels: 1 Staff, 2 Custodian, 3 Manager, 4 Technical Staff
const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView,
    meta: { title: 'Dashboard - BSU Inventory' },
  },
  {
    path: '/admin',
    name: 'admin',
    component: AdminDashboardView,
    meta: { title: 'User Management - BSU Administration', minLevel: 4 },
  },
  {
    path: '/products',
    name: 'products',
    component: ProductsView,
    meta: { title: 'Products - BSU Inventory', maxLevel: 3 },
  },
  {
    path: '/stockcard',
    name: 'stockcard',
    component: StockcardView,
    meta: { title: 'Stockcard - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/export/stockcard',
    name: 'export-stockcard',
    component: ExportStockcardView,
    meta: { title: 'Export Stock Card - BSU Inventory', minLevel: 2 },
  },
  {
    path: '/export/summary',
    name: 'export-summary',
    component: ExportSummaryView,
    meta: { title: 'Export Summary - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/export',
    redirect: '/export/stockcard',
  },
  {
    path: '/products/barcodes',
    name: 'finished-barcodes',
    component: FinishedBarcodesView,
    meta: { title: 'Finished Product Barcodes - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/reports/batches',
    name: 'batches',
    component: BatchesView,
    meta: { title: 'Batch Inventory - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/reports/summary',
    name: 'summary-report',
    component: SummaryReportView,
    meta: { title: 'Inventory Report - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/stockout',
    name: 'stockout',
    component: StockoutView,
    meta: { title: 'Request Stock Out - BSU Inventory', maxLevel: 3 },
  },
  {
    path: '/stockout/list',
    name: 'stockout-list',
    component: StockoutListView,
    meta: { title: 'My Stock-Out List - BSU Inventory', maxLevel: 3 },
  },
  {
    path: '/stockout/pending',
    name: 'stockout-pending',
    component: StockoutPendingView,
    meta: { title: 'Stock-Out Requests - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/settings',
    name: 'settings',
    component: SettingsView,
    // Office Settings: managers only
    meta: { title: 'Office Settings - BSU Inventory', minLevel: 3, maxLevel: 3 },
  },
  {
    path: '/profile',
    name: 'profile',
    component: ProfileView,
    meta: { title: 'Edit Profile - BSU Inventory' },
  },
  {
    path: '/edit-profile',
    redirect: '/profile',
  },
  {
    path: '/change-password',
    redirect: '/profile',
  },
  // Old CodeIgniter page URLs
  { path: '/batches', redirect: '/reports/batches' },
  { path: '/batchlist', redirect: '/reports/summary' },
  {
    // Staff: their own requests; custodians/managers: their office's
    path: '/stockout/history',
    name: 'stockout-history',
    component: StockoutHistoryView,
    meta: { title: 'Request History - BSU Inventory', maxLevel: 3 },
  },
  { path: '/stockout/temp', redirect: '/stockout/list' },
  { path: '/stock/add', redirect: '/stockcard' },
  {
    path: '/transactions',
    name: 'transactions',
    component: TransactionsView,
    meta: { title: 'Transaction Log - BSU Inventory', maxLevel: 3 },
  },
  {
    path: '/stock/count',
    name: 'physical-count',
    component: PhysicalCountView,
    meta: { title: 'Physical Count - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    path: '/stock/borrows',
    name: 'borrows',
    component: BorrowsView,
    meta: { title: 'Borrowed Items - BSU Inventory', minLevel: 2, maxLevel: 3 },
  },
  {
    // Managers: their office; technical staff: every office
    path: '/audit-log',
    name: 'audit-log',
    component: AuditLogView,
    meta: { title: 'Audit Log - BSU Inventory', minLevel: 3 },
  },
  {
    // Every role: the guide shows only the topics for the user's level
    path: '/help',
    name: 'help',
    component: HelpView,
    meta: { title: 'Help & User Guide - BSU Inventory' },
  },
  {
    path: '/notifications',
    name: 'notifications',
    component: NotificationsView,
    meta: { title: 'Notifications - BSU Inventory' },
  },
  {
    path: '/account-setup',
    name: 'account-setup',
    component: AccountSetupView,
    meta: { title: 'Account Setup - BSU Inventory' },
  },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { title: 'Login - BSU Inventory', public: true },
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterView,
    meta: { title: 'Create Account - BSU Inventory', public: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: ForgotPasswordView,
    meta: { title: 'Forgot Password - BSU Inventory', public: true },
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  if (to.meta.title) {
    document.title = to.meta.title
  }

  const authStore = useAuthStore()
  await authStore.ensureLoaded()

  if (to.meta.public) {
    // Logged-in users don't need the login/register pages
    return authStore.isAuthenticated ? { name: 'dashboard' } : true
  }

  if (!authStore.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  // A pending first-login step blocks every other page
  if (authStore.pendingSetup) {
    return to.name === 'account-setup' ? true : { name: 'account-setup' }
  }
  if (to.name === 'account-setup') {
    return { name: 'dashboard' }
  }

  const level = authStore.levelId
  if ((to.meta.minLevel && level < to.meta.minLevel) || (to.meta.maxLevel && level > to.meta.maxLevel)) {
    return { name: 'dashboard' }
  }

  return true
})

// The API reports an expired/missing session (401) or a pending setup step (403)
onAuthFailure(({ type, step, code, message }) => {
  const authStore = useAuthStore()
  const current = router.currentRoute.value

  if (type === 'unauthenticated') {
    // Explain why the user is being sent to the login page (once, not per failed request)
    if (authStore.isAuthenticated && (code === 'session_expired' || code === 'account_inactive') && message) {
      toast(message, 'info', 6000)
    }
    authStore.clearSession()
    if (!current.meta.public) {
      router.push({ name: 'login', query: current.fullPath !== '/' ? { redirect: current.fullPath } : {} })
    }
  } else if (type === 'setup') {
    authStore.pendingSetup = step
    if (current.name !== 'account-setup') {
      router.push({ name: 'account-setup' })
    }
  }
})

// After the server is updated, a tab opened before the update asks for page files that no
// longer exist. Load the page fresh (once per page, so a real network failure can't loop).
router.onError((error, to) => {
  const stale = /dynamically imported module|Importing a module script failed|error loading dynamically imported/i
  if (!stale.test(String(error?.message || ''))) return
  const key = `bsu_reloaded_for:${to.fullPath}`
  try {
    if (sessionStorage.getItem(key)) return
    sessionStorage.setItem(key, '1')
  } catch {
    return // Storage blocked: without the marker a reload could repeat, so leave it to the user
  }
  window.location.assign(to.fullPath)
})

export default router
