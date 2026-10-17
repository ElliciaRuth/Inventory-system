import { defineStore } from 'pinia'
import { notificationApi } from '../api/notification'
import { useAuthStore } from './authStore'
import { toast } from '../composables/useToast'

// Notifications that need someone to act are announced with a pop-up when they arrive:
// a new stock-out request, a decision on yours, a new sign-up
const ANNOUNCED = /^(stockout-pending|stockout-decision|user-pending)-/

// What this user has already seen, so only new arrivals pop up (not the ones there at sign-in)
let seenFor = null
let seenIds = new Set()

function announceNew(notifications, userId) {
  if (seenFor !== userId) {
    seenFor = userId
    seenIds = new Set(notifications.map((n) => n.id))
    return
  }
  const fresh = notifications.filter((n) => ANNOUNCED.test(n.id) && !seenIds.has(n.id))
  notifications.forEach((n) => seenIds.add(n.id))

  if (fresh.length > 2) {
    toast(`${fresh.length} new notifications. Open the bell to see them.`, 'info', 7000)
  } else {
    fresh.forEach((n) => toast(n.title, 'info', 7000))
  }
}

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
          announceNew(this.notifications, useAuthStore().user?.id ?? null)
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
