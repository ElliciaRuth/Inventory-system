<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { useThemeStore } from '../stores/themeStore'

const router = useRouter()
const authStore = useAuthStore()
const themeStore = useThemeStore()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const errorMessage = ref('')

// Info Modals
const showRegisterModal = ref(false)
const showForgotModal = ref(false)

async function handleLogin() {
  if (loading.value) return
  errorMessage.value = ''
  if (!username.value || !password.value) {
    errorMessage.value = 'Please enter both username and password.'
    return
  }

  loading.value = true
  try {
    const success = await authStore.login(username.value, password.value)
    if (success) {
      router.push('/')
    } else {
      errorMessage.value = authStore.error || 'Invalid username or password.'
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || err.message || 'Login failed.'
  } finally {
    loading.value = false
  }
}

function handleQuickDemo() {
  authStore.user = {
    id: 1,
    username: username.value || 'unit_head',
    role: 'Unit Head',
    level_id: 2,
    user_office_id: 2,
    office_name: 'Food Processing Center',
  }
  authStore.isAuthenticated = true
  router.push('/')
}

function handleAdminDemo() {
  authStore.user = {
    id: 3,
    username: 'admin_tech',
    role: 'Technical Staff',
    level_id: 4,
    user_office_id: 0,
    office_name: 'System Administration',
  }
  authStore.isAuthenticated = true
  router.push('/')
}
</script>

<template>
  <div class="login-page-screen">
    <div class="login-shell">
      <!-- Left Brand Panel -->
      <section class="login-brand-panel">
        <div>
          <h1 class="login-brand-title">
            BSU Integrated Inventory<br />Monitoring System
          </h1>
        </div>

        <div class="login-feature-list">
          <div class="login-feature-item">
            Track product movement and stock levels with less clutter.
          </div>
          <div class="login-feature-item">
            Review dashboard alerts for low stock and expiring items.
          </div>
          <div class="login-feature-item">
            Manage products, reports, and settings in one consistent system.
          </div>
        </div>
      </section>

      <!-- Right Sign In Form Card -->
      <section class="login-card">
        <div class="login-card-content">
          <h2 class="login-card-title">Sign In</h2>
          <p class="login-card-subtitle">
            Access the dashboard and continue managing inventory.
          </p>

          <!-- Error Alert Banner -->
          <div v-if="errorMessage" class="login-error-banner">
            <span>⚠️ {{ errorMessage }}</span>
          </div>

          <form class="login-form-body" @submit.prevent="handleLogin">
            <!-- Username Input -->
            <div class="login-field-group">
              <label class="login-field-label">Username or Email</label>
              <input
                v-model="username"
                type="text"
                class="login-field-input"
                placeholder="Enter username or email"
                autocomplete="username"
                required
              />
            </div>

            <!-- Password Input with Toggle Eye Icon -->
            <div class="login-field-group">
              <label class="login-field-label">Password</label>
              <div class="login-password-wrapper">
                <input
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  class="login-field-input"
                  placeholder="Enter password"
                  autocomplete="current-password"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  @click="showPassword = !showPassword"
                  :title="showPassword ? 'Hide password' : 'Show password'"
                  aria-label="Toggle password visibility"
                >
                  <!-- Eye Icon SVG matching original design -->
                  <svg
                    v-if="!showPassword"
                    xmlns="http://www.w3.org/2000/svg"
                    width="19"
                    height="19"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                  <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    width="19"
                    height="19"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                    <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                    <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                    <line x1="2" x2="22" y1="2" y2="22" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Login Action Button -->
            <button
              type="submit"
              class="login-submit-button"
              :disabled="loading"
            >
              {{ loading ? 'Signing in...' : 'Login' }}
            </button>
          </form>

          <!-- Secondary Actions -->
          <div class="login-inline-actions">
            <router-link
              to="/register"
              class="login-secondary-pill"
            >
              Create a new account
            </router-link>
            <span class="login-actions-sep">·</span>
            <button
              type="button"
              class="login-secondary-pill"
              @click="showForgotModal = true"
            >
              Forgot Password?
            </button>
          </div>

          <!-- Quick Dev Access (Convenience) -->
          <div style="margin-top: 1.75rem; display: flex; flex-direction: column; gap: 8px; align-items: center;">
            <button
              type="button"
              class="login-demo-link"
              @click="handleQuickDemo"
            >
              ⚡ Quick Access: Unit Head (Inventory Dashboard)
            </button>
            <button
              type="button"
              class="login-demo-link"
              @click="handleAdminDemo"
              style="color: #0f766e; font-weight: 700;"
            >
              👑 Quick Access: Tech Staff / Admin (User Management Dashboard)
            </button>
          </div>
        </div>
      </section>
    </div>

    <!-- Floating Theme Toggle in Bottom-Right Corner (Matches original screenshot) -->
    <button
      type="button"
      class="login-floating-theme-toggle"
      @click="themeStore.cycleTheme()"
      :title="`Theme: ${themeStore.current}. Click to change.`"
      aria-label="Switch color theme"
    >
      <span v-if="themeStore.current === 'bsu'">🏛️</span>
      <span v-else-if="themeStore.current === 'dark'">🌙</span>
      <span v-else>☀️</span>
    </button>

    <!-- Modal for Create Account Info -->
    <div v-if="showRegisterModal" class="modal-backdrop" @click.self="showRegisterModal = false">
      <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
          <h3 style="font-size: 1.2rem; color: var(--text-main);">Create a New Account</h3>
          <button type="button" class="btn btn-sm btn-secondary" @click="showRegisterModal = false">✕</button>
        </div>
        <div class="modal-body" style="line-height: 1.6; color: var(--text-muted);">
          <p style="margin-bottom: 1rem;">
            User accounts for the <strong>BSU Integrated Inventory System</strong> are provisioned according to university office assignments.
          </p>
          <p style="margin-bottom: 1rem;">
            Please contact your department director, custodian head, or technical administrator to activate your official university account.
          </p>
          <div class="badge badge-info" style="width: 100%; padding: 0.75rem 1rem;">
            ℹ️ You can also use <strong>Quick Access</strong> below to explore the portal.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" @click="showRegisterModal = false">Got it</button>
        </div>
      </div>
    </div>

    <!-- Modal for Forgot Password Info -->
    <div v-if="showForgotModal" class="modal-backdrop" @click.self="showForgotModal = false">
      <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
          <h3 style="font-size: 1.2rem; color: var(--text-main);">Password Recovery</h3>
          <button type="button" class="btn btn-sm btn-secondary" @click="showForgotModal = false">✕</button>
        </div>
        <div class="modal-body" style="line-height: 1.6; color: var(--text-muted);">
          <p style="margin-bottom: 1rem;">
            To reset your inventory portal password, request a recovery code from your office administrator or university IT support.
          </p>
          <p>
            If SMTP is configured on the backend, password reset requests are verified via your registered university email address.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" @click="showForgotModal = false">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>
