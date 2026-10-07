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
import { useAuthStore } from '../stores/authStore'
import { onAuthFailure } from '../api/client'

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
    path: '/export',
    redirect: '/export/stockcard',
  },
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
onAuthFailure(({ type, step }) => {
  const authStore = useAuthStore()
  const current = router.currentRoute.value

  if (type === 'unauthenticated') {
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
