import { defineStore } from 'pinia'
import { notificationApi } from '../api/notification'
import { useAuthStore } from './authStore'

export const useNotificationStore = defineStore('notification', {
  state: () => ({
    notifications: [],
    counts: {
      total: 0,
      outOfStock: 0,
      lowStock: 0,
      expiring: 0,
      borrows: 0,
      pendingUsers: 0,
      stockoutRequests: 0,
      stockoutDecisions: 0,
    },
    readIds: [],
    dismissedIds: [],
    loading: false,
    lastFetched: null,
  }),

  getters: {
    activeNotifications: (state) => {
      return state.notifications
        .filter((n) => !state.dismissedIds.includes(n.id))
        .map((n) => ({
          ...n,
          isRead: state.readIds.includes(n.id),
        }))
    },

    unreadNotifications() {
      return this.activeNotifications.filter((n) => !n.isRead)
    },

    unreadCount() {
      return this.unreadNotifications.length
    },

    criticalCount() {
      return this.activeNotifications.filter((n) => n.severity === 'danger' && !n.isRead).length
    },
  },

  actions: {
    getStorageKey(prefix) {
      const authStore = useAuthStore()
      const uid = authStore.user?.id || 'guest'
      return `bsu_${prefix}_${uid}`
    },

    loadPersistedState() {
      try {
        const readRaw = localStorage.getItem(this.getStorageKey('read_notifications'))
        if (readRaw) this.readIds = JSON.parse(readRaw)

        const dismissedRaw = localStorage.getItem(this.getStorageKey('dismissed_notifications'))
        if (dismissedRaw) this.dismissedIds = JSON.parse(dismissedRaw)
      } catch (e) {
        console.warn('Failed to parse stored notifications state', e)
      }
    },

    persistReadState() {
      try {
        localStorage.setItem(this.getStorageKey('read_notifications'), JSON.stringify(this.readIds))
      } catch (e) {
        console.warn('Failed to save read notifications', e)
      }
    },

    persistDismissedState() {
      try {
        localStorage.setItem(
          this.getStorageKey('dismissed_notifications'),
          JSON.stringify(this.dismissedIds)
        )
      } catch (e) {
        console.warn('Failed to save dismissed notifications', e)
      }
    },

    async fetchNotifications() {
      this.loadPersistedState()
      this.loading = true
      try {
        const res = await notificationApi.getNotifications()
        if (res && res.status && res.data) {
          this.notifications = res.data.notifications || []
          this.counts = res.data.counts || this.counts
          this.lastFetched = new Date().toISOString()
        }
      } catch (err) {
        console.error('Failed to fetch notifications', err)
      } finally {
        this.loading = false
      }
    },

    markAsRead(id) {
      if (!this.readIds.includes(id)) {
        this.readIds.push(id)
        this.persistReadState()
      }
    },

    markAllAsRead() {
      const allIds = this.notifications.map((n) => n.id)
      this.readIds = Array.from(new Set([...this.readIds, ...allIds]))
      this.persistReadState()
    },

    dismiss(id) {
      if (!this.dismissedIds.includes(id)) {
        this.dismissedIds.push(id)
        this.persistDismissedState()
      }
    },

    clearAll() {
      const allIds = this.notifications.map((n) => n.id)
      this.dismissedIds = Array.from(new Set([...this.dismissedIds, ...allIds]))
      this.persistDismissedState()
    },
  },
})
