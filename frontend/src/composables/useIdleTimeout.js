import { watch, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { authApi } from '../api/auth'
import { toast } from './useToast'

// Must match AuthFilter::IDLE_TTL on the backend
export const IDLE_TIMEOUT_MS = 15 * 60 * 1000
const WARNING_BEFORE_MS = 60 * 1000
const CHECK_EVERY_MS = 10 * 1000
// While the user is active, ping the server this often so a long stretch of
// typing/reading without API calls doesn't expire the server-side session
const KEEPALIVE_EVERY_MS = 5 * 60 * 1000

// Shared across tabs, so activity in one tab keeps the others logged in
const STORAGE_KEY = 'bsu_last_activity'
const ACTIVITY_EVENTS = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'wheel']

function readLastActivity() {
  try {
    return Number(localStorage.getItem(STORAGE_KEY)) || 0
  } catch {
    return 0
  }
}

function writeLastActivity(time) {
  try {
    localStorage.setItem(STORAGE_KEY, String(time))
  } catch {
    // Storage unavailable — this tab still tracks its own activity
  }
}

/**
 * Logs the user out after IDLE_TIMEOUT_MS without mouse, keyboard, touch or
 * scroll activity. Mount once, in App.vue.
 */
export function useIdleTimeout() {
  const router = useRouter()
  const authStore = useAuthStore()

  let lastActivity = Date.now()
  let lastKeepAlive = Date.now()
  let warned = false
  let checkId = null

  function markActive() {
    const now = Date.now()
    // Throttle localStorage writes; mousemove fires constantly
    if (now - lastActivity < 1000) return
    lastActivity = now
    warned = false
    writeLastActivity(now)
  }

  async function expire() {
    stop()
    await authStore.logout()
    toast('You were logged out after 15 minutes of inactivity.', 'info', 6000)
    const current = router.currentRoute.value
    if (!current.meta.public) {
      router.push({ name: 'login', query: current.fullPath !== '/' ? { redirect: current.fullPath } : {} })
    }
  }

  function check() {
    const now = Date.now()
    lastActivity = Math.max(lastActivity, readLastActivity())
    const idleFor = now - lastActivity

    if (idleFor >= IDLE_TIMEOUT_MS) {
      expire()
      return
    }

    if (idleFor >= IDLE_TIMEOUT_MS - WARNING_BEFORE_MS && !warned) {
      warned = true
      toast('You will be logged out in 1 minute due to inactivity.', 'info', WARNING_BEFORE_MS)
    }

    if (lastActivity > lastKeepAlive && now - lastKeepAlive >= KEEPALIVE_EVERY_MS) {
      lastKeepAlive = now
      authApi.me().catch(() => {})
    }
  }

  function start() {
    if (checkId) return
    lastActivity = Date.now()
    lastKeepAlive = lastActivity
    warned = false
    writeLastActivity(lastActivity)
    ACTIVITY_EVENTS.forEach((e) => window.addEventListener(e, markActive, { passive: true }))
    // Timers are throttled in background tabs and paused during sleep, so
    // compare timestamps on return instead of trusting a single setTimeout
    document.addEventListener('visibilitychange', check)
    checkId = setInterval(check, CHECK_EVERY_MS)
  }

  function stop() {
    if (!checkId) return
    clearInterval(checkId)
    checkId = null
    ACTIVITY_EVENTS.forEach((e) => window.removeEventListener(e, markActive))
    document.removeEventListener('visibilitychange', check)
  }

  watch(
    () => authStore.isAuthenticated,
    (loggedIn) => (loggedIn ? start() : stop()),
    { immediate: true }
  )

  onUnmounted(stop)
}
