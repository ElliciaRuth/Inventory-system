<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { authApi } from '../api/auth'
import { toast, errorMessage } from '../composables/useToast'
import AuthLogoHeader from '../components/AuthLogoHeader.vue'
import { AlertTriangle, Eye, EyeOff } from 'lucide-vue-next'

// Step 1: email + the app password of that email account → the code is sent from the
//         user's own account to itself (the server never stores the app password).
// Step 2: enter the 6-digit code; it is verified before moving on.
// Step 3: choose the new password.
const router = useRouter()

const STEPS = ['Email', 'Code', 'New password']
const step = ref(1)
const email = ref('')
// Lives only in this field until it is sent; cleared on submit, never stored anywhere
const appKey = ref('')
const showAppKey = ref(false)
const showAppKeyHelp = ref(false)
const code = ref('')
const password = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)
const loading = ref(false)
const error = ref('')
const info = ref('')

const TITLES = { 1: 'Forgot Password', 2: 'Enter Verification Code', 3: 'Choose a New Password' }
const SUBTITLES = {
  1: 'Enter the email address on your account. A 6-digit code is sent to it from your own email account.',
  2: 'Enter the 6-digit code from the email. It expires in 15 minutes.',
  3: 'Your code is verified. Choose a new password for your account.',
}

const codeComplete = computed(() => /^\d{6}$/.test(code.value))

async function requestCode() {
  error.value = ''
  info.value = ''
  // Take the key out of the form before sending: it is never shown again
  const key = appKey.value.trim()
  appKey.value = ''
  showAppKey.value = false
  loading.value = true
  try {
    const res = await authApi.forgotPassword(email.value.trim(), key)
    info.value = res.message
    code.value = ''
    step.value = 2
  } catch (err) {
    error.value = errorMessage(err, 'Could not send the reset code.')
  } finally {
    loading.value = false
  }
}

// A new code needs the app password again, so go back to the first step (email kept)
function requestNewCode() {
  step.value = 1
  error.value = ''
  info.value = ''
  code.value = ''
}

async function verifyCode() {
  error.value = ''
  if (!codeComplete.value) {
    error.value = 'Please enter the 6-digit code from your email.'
    return
  }
  loading.value = true
  try {
    await authApi.verifyResetCode(email.value.trim(), code.value)
    info.value = ''
    step.value = 3
  } catch (err) {
    error.value = errorMessage(err, 'Could not verify the code.')
    // The backend cancels the code after too many wrong tries
    if (/request a new code/i.test(error.value)) code.value = ''
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
      code: code.value,
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

// Keep only digits; paste of "123 456" still works
function onCodeInput(e) {
  code.value = e.target.value.replace(/\D/g, '').slice(0, 6)
  e.target.value = code.value
}

function startOver() {
  step.value = 1
  error.value = ''
  info.value = ''
  code.value = ''
  password.value = ''
  confirmPassword.value = ''
}
</script>

<template>
  <div class="login-page-screen">
    <div class="fp-shell">
      <section class="login-card">
        <div class="login-card-content">
          <AuthLogoHeader />

          <!-- Step indicator -->
          <ol class="fp-steps" aria-label="Progress">
            <li
              v-for="(label, i) in STEPS"
              :key="label"
              :class="{ 'is-done': step > i + 1, 'is-current': step === i + 1 }"
              :aria-current="step === i + 1 ? 'step' : undefined"
            >
              <span class="fp-step-dot">{{ step > i + 1 ? '✓' : i + 1 }}</span>
              <span class="fp-step-label">{{ label }}</span>
            </li>
          </ol>

          <h2 class="login-card-title">{{ TITLES[step] }}</h2>
          <p class="login-card-subtitle">{{ SUBTITLES[step] }}</p>

          <div v-if="error" class="login-error-banner"><span><AlertTriangle :size="15" /> {{ error }}</span></div>
          <div v-else-if="info && step === 2" class="login-success-banner"><span>{{ info }}</span></div>

          <!-- Step 1: email -->
          <form v-if="step === 1" class="login-form-body" @submit.prevent="requestCode">
            <div class="login-field-group">
              <label class="login-field-label" for="fp_email">Email Address</label>
              <input id="fp_email" v-model="email" type="email" class="login-field-input" autocomplete="email" required />
            </div>
            <div class="login-field-group">
              <label class="login-field-label" for="fp_app_key">Email App Password</label>
              <div class="login-password-wrapper">
                <input
                  id="fp_app_key"
                  v-model="appKey"
                  :type="showAppKey ? 'text' : 'password'"
                  class="login-field-input"
                  autocomplete="off"
                  autocapitalize="off"
                  spellcheck="false"
                  placeholder="e.g. abcd efgh ijkl mnop"
                  aria-describedby="fp_app_key_hint"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  :title="showAppKey ? 'Hide app password' : 'Show app password'"
                  :aria-label="showAppKey ? 'Hide app password' : 'Show app password'"
                  @click="showAppKey = !showAppKey"
                >
                  <component :is="showAppKey ? EyeOff : Eye" :size="19" />
                </button>
              </div>
              <p id="fp_app_key_hint" class="fp-hint">
                Used once to send the reset email from your own email account to itself. It is
                not your normal email password, and it is not saved anywhere.
                <button type="button" class="fp-help-toggle" @click="showAppKeyHelp = !showAppKeyHelp">
                  {{ showAppKeyHelp ? 'Hide help' : 'How do I get one?' }}
                </button>
              </p>
              <div v-if="showAppKeyHelp" class="fp-help">
                <strong>Gmail or a BSU (Google) account:</strong> open your Google Account →
                Security → turn on 2-Step Verification → App passwords → create one (any name),
                and copy the 16 letters here. You can delete it in the same place afterwards.
              </div>
            </div>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Sending…' : 'Send Code' }}
            </button>
          </form>

          <!-- Step 2: code -->
          <form v-else-if="step === 2" class="login-form-body" @submit.prevent="verifyCode">
            <p class="fp-sent-to">If an account uses <strong>{{ email }}</strong>, the code was sent there.</p>
            <div class="login-field-group">
              <label class="login-field-label" for="fp_code">6-Digit Code</label>
              <input
                id="fp_code"
                :value="code"
                type="text"
                inputmode="numeric"
                maxlength="6"
                class="login-field-input fp-code-input"
                placeholder="••••••"
                autocomplete="one-time-code"
                autofocus
                required
                @input="onCodeInput"
              />
            </div>
            <button type="submit" class="login-submit-button" :disabled="loading || !codeComplete">
              {{ loading ? 'Verifying…' : 'Verify Code' }}
            </button>
          </form>

          <!-- Step 3: new password -->
          <form v-else class="login-form-body" @submit.prevent="resetPassword">
            <div class="login-field-group">
              <label class="login-field-label" for="fp_password">New Password</label>
              <div class="login-password-wrapper">
                <input
                  id="fp_password"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  class="login-field-input"
                  autocomplete="new-password"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  :title="showPassword ? 'Hide password' : 'Show password'"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  @click="showPassword = !showPassword"
                >
                  <component :is="showPassword ? EyeOff : Eye" :size="19" />
                </button>
              </div>
            </div>
            <div class="login-field-group">
              <label class="login-field-label" for="fp_confirm">Confirm Password</label>
              <div class="login-password-wrapper">
                <input
                  id="fp_confirm"
                  v-model="confirmPassword"
                  :type="showConfirmPassword ? 'text' : 'password'"
                  class="login-field-input"
                  :class="matchClass(password, confirmPassword)"
                  autocomplete="new-password"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  :title="showConfirmPassword ? 'Hide password' : 'Show password'"
                  :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                  @click="showConfirmPassword = !showConfirmPassword"
                >
                  <component :is="showConfirmPassword ? EyeOff : Eye" :size="19" />
                </button>
              </div>
              <PasswordMatchHint :password="password" :confirm="confirmPassword" />
            </div>
            <p class="login-card-subtitle" style="font-size: 0.8rem;">
              At least 8 characters with an uppercase letter, a lowercase letter and a number, and no sequential numbers (e.g. 123).
            </p>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Saving…' : 'Reset Password' }}
            </button>
          </form>

          <div class="login-inline-actions">
            <template v-if="step === 2">
              <button type="button" class="login-secondary-pill" :disabled="loading" @click="requestNewCode">Send a new code</button>
              <span class="login-actions-sep">·</span>
              <button type="button" class="login-secondary-pill" @click="startOver">Use a different email</button>
              <span class="login-actions-sep">·</span>
            </template>
            <router-link to="/login" class="login-secondary-pill">Back to login</router-link>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* Single centered card (the login page's .login-shell is a two-column grid) */
.fp-shell {
  width: min(520px, 100%);
  margin: 0 auto;
}

.fp-steps {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin: 0 0 22px;
  padding: 0;
  list-style: none;
}

.fp-steps li {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #94a3b8;
}

.fp-steps li + li::before {
  content: '';
  width: 22px;
  height: 2px;
  margin-right: 2px;
  border-radius: 2px;
  background: currentColor;
  opacity: 0.4;
}

.fp-step-dot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid currentColor;
  font-size: 11px;
  font-weight: 700;
}

.fp-steps li.is-current,
.fp-steps li.is-done {
  color: var(--color-primary);
}

.fp-steps li.is-current .fp-step-dot,
.fp-steps li.is-done .fp-step-dot {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}

.fp-sent-to {
  margin: 0 0 4px;
  font-size: 13.5px;
  color: var(--text-muted);
  overflow-wrap: anywhere;
}

.fp-hint {
  margin: 6px 0 0;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--text-muted);
}

.fp-help-toggle {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
}

.fp-help {
  margin-top: 8px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--text-muted);
}

.fp-code-input {
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.5em;
  padding-left: calc(16px + 0.5em);
}

.login-secondary-pill:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 420px) {
  .fp-step-label {
    display: none;
  }
}
</style>
