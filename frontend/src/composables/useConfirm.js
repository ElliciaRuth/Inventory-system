import { reactive } from 'vue'

// Global confirm dialog state rendered by components/ConfirmDialog.vue
export const confirmState = reactive({
  open: false,
  title: '',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  // 'danger' (delete/deactivate) | 'warning' (overwrite/irreversible) | 'primary' | 'success' | 'info'
  variant: 'primary',
  // A notice with a single OK button (alertDialog)
  alert: false,
  // What the action is about, shown as label/value rows: [['Product', 'Flour'], ['Quantity', '5 kg']]
  details: [],
})

let resolver = null

/**
 * Styled replacement for window.confirm(). Resolves true when confirmed.
 *   if (!(await confirmDialog({ title: 'Delete product?', message: '…', details: [['Product', name]], variant: 'danger' }))) return
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
    alert: Boolean(options.alert),
    // Rows with no value are left out
    details: (options.details || []).filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''),
    open: true,
  })

  return new Promise((resolve) => {
    resolver = resolve
  })
}

/**
 * A notice with a single button; resolves when it is closed.
 *   await alertDialog({ title: 'Account created', message: '…', variant: 'success' })
 */
export function alertDialog(options = {}) {
  return confirmDialog({ confirmText: 'OK', variant: 'info', ...options, alert: true })
}

export function settleConfirm(result) {
  confirmState.open = false
  resolver?.(result)
  resolver = null
}
