<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { toast, errorMessage } from '../composables/useToast'

const router = useRouter()

const currentPassword = ref('')
const password = ref('')
const confirmPassword = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  if (password.value !== confirmPassword.value) {
    error.value = 'New Password and Confirm Password do not match.'
    return
  }
  loading.value = true
  try {
    const res = await authApi.changePassword(password.value, confirmPassword.value, currentPassword.value)
    toast(res.message || 'Password changed.')
    router.push('/')
  } catch (err) {
    error.value = errorMessage(err, 'Could not change the password.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Account</p>
        <h1 class="hero-title">Change Password</h1>
        <p class="hero-subtitle">Choose a new password for your account.</p>
      </div>
    </div>

    <form class="panel" style="padding: 1.5rem; max-width: 520px;" @submit.prevent="submit">
      <div v-if="error" class="badge badge-danger" style="display: flex; margin-bottom: 1rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;">⚠️ {{ error }}</div>
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input v-model="currentPassword" type="password" class="form-input" autocomplete="current-password" required />
      </div>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input v-model="password" type="password" class="form-input" autocomplete="new-password" required />
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <input v-model="confirmPassword" type="password" class="form-input" autocomplete="new-password" required />
      </div>
      <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
        At least 6 characters with an uppercase letter, a lowercase letter and a number, and no sequential numbers (e.g. 123).
      </p>
      <button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? 'Saving…' : 'Change Password' }}</button>
    </form>
  </div>
</template>
