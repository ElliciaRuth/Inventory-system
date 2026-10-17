import { watch, onUnmounted } from 'vue'
import client from '../api/client'
import { useAuthStore } from '../stores/authStore'
import { triggerAutoReload, autoSyncEnabled } from './useAutoReload'

// How often to ask the server whether anything changed. The answer is a few bytes
// (GET api/sync), so this is cheaper than the full reloads it replaces.
const CHECK_EVERY_MS = 3000

/**
 * Refreshes the open page and the notifications within seconds of anyone changing
 * something (a staff request, an approval, a stock movement…), without reloading
 * the browser. Mount once, in App.vue.
 */
export function useLiveSync() {
  const authStore = useAuthStore()

  let timer = null
  let known = null
  let checking = false

  async function check() {
    if (checking || !autoSyncEnabled.value || document.visibilityState !== 'visible') return
    checking = true
    try {
      const res = await client.get('/sync', { timeout: 10000 })
      const version = res.data?.data?.version
      if (version) {
        if (known !== null && version !== known) triggerAutoReload('remote')
        known = version
      }
    } catch {
      // Offline or signed out: the next check tries again (a 401 signs out as usual)
    } finally {
      checking = false
    }
  }

  function start() {
    if (timer) return
    known = null
    check()
    timer = setInterval(check, CHECK_EVERY_MS)
  }

  function stop() {
    clearInterval(timer)
    timer = null
    known = null
  }

  // Coming back to the tab: catch up at once instead of waiting for the next tick
  function onVisibilityChange() {
    if (document.visibilityState === 'visible') check()
  }

  watch(
    () => authStore.isAuthenticated && !authStore.pendingSetup,
    (active) => (active ? start() : stop()),
    { immediate: true }
  )
  document.addEventListener('visibilitychange', onVisibilityChange)

  onUnmounted(() => {
    stop()
    document.removeEventListener('visibilitychange', onVisibilityChange)
  })
}
