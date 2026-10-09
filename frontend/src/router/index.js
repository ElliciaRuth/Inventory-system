import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import ProductsView from '../views/ProductsView.vue'
import StockcardView from '../views/StockcardView.vue'
import TransactionsView from '../views/TransactionsView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import AccountSetupView from '../views/AccountSetupView.vue'
import ExportStockcardView from '../views/ExportStockcardView.vue'
import NotificationsView from '../views/NotificationsView.vue'
import StockoutView from '../views/StockoutView.vue'
import StockoutListView from '../views/StockoutListView.vue'
import StockoutPendingView from '../views/StockoutPendingView.vue'
import SettingsView from '../views/SettingsView.vue'
import BatchesView from '../views/BatchesView.vue'
import SummaryReportView from '../views/SummaryReportView.vue'
import ExportSummaryView from '../views/ExportSummaryView.vue'
import FinishedBarcodesView from '../views/FinishedBarcodesView.vue'
import ForgotPasswordView from '../views/ForgotPasswordView.vue'
import ProfileView from '../views/ProfileView.vue'
import { useAuthStore } from '../stores/authStore'
import { onAuthFailure } from '../api/client'
import { toast } from '../composables/useToast'

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
    meta: { title: 'Settings - BSU Inventory', minLevel: 2, maxLevel: 3 },
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
  { path: '/stockout/temp', redirect: '/stockout/list' },
  { path: '/stock/add', redirect: '/stockcard' },
  {
    path: '/transactions',
    name: 'transactions',
    component: TransactionsView,
    meta: { title: 'Transaction Log - BSU Inventory', maxLevel: 3 },
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

export default router
