<script setup>
import { ref, computed } from 'vue'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useAuthStore } from '../stores/authStore'
import { confirmDialog } from '../composables/useConfirm'
import { AlertTriangle, Eye, EyeOff } from 'lucide-vue-next'
import AuthLogoHeader from '../components/AuthLogoHeader.vue'

/**
 * Forced first-login steps, driven by authStore.pendingSetup:
 *   1. change_password      — all users
 *   2. setup_smtp           — admin_tech (level 4) only
 *   3. setup_recovery_email — all users (two sub-steps: request + confirm)
 */
const router = useRouter()
const authStore = useAuthStore()

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// ── change_password ───────────────────────────────────────────────────────────
const password = ref('')
const confirmPassword = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)

// ── setup_smtp ────────────────────────────────────────────────────────────────
const smtpEmail = ref('')
const smtpPassword = ref('')
const showSmtpPassword = ref(false)
const showSmtpHelp = ref(false)

// ── setup_recovery_email ──────────────────────────────────────────────────────
// sub-step: 'enter_email' | 'enter_code'
const recoverySubStep = ref('enter_email')
const recoveryEmail = ref('')
const recoveryCode = ref('')
const recoveryCodeComplete = computed(() => /^\d{6}$/.test(recoveryCode.value))

function onRecoveryCodeInput(e) {
  recoveryCode.value = e.target.value.replace(/\D/g, '').slice(0, 6)
  e.target.value = recoveryCode.value
}

// ─────────────────────────────────────────────────────────────────────────────

const step = computed(() => authStore.pendingSetup)

const copy = {
  change_password: {
    title: 'Change Your Password',
    subtitle: 'For security, please set a new password before continuing.',
  },
  setup_smtp: {
    title: 'Configure System Email',
    subtitle:
      'Set the Gmail account that will send password-reset codes to all users. ' +
      'A confirmation email is sent to verify the credentials before saving.',
  },
  setup_recovery_email: {
    title: 'Set Your Recovery Email',
    subtitle:
      'Add a recovery email address. Password reset codes will be sent here instead of your main account email.',
  },
}

// ── Handlers ─────────────────────────────────────────────────────────────────

async function submitChangePassword() {
  if (password.value !== confirmPassword.value) {
    errorMessage.value = 'Password and Confirm Password do not match.'
    return
  }
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await authApi.changePassword(password.value, confirmPassword.value)
    if (res.data?.user) authStore.user = res.data.user
    authStore.pendingSetup = res.data?.pending_setup ?? null
    successMessage.value = res.message
    if (!authStore.pendingSetup) router.push('/')
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

async function submitSetupSmtp() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await authApi.setupSmtp(smtpEmail.value.trim(), smtpPassword.value)
    authStore.pendingSetup = res.data?.pending_setup ?? null
    successMessage.value = res.message
    smtpPassword.value = ''
    if (!authStore.pendingSetup) router.push('/')
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

async function submitRequestRecoveryEmail() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await authApi.requestRecoveryEmail(recoveryEmail.value.trim())
    successMessage.value = res.message
    recoverySubStep.value = 'enter_code'
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

async function submitConfirmRecoveryEmail() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const res = await authApi.confirmRecoveryEmail(recoveryCode.value)
    authStore.pendingSetup = res.data?.pending_setup ?? null
    successMessage.value = res.message
    if (!authStore.pendingSetup) router.push('/')
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

function resendRecoveryCode() {
  recoverySubStep.value = 'enter_email'
  recoveryCode.value = ''
  errorMessage.value = ''
  successMessage.value = ''
}

async function handleLogout() {
  const ok = await confirmDialog({
    title: 'Sign out?',
    message: 'You will need to sign in again and finish this step.',
    details: [['Signed in as', authStore.user?.username]],
    confirmText: 'Sign out',
  })
  if (!ok) return
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

          <!-- ── Step: change_password ───────────────────────────────────── -->
          <form v-if="step === 'change_password'" class="login-form-body" @submit.prevent="submitChangePassword">
            <div class="login-field-group">
              <label class="login-field-label">New Password</label>
              <div class="login-password-wrapper">
                <input
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
                  @click="showPassword = !showPassword"
                >
                  <component :is="showPassword ? EyeOff : Eye" :size="19" />
                </button>
              </div>
            </div>
            <div class="login-field-group">
              <label class="login-field-label">Confirm Password</label>
              <div class="login-password-wrapper">
                <input
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
                  @click="showConfirmPassword = !showConfirmPassword"
                >
                  <component :is="showConfirmPassword ? EyeOff : Eye" :size="19" />
                </button>
              </div>
              <PasswordMatchHint :password="password" :confirm="confirmPassword" />
            </div>
            <p class="login-card-subtitle" style="font-size: 0.8rem;">
              At least 8 characters with an uppercase letter, a lowercase letter and a number,
              and no sequential numbers (e.g. 123).
            </p>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Saving…' : 'Continue' }}
            </button>
          </form>

          <!-- ── Step: setup_smtp ───────────────────────────────────────── -->
          <form v-else-if="step === 'setup_smtp'" class="login-form-body" @submit.prevent="submitSetupSmtp">
            <div class="login-field-group">
              <label class="login-field-label" for="smtp_email">Gmail / BSU Account Address</label>
              <input
                id="smtp_email"
                v-model="smtpEmail"
                type="email"
                class="login-field-input"
                autocomplete="email"
                placeholder="e.g. yourname@bsu.edu.ph"
                required
              />
            </div>
            <div class="login-field-group">
              <label class="login-field-label" for="smtp_pass">Gmail App Password</label>
              <div class="login-password-wrapper">
                <input
                  id="smtp_pass"
                  v-model="smtpPassword"
                  :type="showSmtpPassword ? 'text' : 'password'"
                  class="login-field-input"
                  autocomplete="off"
                  autocapitalize="off"
                  spellcheck="false"
                  placeholder="e.g. abcd efgh ijkl mnop"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  :title="showSmtpPassword ? 'Hide app password' : 'Show app password'"
                  @click="showSmtpPassword = !showSmtpPassword"
                >
                  <component :is="showSmtpPassword ? EyeOff : Eye" :size="19" />
                </button>
              </div>
              <p class="setup-hint">
                This is <strong>not</strong> your normal email password. It is a 16-character
                app password generated in your Google Account settings.
                <button type="button" class="setup-help-toggle" @click="showSmtpHelp = !showSmtpHelp">
                  {{ showSmtpHelp ? 'Hide instructions' : 'How do I create one?' }}
                </button>
              </p>
              <div v-if="showSmtpHelp" class="setup-help-box">
                <strong>For a Gmail or BSU (Google Workspace) account:</strong><br />
                Open your Google Account → Security → enable 2-Step Verification → App passwords →
                create a new one (choose any name, e.g. "BSU Inventory"), copy the 16-letter code,
                and paste it here. You can delete the app password from the same page at any time.
              </div>
            </div>
            <p class="setup-hint" style="margin-top: 0;">
              A test email is sent to this address to confirm the credentials. If it succeeds,
              the app password is encrypted and stored; it is never visible again.
            </p>
            <button type="submit" class="login-submit-button" :disabled="loading">
              {{ loading ? 'Verifying & Saving…' : 'Save System Email' }}
            </button>
          </form>

          <!-- ── Step: setup_recovery_email ────────────────────────────── -->
          <template v-else-if="step === 'setup_recovery_email'">
            <!-- Sub-step A: enter the recovery address -->
            <form v-if="recoverySubStep === 'enter_email'" class="login-form-body" @submit.prevent="submitRequestRecoveryEmail">
              <div class="login-field-group">
                <label class="login-field-label" for="recovery_email">Recovery Email Address</label>
                <input
                  id="recovery_email"
                  v-model="recoveryEmail"
                  type="email"
                  class="login-field-input"
                  autocomplete="email"
                  placeholder="A personal email you always have access to"
                  required
                />
              </div>
              <p class="setup-hint">
                Password reset codes will be sent to this address. Use a personal email
                that is different from your institutional account.
              </p>
              <button type="submit" class="login-submit-button" :disabled="loading">
                {{ loading ? 'Sending code…' : 'Send Verification Code' }}
              </button>
            </form>

            <!-- Sub-step B: enter the OTP sent to recovery address -->
            <form v-else class="login-form-body" @submit.prevent="submitConfirmRecoveryEmail">
              <p class="setup-sent-to">
                A 6-digit code was sent to <strong>{{ recoveryEmail }}</strong>. Enter it below.
              </p>
              <div class="login-field-group">
                <label class="login-field-label" for="recovery_code">Verification Code</label>
                <input
                  id="recovery_code"
                  :value="recoveryCode"
                  type="text"
                  inputmode="numeric"
                  maxlength="6"
                  class="login-field-input setup-code-input"
                  placeholder="••••••"
                  autocomplete="one-time-code"
                  autofocus
                  required
                  @input="onRecoveryCodeInput"
                />
              </div>
              <button type="submit" class="login-submit-button" :disabled="loading || !recoveryCodeComplete">
                {{ loading ? 'Verifying…' : 'Confirm Recovery Email' }}
              </button>
              <div class="login-inline-actions" style="margin-top: 8px;">
                <button type="button" class="login-secondary-pill" :disabled="loading" @click="resendRecoveryCode">
                  Use a different address
                </button>
              </div>
            </form>
          </template>

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

.setup-hint {
  margin: 6px 0 0;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--text-muted);
}

.setup-help-toggle {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-primary);
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
}

.setup-help-box {
  margin-top: 8px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--text-muted);
}

.setup-sent-to {
  margin: 0 0 8px;
  font-size: 13.5px;
  color: var(--text-muted);
  overflow-wrap: anywhere;
}

.setup-code-input {
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.5em;
  padding-left: calc(16px + 0.5em);
}
</style>
