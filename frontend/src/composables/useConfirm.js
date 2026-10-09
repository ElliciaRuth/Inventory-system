import { reactive } from 'vue'

// Global confirm dialog state rendered by components/ConfirmDialog.vue
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  // 'danger' (delete/deactivate) | 'warning' (overwrite/irreversible) | 'primary'
  variant: 'primary',
})

let resolver = null

/**
 * Styled replacement for window.confirm(). Resolves true when confirmed.
 *   if (!(await confirmDialog({ title: 'Delete product?', message: '…', variant: 'danger' }))) return
 */
export function confirmDialog(options = {}) {
  // A dialog already open is answered "cancel" before the new one replaces it
  resolver?.(false)

  Object.assign(confirmState, {
    title: options.title || 'Are you sure?',
    message: options.message || '',
    confirmText: options.confirmText || 'Confirm',
    cancelText: options.cancelText || 'Cancel',
    variant: options.variant || 'primary',
    open: true,
  })

  return new Promise((resolve) => {
    resolver = resolve
  })
}

export function settleConfirm(result) {
  confirmState.open = false
  resolver?.(result)
  resolver = null
}
