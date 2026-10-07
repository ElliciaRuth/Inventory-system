import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import ProductsView from '../views/ProductsView.vue'
import StockcardView from '../views/StockcardView.vue'
import TransactionsView from '../views/TransactionsView.vue'
import AdminDashboardView from '../views/AdminDashboardView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import ExportStockcardView from '../views/ExportStockcardView.vue'
import NotificationsView from '../views/NotificationsView.vue'
import { useAuthStore } from '../stores/authStore'

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
    meta: { title: 'User Management - BSU Administration' },
  },
  {
    path: '/products',
    name: 'products',
    component: ProductsView,
    meta: { title: 'Products - BSU Inventory' },
  },
  {
    path: '/stockcard',
    name: 'stockcard',
    component: StockcardView,
    meta: { title: 'Stockcard - BSU Inventory' },
  },
  {
    path: '/export/stockcard',
    name: 'export-stockcard',
    component: ExportStockcardView,
    meta: { title: 'Export Stock Card - BSU Inventory' },
  },
  {
    path: '/export',
    redirect: '/export/stockcard',
  },
  {
    path: '/transactions',
    name: 'transactions',
    component: TransactionsView,
    meta: { title: 'Transaction Log - BSU Inventory' },
  },
  {
    path: '/notifications',
    name: 'notifications',
    component: NotificationsView,
    meta: { title: 'Notifications - BSU Inventory' },
  },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { title: 'Login - BSU Inventory' },
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterView,
    meta: { title: 'Create Account - BSU Inventory' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  if (to.meta.title) {
    document.title = to.meta.title
  }

  // Guard inventory pages from admin/technical staff accounts (Level 4)
  const authStore = useAuthStore()
  if (authStore.levelId >= 4 && ['products', 'stockcard', 'transactions'].includes(to.name)) {
    return next({ name: 'dashboard' })
  }

  next()
})

export default router
