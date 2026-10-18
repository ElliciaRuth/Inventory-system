import { onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '../stores/authStore'

// Set when the app itself reloads the page (e.g. after a server update), so no warning shows
let leavingOnPurpose = false

/** Lets the app reload or leave the page without the sign-out reminder. */
export function allowLeaving() {
  leavingOnPurpose = true
}

/**
 * While someone is signed in, closing the tab or window (or reloading) asks first, so people
 * on a shared computer are reminded to sign out. Browsers only allow their own "Leave site?"
 * dialog here, with their own wording, and only once the page has been used (clicked or typed in).
 * Mount once, in App.vue.
 */
export function useSignOutReminder() {
  const authStore = useAuthStore()

  function onBeforeUnload(event) {
    if (leavingOnPurpose || !authStore.isAuthenticated) return
    event.preventDefault()
    event.returnValue = ''
  }

  onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
  onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload))
}
