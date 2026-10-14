import { onMounted, onUnmounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { confirmDialog } from './useConfirm'

/**
 * Keeps unsaved inline edits from being lost: asks before leaving the page (in the app or
 * by closing the tab), and gives the view one question to ask before throwing edits away.
 *   const { confirmDiscard } = useUnsavedChanges(() => changeCount.value > 0)
 *   if (!(await confirmDiscard('Open another item?'))) return
 */
export function useUnsavedChanges(isDirty, { message = 'You have changes that are not saved yet. They will be lost.' } = {}) {
  function onBeforeUnload(event) {
    if (!isDirty()) return
    event.preventDefault()
    event.returnValue = ''
  }

  async function confirmDiscard(title = 'Discard your changes?', confirmText = 'Discard changes') {
    if (!isDirty()) return true
    return confirmDialog({
      title,
      message,
      confirmText,
      cancelText: 'Keep editing',
      variant: 'warning',
    })
  }

  onBeforeRouteLeave(() => confirmDiscard('Leave without saving?', 'Leave page'))
  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload))

  return { confirmDiscard }
}
