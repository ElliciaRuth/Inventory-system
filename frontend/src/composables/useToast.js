import { reactive } from 'vue'

// Global toast queue rendered by components/ToastContainer.vue
export const toasts = reactive([])
let nextId = 1

export function toast(message, type = 'success', timeoutMs = 3500) {
  const id = nextId++
  toasts.push({ id, message, type })
  setTimeout(() => {
    const index = toasts.findIndex((t) => t.id === id)
    if (index !== -1) toasts.splice(index, 1)
  }, timeoutMs)
}

// Message from an axios error, falling back to a generic one
export function errorMessage(err, fallback = 'Something went wrong. Please try again.') {
  return err?.response?.data?.message || err?.message || fallback
}
