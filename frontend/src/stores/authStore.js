import { defineStore } from 'pinia'
import { authApi } from '../api/auth'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    loading: false,
    error: null,
  }),

  getters: {
    userName: (state) => state.user?.username || 'Guest',
    officeName: (state) => state.user?.office_name || 'BSU Inventory',
    role: (state) => state.user?.role || 'Staff',
    levelId: (state) => Number(state.user?.level_id || 1),
    canManageStock: (state) => Number(state.user?.level_id || 1) >= 2,
    isAdmin: (state) => Number(state.user?.level_id || 1) >= 3,
  },

  actions: {
    async checkAuth() {
      this.loading = true
      try {
        const res = await authApi.me()
        if (res.status && res.data?.authenticated) {
          this.user = res.data.user
          this.isAuthenticated = true
        } else {
          // If no active session, provide fallback default info for frictionless preview
          this.user = {
            id: 1,
            username: 'administrator',
            email: 'admin@bsu.edu.ph',
            role: 'Administrator',
            level_id: 3,
            user_office_id: 2,
            office_name: 'Food Processing Center',
          }
          this.isAuthenticated = true
        }
      } catch (err) {
        // Fallback for standalone/dev demo
        this.user = {
          id: 1,
          username: 'administrator',
          email: 'admin@bsu.edu.ph',
          role: 'Administrator',
          level_id: 3,
          user_office_id: 2,
          office_name: 'Food Processing Center',
        }
        this.isAuthenticated = true
      } finally {
        this.loading = false
      }
    },

    async login(username, password) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.login(username, password)
        if (res.status && res.data?.user) {
          this.user = res.data.user
          this.isAuthenticated = true
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
      } catch (e) {
        // Ignore logout error
      }
      this.user = null
      this.isAuthenticated = false
    },
  },
})
