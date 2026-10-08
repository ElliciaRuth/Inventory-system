<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { toast, errorMessage } from '../composables/useToast'
import AuthLogoHeader from '../components/AuthLogoHeader.vue'
import { AlertTriangle } from 'lucide-vue-next'

// Step 1: request a 6-digit code by email. Step 2: enter it with a new password.
const router = useRouter()

const step = ref(1)
const email = ref('')
const code = ref('')
const password = ref('')
const confirmPassword = ref('')
const loading = ref(false)
const error = ref('')
const info = ref('')

async function requestCode() {
  error.value = ''
  loading.value = true
  try {
    const res = await authApi.forgotPassword(email.value.trim())
    info.value = res.message
    step.value = 2
  } catch (err) {
    error.value = errorMessage(err, 'Could not send the reset code.')
  } finally {
    loading.value = false
  }
}

async function resetPassword() {
  error.value = ''
  if (password.value !== confirmPassword.value) {
    error.value = 'Password and Confirm Password do not match.'
    return
  }
  loading.value = true
  try {
    const res = await authApi.resetPassword({
      email: email.value.trim(),
      code: code.value.trim(),
      password: password.value,
      confirm_password: confirmPassword.value,
    })
    toast(res.message || 'Password reset. You can now log in.')
    router.push('/login')
  } catch (err) {
    error.value = errorMessage(err, 'Could not reset the password.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page-screen">
    <div class="login-shell">
      <section class="login-card" style="margin: 0 auto;">
        <div class="login-card-content">
          <!-- Institution & Department Branding Logos -->
          <AuthLogoHeader />

          <h2 class="login-card-title">{{ step === 1 ? 'Forgot Password' : 'Reset Password' }}</h2>
          <p class="login-card-subtitle">
            {{ step === 1
              ? 'Enter the email address on your account and we will send you a 6-digit code.'
              : 'Enter the code from your email and choose a new password. The code expires in 15 minutes.' }}
          </p>

          <div v-if="error" class="login-error-banner"><span><AlertTriangle :size="15" /> {{ error }}</span></div>
          <div v-if="info && step === 2 && !error" class="login-success-banner"><span>{{ info }}</span></div>

          <form v-if="step === 1" class="login-form-body" @submit.prevent="requestCode">
            <div class="login-field-group">
              <label class="login-field-label">Email Address</label>
              <input v-model="email" type="email" class="login-field-input" autocomplete="email" required />
            </div>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Sending…' : 'Send Code' }}
            </button>
          </form>

          <form v-else class="login-form-body" @submit.prevent="resetPassword">
            <div class="login-field-group">
              <label class="login-field-label">6-Digit Code</label>
              <input v-model="code" type="text" inputmode="numeric" maxlength="6" pattern="\d{6}" class="login-field-input" autocomplete="one-time-code" required />
            </div>
            <div class="login-field-group">
              <label class="login-field-label">New Password</label>
              <input v-model="password" type="password" class="login-field-input" autocomplete="new-password" required />
            </div>
            <div class="login-field-group">
              <label class="login-field-label">Confirm Password</label>
              <input v-model="confirmPassword" type="password" class="login-field-input" autocomplete="new-password" required />
            </div>
            <p class="login-card-subtitle" style="font-size: 0.8rem;">
              At least 6 characters with an uppercase letter, a lowercase letter and a number, and no sequential numbers (e.g. 123).
            </p>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Saving…' : 'Reset Password' }}
            </button>
          </form>

          <div class="login-inline-actions">
            <button v-if="step === 2" type="button" class="login-secondary-pill" @click="step = 1">Send a new code</button>
            <span v-if="step === 2" class="login-actions-sep">·</span>
            <router-link to="/login" class="login-secondary-pill">Back to login</router-link>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
