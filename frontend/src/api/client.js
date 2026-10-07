import axios from 'axios'

// All requests go to the same-origin /api prefix:
// - production: nginx forwards /api/* to the CodeIgniter backend
// - development: the Vite dev server proxies /api (see vite.config.js)
const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  timeout: 30000,
})

// Error codes the backend sends while a forced first-login step is pending
export const SETUP_CODES = {
  password_change_required: 'change_password',
  smtp_setup_required: 'setup_smtp',
  recovery_email_setup_required: 'setup_recovery_email',
}

// Registered by the router so this module doesn't import it directly
let authFailureHandler = null
export function onAuthFailure(handler) {
  authFailureHandler = handler
}

client.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status
    let data = error.response?.data

    // Blob requests (downloads) carry JSON errors as a Blob — decode them so
    // callers can read error.response.data.message as usual.
    if (data instanceof Blob && data.type.includes('json')) {
      try {
        data = JSON.parse(await data.text())
        error.response.data = data
      } catch {
        // leave as-is
      }
    }

    if (authFailureHandler) {
      if (status === 401) {
        authFailureHandler({ type: 'unauthenticated', code: data?.code, message: data?.message })
      } else if (status === 403 && SETUP_CODES[data?.code]) {
        authFailureHandler({ type: 'setup', step: SETUP_CODES[data.code] })
      }
    }

    return Promise.reject(error)
  }
)

export default client
