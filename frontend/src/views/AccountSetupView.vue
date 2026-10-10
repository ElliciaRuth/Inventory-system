<script setup>
import { ref, computed } from 'vue'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useAuthStore } from '../stores/authStore'
import { AlertTriangle } from 'lucide-vue-next'
import AuthLogoHeader from '../components/AuthLogoHeader.vue'

// Forced first-login step, driven by authStore.pendingSetup: change_password
const router = useRouter()
const authStore = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const password = ref('')
const confirmPassword = ref('')

const step = computed(() => authStore.pendingSetup)

const copy = {
  change_password: {
    title: 'Change Your Password',
    subtitle: 'For security, please set a new password before continuing.',
  },
}

async function submit() {
  if (loading.value) return
  errorMessage.value = ''
  successMessage.value = ''

  if (step.value === 'change_password' && password.value !== confirmPassword.value) {
    errorMessage.value = 'Password and Confirm Password do not match.'
    return
  }

  loading.value = true
  try {
    const res = await authApi.changePassword(password.value, confirmPassword.value)

    if (res.data?.user) {
      authStore.user = res.data.user
    }
    authStore.pendingSetup = res.data?.pending_setup ?? null
    successMessage.value = res.message

    if (!authStore.pendingSetup) {
      router.push('/')
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="login-page-screen">
    <div class="setup-shell">
      <section class="login-card">
        <div class="login-card-content">
          <AuthLogoHeader />
          <h2 class="login-card-title">{{ copy[step]?.title || 'Account Setup' }}</h2>
          <p class="login-card-subtitle">{{ copy[step]?.subtitle }}</p>

          <div v-if="errorMessage" class="login-error-banner">
            <span><AlertTriangle :size="15" /> {{ errorMessage }}</span>
          </div>
          <div v-if="successMessage" class="login-success-banner">
            <span>{{ successMessage }}</span>
          </div>

          <form class="login-form-body" @submit.prevent="submit">
            <template v-if="step === 'change_password'">
              <div class="login-field-group">
                <label class="login-field-label">New Password</label>
                <input
                  v-model="password"
                  type="password"
                  class="login-field-input"
                  autocomplete="new-password"
                  required
                />
              </div>
              <div class="login-field-group">
                <label class="login-field-label">Confirm Password</label>
                <input
                  v-model="confirmPassword"
                  type="password"
                  class="login-field-input"
                  :class="matchClass(password, confirmPassword)"
                  autocomplete="new-password"
                  required
                />
                <PasswordMatchHint :password="password" :confirm="confirmPassword" />
              </div>
              <p class="login-card-subtitle" style="font-size: 0.8rem;">
                At least 8 characters with an uppercase letter, a lowercase letter and a number,
                and no sequential numbers (e.g. 123).
              </p>
            </template>

            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Saving...' : 'Continue' }}
            </button>
          </form>

          <div class="login-inline-actions">
            <button type="button" class="login-secondary-pill" @click="handleLogout">
              Log out
            </button>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* One centered card (the login page's .login-shell is a two-column grid) */
.setup-shell {
  width: min(520px, 100%);
  margin: 0 auto;
}
</style>
