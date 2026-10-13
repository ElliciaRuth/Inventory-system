import { defineStore } from 'pinia'
import { authApi } from '../api/auth'

let loadPromise = null

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    // Forced first-login step still to complete:
    // 'change_password' | 'setup_smtp' | 'setup_recovery_email' | null
    pendingSetup: null,
    loaded: false,
    loading: false,
    error: null,
  }),

  getters: {
    userName: (state) => state.user?.username || 'Guest',
    officeName: (state) => state.user?.office_name || 'BSU Inventory',
    role: (state) => state.user?.role || 'Staff',
    levelId: (state) => Number(state.user?.level_id || 0),
    canManageStock: (state) => Number(state.user?.level_id || 0) >= 2,
    isAdmin: (state) => Number(state.user?.level_id || 0) >= 3,
  },

  actions: {
    setSession(user, pendingSetup = null) {
      this.user = user
      this.isAuthenticated = !!user
      this.pendingSetup = user ? pendingSetup : null
    },

    clearSession() {
      this.setSession(null)
    },

    async checkAuth() {
      this.loading = true
      try {
        const res = await authApi.me()
        if (res.status && res.data?.authenticated) {
          this.setSession(res.data.user, res.data.pending_setup)
        } else {
          this.clearSession()
        }
      } catch {
        this.clearSession()
      } finally {
        this.loaded = true
        this.loading = false
      }
    },

    // Loads the session once; later calls reuse the same request.
    ensureLoaded() {
      if (this.loaded) return Promise.resolve()
      loadPromise ??= this.checkAuth().finally(() => {
        loadPromise = null
      })
      return loadPromise
    },

    async login(username, password) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.login(username, password)
        if (res.status && res.data?.user) {
          this.setSession(res.data.user, res.data.pending_setup)
          this.loaded = true
          return true
        }
        this.error = res.message || 'Login failed'
        return false
      } catch (err) {
        this.error = err.response?.data?.message || err.message || 'Invalid username or password'
        return false
      } finally {
        this.loading = false
      }
    },

    async logout() {
      try {
        await authApi.logout()
      } catch {
        // Session is cleared locally either way
      }
      this.clearSession()
    },
  },
})
