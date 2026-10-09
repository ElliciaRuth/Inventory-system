<script setup>
import { ref, computed } from 'vue'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useAuthStore } from '../stores/authStore'
import { AlertTriangle } from 'lucide-vue-next'

// Forced first-login steps, driven by authStore.pendingSetup:
//   change_password → (Technical Staff only) setup_smtp → setup_recovery_email
const router = useRouter()
const authStore = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const password = ref('')
const confirmPassword = ref('')
const smtpEmail = ref('')
const smtpPassword = ref('')
const recoveryEmail = ref('')

const step = computed(() => authStore.pendingSetup)

const copy = {
  change_password: {
    title: 'Change Your Password',
    subtitle: 'For security, please set a new password before continuing.',
  },
  setup_smtp: {
    title: 'Configure Recovery Email Sender',
    subtitle: 'Set the Gmail account (with an app password) used to send password reset codes.',
  },
  setup_recovery_email: {
    title: 'Set Your Recovery Email',
    subtitle: 'This address receives your own password reset codes.',
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
    let res
    if (step.value === 'change_password') {
      res = await authApi.changePassword(password.value, confirmPassword.value)
    } else if (step.value === 'setup_smtp') {
      res = await authApi.setupSmtp(smtpEmail.value, smtpPassword.value)
    } else {
      res = await authApi.setupRecoveryEmail(recoveryEmail.value)
    }

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
    <div class="login-shell">
      <section class="login-card" style="margin: 0 auto;">
        <div class="login-card-content">
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
                At least 6 characters with an uppercase letter, a lowercase letter and a number,
                and no sequential numbers (e.g. 123).
              </p>
            </template>

            <template v-else-if="step === 'setup_smtp'">
              <div class="login-field-group">
                <label class="login-field-label">Gmail Address</label>
                <input v-model="smtpEmail" type="email" class="login-field-input" required />
              </div>
              <div class="login-field-group">
                <label class="login-field-label">App Password</label>
                <input
                  v-model="smtpPassword"
                  type="password"
                  class="login-field-input"
                  autocomplete="off"
                  minlength="8"
                  required
                />
              </div>
            </template>

            <template v-else-if="step === 'setup_recovery_email'">
              <div class="login-field-group">
                <label class="login-field-label">Recovery Email</label>
                <input v-model="recoveryEmail" type="email" class="login-field-input" required />
              </div>
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
