import { ref, onMounted, onUnmounted } from 'vue'

export const reloadListeners = new Set()
export const lastReloadTime = ref(Date.now())
export const isReloading = ref(false)
export const autoSyncEnabled = ref(true)

// Reloads nobody asked for: the timer, coming back to the window, or a change someone
// else made (useLiveSync). Pages refresh these quietly, without a loading state.
const BACKGROUND_REASONS = new Set(['interval', 'focus', 'visibility', 'remote'])
export function isBackgroundReload(reason) {
  return BACKGROUND_REASONS.has(reason)
}

/**
 * Triggers a global reload across all active listeners and components.
 * Can be called after any mutation (create, update, delete, stock movement, etc.)
 */
export function triggerAutoReload(reason = 'change') {
  lastReloadTime.value = Date.now()
  isReloading.value = true

  const promises = []
  for (const listener of reloadListeners) {
    try {
      const res = listener(reason)
      if (res instanceof Promise) {
        promises.push(res)
      }
    } catch (err) {
      console.warn('Error executing auto-reload listener:', err)
    }
  }

  Promise.allSettled(promises).finally(() => {
    setTimeout(() => {
      isReloading.value = false
    }, 400)
  })
}

/**
 * Helper utility to deduplicate an array of objects by a unique key (e.g. 'product_id', 'id', 'user_id').
 * Prevents any duplicate entries from ever appearing in the UI.
 */
export function deduplicateById(items, key = 'id') {
  if (!Array.isArray(items)) return []
  const seen = new Set()
  return items.filter((item) => {
    if (!item) return false
    const id = item[key] ?? item.id ?? item._id
    if (id === undefined || id === null) return true // Keep if no identifiable key
    if (seen.has(id)) {
      return false
    }
    seen.add(id)
    return true
  })
}

/**
 * Composable to attach auto-reload lifecycle to any view.
 * @param {Function} fetchFn The data fetching function of the component.
 * @param {Object} options Configuration options (intervalMs, onFocus, etc.)
 */
export function useAutoReload(fetchFn, options = {}) {
  const {
    // A safety net only: changes arrive within seconds through useLiveSync. The timer
    // still catches what changes by itself, such as batches reaching their expiry date.
    intervalMs = 60000,
    reloadOnFocus = true,
    reloadOnVisibility = true,
  } = options

  let intervalId = null
  // Only skip when THIS fetch is still running: a shared flag would let the first
  // listener block every other one (and triggerAutoReload sets it before calling them)
  let inFlight = false

  // Wrap fetch function to track reloading state
  async function executeReload(reason = 'periodic') {
    if (!fetchFn || inFlight) return
    inFlight = true
    try {
      isReloading.value = true
      await fetchFn(reason)
      lastReloadTime.value = Date.now()
    } catch (err) {
      console.error(`Auto-reload failed (${reason}):`, err)
    } finally {
      inFlight = false
      setTimeout(() => {
        isReloading.value = false
      }, 300)
    }
  }

  function handleFocus() {
    if (reloadOnFocus && autoSyncEnabled.value) {
      // Avoid excessive reloads if focused within last 3 seconds
      if (Date.now() - lastReloadTime.value > 3000) {
        executeReload('focus')
      }
    }
  }

  function handleVisibilityChange() {
    if (
      reloadOnVisibility &&
      document.visibilityState === 'visible' &&
      autoSyncEnabled.value
    ) {
      if (Date.now() - lastReloadTime.value > 3000) {
        executeReload('visibility')
      }
    }
  }

  onMounted(() => {
    // Register listener for explicit triggerAutoReload() calls
    reloadListeners.add(executeReload)

    // Window focus and visibility listeners
    if (reloadOnFocus) {
      window.addEventListener('focus', handleFocus)
    }
    if (reloadOnVisibility) {
      document.addEventListener('visibilitychange', handleVisibilityChange)
    }

    // Background interval
    if (intervalMs > 0) {
      intervalId = setInterval(() => {
        if (autoSyncEnabled.value && document.visibilityState === 'visible') {
          executeReload('interval')
        }
      }, intervalMs)
    }
  })

  onUnmounted(() => {
    reloadListeners.delete(executeReload)
    if (intervalId) clearInterval(intervalId)
    if (reloadOnFocus) window.removeEventListener('focus', handleFocus)
    if (reloadOnVisibility) document.removeEventListener('visibilitychange', handleVisibilityChange)
  })

  return {
    triggerReload: triggerAutoReload,
    isReloading,
    lastReloadTime,
    autoSyncEnabled,
  }
}
