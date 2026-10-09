<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { authApi } from '../api/auth'
import { toast, errorMessage } from '../composables/useToast'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { maskEmail } from '../utils/maskEmail'
import {
  User,
  Mail,
  Lock,
  KeyRound,
  Shield,
  Building2,
  Check,
  AlertTriangle,
  AlertCircle,
  Eye,
  EyeOff,
  Save,
  Clock,
  CheckCircle2,
  XCircle,
} from 'lucide-vue-next'

const authStore = useAuthStore()
const router = useRouter()

// Active tab
const activeTab = ref('profile') // 'profile' | 'security'

// The email field shows a masked address until it is clicked for editing
const emailFocused = ref(false)

// Profile Form State
const profileForm = ref({
  name: '',
  username: '',
  email: '',
})

// Password Form State
const passwordForm = ref({
  current_password: '',
  password: '',
  confirm_password: '',
})

const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

const loadingProfile = ref(false)
const loadingPassword = ref(false)
const profileError = ref('')
const profileSuccess = ref('')
const passwordError = ref('')
const passwordSuccess = ref('')

// Initialize profile values from current session
function initProfileFromStore() {
  const user = authStore.user
  if (user) {
    profileForm.value = {
      name: user.name || user.username || '',
      username: user.username || '',
      email: user.email || '',
    }
  }
}

// Password rules check (consistent with registration & backend criteria)
function hasSequentialNumbers(str) {
  for (let i = 0; i < str.length - 2; i++) {
    const a = str.charCodeAt(i)
    const b = str.charCodeAt(i + 1)
    const c = str.charCodeAt(i + 2)
    if (a >= 48 && a <= 57 && b >= 48 && b <= 57 && c >= 48 && c <= 57) {
      if (b === a + 1 && c === b + 1) return true
      if (b === a - 1 && c === b - 1) return true
    }
  }
  return false
}

const ruleLen = computed(() => passwordForm.value.password.length >= 6)
const ruleUpper = computed(() => /[A-Z]/.test(passwordForm.value.password))
const ruleLower = computed(() => /[a-z]/.test(passwordForm.value.password))
const ruleNum = computed(() => /[0-9]/.test(passwordForm.value.password))
const ruleSeq = computed(
  () => passwordForm.value.password.length > 0 && !hasSequentialNumbers(passwordForm.value.password)
)
const ruleMatch = computed(
  () =>
    passwordForm.value.password.length > 0 &&
    passwordForm.value.password === passwordForm.value.confirm_password
)

const isPasswordFormValid = computed(() => {
  return (
    passwordForm.value.current_password.length > 0 &&
    ruleLen.value &&
    ruleUpper.value &&
    ruleLower.value &&
    ruleNum.value &&
    ruleSeq.value &&
    ruleMatch.value
  )
})

// Save Profile Information
async function handleSaveProfile() {
  profileError.value = ''
  profileSuccess.value = ''

  if (!profileForm.value.username.trim()) {
    profileError.value = 'Username cannot be blank.'
    return
  }

  loadingProfile.value = true
  try {
    const res = await authApi.updateProfile({
      name: profileForm.value.name.trim(),
      username: profileForm.value.username.trim(),
      email: profileForm.value.email.trim(),
    })

    if (res.data?.user) {
      authStore.setSession(res.data.user)
    } else {
      await authStore.checkAuth()
    }

    profileSuccess.value = res.message || 'Profile information updated successfully.'
    toast(profileSuccess.value)
  } catch (err) {
    profileError.value = errorMessage(err, 'Failed to update profile information.')
  } finally {
    loadingProfile.value = false
  }
}

// Change Password
async function handleChangePassword() {
  passwordError.value = ''
  passwordSuccess.value = ''

  if (!passwordForm.value.current_password) {
    passwordError.value = 'Please enter your current password.'
    return
  }
  if (!passwordForm.value.password) {
    passwordError.value = 'Please enter a new password.'
    return
  }
  if (passwordForm.value.password !== passwordForm.value.confirm_password) {
    passwordError.value = 'New Password and Confirm Password do not match.'
    return
  }

  loadingPassword.value = true
  try {
    const res = await authApi.updateProfile({
      name: profileForm.value.name || authStore.user?.name || '',
      username: profileForm.value.username || authStore.user?.username || '',
      email: profileForm.value.email || authStore.user?.email || '',
      current_password: passwordForm.value.current_password,
      password: passwordForm.value.password,
      confirm_password: passwordForm.value.confirm_password,
    })

    passwordSuccess.value = res.message || 'Password changed successfully.'
    toast(passwordSuccess.value)

    // Reset password fields
    passwordForm.value = {
      current_password: '',
      password: '',
      confirm_password: '',
    }
  } catch (err) {
    passwordError.value = errorMessage(err, 'Could not change password.')
  } finally {
    loadingPassword.value = false
  }
}

onMounted(async () => {
  await authStore.ensureLoaded()
  initProfileFromStore()
})
</script>

<template>
  <div class="profile-page-container">
    <!-- Hero Banner -->
    <div class="page-hero profile-hero">
      <div class="hero-left">
        <p class="hero-eyebrow">Account Settings</p>
        <h1 class="hero-title">My Profile &amp; Security</h1>
        <p class="hero-subtitle">
          Manage your account information, personal details, and security credentials.
        </p>
      </div>

      <!-- User Identity Card Widget -->
      <div class="profile-summary-badge">
        <div class="profile-avatar-large">
          {{ (authStore.userName || 'U').charAt(0).toUpperCase() }}
        </div>
        <div class="profile-badge-info">
          <strong class="profile-badge-name">{{ authStore.user?.name || authStore.userName }}</strong>
          <span class="profile-badge-username">@{{ authStore.userName }}</span>
          <div class="profile-badge-tags">
            <span class="badge badge-success">{{ authStore.role || 'Staff' }}</span>
            <span class="badge badge-neutral">{{ authStore.officeName || 'BSU Inventory' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="profile-nav-tabs">
      <button
        type="button"
        class="profile-tab-btn"
        :class="{ 'is-active': activeTab === 'profile' }"
        @click="activeTab = 'profile'"
      >
        <User :size="16" />
        <span>Profile Details</span>
      </button>

      <button
        type="button"
        class="profile-tab-btn"
        :class="{ 'is-active': activeTab === 'security' }"
        @click="activeTab = 'security'"
      >
        <Lock :size="16" />
        <span>Password &amp; Security</span>
      </button>
    </div>

    <!-- Tab 1: Profile Details Form -->
    <div v-show="activeTab === 'profile'" class="profile-tab-content">
      <div class="profile-grid-layout">
        <!-- Main Form Panel -->
        <section class="panel profile-card">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title" style="display: flex; align-items: center; gap: 8px;">
                <User :size="18" style="color: var(--color-primary);" />
                <span>Personal Information</span>
              </h2>
              <p class="panel-subtitle">Update your display name, username, and email address.</p>
            </div>
          </div>

          <form @submit.prevent="handleSaveProfile" class="profile-form-body">
            <!-- Feedback Banners -->
            <div v-if="profileError" class="badge badge-danger form-feedback-alert">
              <AlertTriangle :size="16" style="flex-shrink: 0;" />
              <span>{{ profileError }}</span>
            </div>

            <div v-if="profileSuccess" class="badge badge-success form-feedback-alert">
              <Check :size="16" style="flex-shrink: 0;" />
              <span>{{ profileSuccess }}</span>
            </div>

            <div class="form-group">
              <label class="form-label" for="full_name">Full Name</label>
              <input
                id="full_name"
                v-model="profileForm.name"
                type="text"
                class="form-input"
                placeholder="e.g. Juan Dela Cruz"
                autocomplete="name"
                required
              />
              <small class="form-hint">Displayed across ledger entries, activity logs, and receipts.</small>
            </div>

            <div class="form-group">
              <label class="form-label" for="profile_username">Username</label>
              <input
                id="profile_username"
                v-model="profileForm.username"
                type="text"
                class="form-input"
                placeholder="e.g. jdelacruz"
                autocomplete="username"
                required
              />
              <small class="form-hint">Unique identifier used for signing in to the system.</small>
            </div>

            <div class="form-group">
              <label class="form-label" for="profile_email">Email Address</label>
              <input
                id="profile_email"
                :value="emailFocused ? profileForm.email : maskEmail(profileForm.email)"
                type="email"
                class="form-input"
                placeholder="e.g. jdelacruz@bsu.edu.ph"
                autocomplete="email"
                @input="profileForm.email = $event.target.value"
                @focus="emailFocused = true"
                @blur="emailFocused = false"
              />
              <small class="form-hint">Used for official system communications and account recovery.</small>
            </div>

            <div class="form-actions-bar">
              <button
                type="submit"
                class="btn btn-primary"
                style="display: inline-flex; align-items: center; gap: 8px;"
                :disabled="loadingProfile"
              >
                <Save :size="16" />
                <span>{{ loadingProfile ? 'Saving Changes…' : 'Save Profile Changes' }}</span>
              </button>
            </div>
          </form>
        </section>

        <!-- Read-Only Account Details Panel -->
        <aside class="panel profile-side-panel">
          <div class="panel-header">
            <h3 class="panel-title" style="font-size: 1.05rem; display: flex; align-items: center; gap: 8px;">
              <Shield :size="16" style="color: var(--color-primary);" />
              <span>Account Information</span>
            </h3>
          </div>

          <div class="side-info-list">
            <div class="side-info-item">
              <span class="side-info-label">Assigned Office / Unit</span>
              <div class="side-info-val">
                <Building2 :size="15" style="color: var(--color-primary); flex-shrink: 0;" />
                <strong>{{ authStore.officeName || 'BSU General Office' }}</strong>
              </div>
              <small class="side-info-note">Office assignments are managed by System Administrators.</small>
            </div>

            <div class="side-info-item">
              <span class="side-info-label">Role &amp; Privilege Level</span>
              <div class="side-info-val">
                <Shield :size="15" style="color: var(--color-accent); flex-shrink: 0;" />
                <strong>Level {{ authStore.levelId }} — {{ authStore.role }}</strong>
              </div>
              <small class="side-info-note">Determines inventory mutation and approval permissions.</small>
            </div>

            <div class="side-info-item">
              <span class="side-info-label">Account Status</span>
              <div class="side-info-val">
                <span class="status-indicator-dot"></span>
                <span style="font-weight: 700; color: var(--color-success);">Active &amp; Verified</span>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>

    <!-- Tab 2: Security & Password Form -->
    <div v-show="activeTab === 'security'" class="profile-tab-content">
      <div class="profile-grid-layout">
        <section class="panel profile-card">
          <div class="panel-header">
            <div class="panel-title-group">
              <h2 class="panel-title" style="display: flex; align-items: center; gap: 8px;">
                <Lock :size="18" style="color: var(--color-primary);" />
                <span>Change Account Password</span>
              </h2>
              <p class="panel-subtitle">
                Ensure your account is protected with a strong, secure passphrase.
              </p>
            </div>
          </div>

          <form @submit.prevent="handleChangePassword" class="profile-form-body">
            <!-- Feedback Banners -->
            <div v-if="passwordError" class="badge badge-danger form-feedback-alert">
              <AlertTriangle :size="16" style="flex-shrink: 0;" />
              <span>{{ passwordError }}</span>
            </div>

            <div v-if="passwordSuccess" class="badge badge-success form-feedback-alert">
              <Check :size="16" style="flex-shrink: 0;" />
              <span>{{ passwordSuccess }}</span>
            </div>

            <!-- Current Password -->
            <div class="form-group">
              <label class="form-label" for="current_pw">Current Password *</label>
              <div class="input-password-wrapper">
                <input
                  id="current_pw"
                  v-model="passwordForm.current_password"
                  :type="showCurrentPassword ? 'text' : 'password'"
                  class="form-input"
                  autocomplete="current-password"
                  placeholder="Enter your current password"
                  required
                />
                <button
                  type="button"
                  class="password-toggle-btn"
                  @click="showCurrentPassword = !showCurrentPassword"
                  :title="showCurrentPassword ? 'Hide password' : 'Show password'"
                  tabindex="-1"
                >
                  <component :is="showCurrentPassword ? EyeOff : Eye" :size="16" />
                </button>
              </div>
            </div>

            <!-- New Password -->
            <div class="form-group">
              <label class="form-label" for="new_pw">New Password *</label>
              <div class="input-password-wrapper">
                <input
                  id="new_pw"
                  v-model="passwordForm.password"
                  :type="showNewPassword ? 'text' : 'password'"
                  class="form-input"
                  autocomplete="new-password"
                  placeholder="Create a strong new password"
                  required
                />
                <button
                  type="button"
                  class="password-toggle-btn"
                  @click="showNewPassword = !showNewPassword"
                  :title="showNewPassword ? 'Hide password' : 'Show password'"
                  tabindex="-1"
                >
                  <component :is="showNewPassword ? EyeOff : Eye" :size="16" />
                </button>
              </div>
            </div>

            <!-- Confirm New Password -->
            <div class="form-group">
              <label class="form-label" for="confirm_pw">Confirm New Password *</label>
              <div class="input-password-wrapper">
                <input
                  id="confirm_pw"
                  v-model="passwordForm.confirm_password"
                  :type="showConfirmPassword ? 'text' : 'password'"
                  class="form-input"
                  autocomplete="new-password"
                  placeholder="Re-type your new password"
                  :class="matchClass(passwordForm.password, passwordForm.confirm_password)"
                  required
                />
                <button
                  type="button"
                  class="password-toggle-btn"
                  @click="showConfirmPassword = !showConfirmPassword"
                  :title="showConfirmPassword ? 'Hide password' : 'Show password'"
                  tabindex="-1"
                >
                  <component :is="showConfirmPassword ? EyeOff : Eye" :size="16" />
                </button>
              </div>
              <PasswordMatchHint :password="passwordForm.password" :confirm="passwordForm.confirm_password" />
            </div>

            <div class="form-actions-bar">
              <button
                type="submit"
                class="btn btn-primary"
                style="display: inline-flex; align-items: center; gap: 8px;"
                :disabled="loadingPassword || !isPasswordFormValid"
              >
                <KeyRound :size="16" />
                <span>{{ loadingPassword ? 'Updating Password…' : 'Update Password' }}</span>
              </button>
            </div>
          </form>
        </section>

        <!-- Password Checklist Helper Card -->
        <aside class="panel profile-side-panel">
          <div class="panel-header">
            <h3 class="panel-title" style="font-size: 1.05rem; display: flex; align-items: center; gap: 8px;">
              <KeyRound :size="16" style="color: var(--color-primary);" />
              <span>Password Security Rules</span>
            </h3>
          </div>

          <div class="side-info-list">
            <p style="font-size: 0.825rem; color: var(--text-muted); line-height: 1.4; margin-bottom: 0.5rem;">
              Your new password must meet all of the following requirements:
            </p>

            <ul class="password-rules-list">
              <li :class="{ 'is-met': ruleLen }">
                <component :is="ruleLen ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>At least 6 characters long</span>
              </li>
              <li :class="{ 'is-met': ruleUpper }">
                <component :is="ruleUpper ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>At least one uppercase letter (A-Z)</span>
              </li>
              <li :class="{ 'is-met': ruleLower }">
                <component :is="ruleLower ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>At least one lowercase letter (a-z)</span>
              </li>
              <li :class="{ 'is-met': ruleNum }">
                <component :is="ruleNum ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>At least one number (0-9)</span>
              </li>
              <li :class="{ 'is-met': ruleSeq }">
                <component :is="ruleSeq ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>No sequential numbers (e.g., 123, 789)</span>
              </li>
              <li :class="{ 'is-met': ruleMatch }">
                <component :is="ruleMatch ? CheckCircle2 : XCircle" :size="15" class="rule-icon" />
                <span>Passwords match</span>
              </li>
            </ul>
          </div>
        </aside>
      </div>
    </div>
  </div>
</template>

<style scoped>
.profile-page-container {
  max-width: 1100px;
  margin: 0 auto;
}

.profile-hero {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1.5rem;
  margin-bottom: 1.5rem;
}

.hero-left {
  flex: 1;
  min-width: 280px;
}

/* User Identity Widget */
.profile-summary-badge {
  display: flex;
  align-items: center;
  gap: 1rem;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg, 18px);
  padding: 1rem 1.4rem;
  box-shadow: var(--shadow-sm);
  flex-shrink: 0;
}

.profile-avatar-large {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--color-accent, #e6d628);
  color: #12200f;
  font-family: var(--font-display);
  font-size: 1.5rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.16);
  flex-shrink: 0;
}

.profile-badge-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.profile-badge-name {
  font-family: var(--font-display);
  font-size: 1.1rem;
  color: var(--text-main);
  line-height: 1.2;
}

.profile-badge-username {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.profile-badge-tags {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
}

/* Tab Navigation */
.profile-nav-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 1.5rem;
  border-bottom: 1px solid var(--border-subtle);
  padding-bottom: 4px;
}

.profile-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 12px;
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-muted);
  font-size: 0.9rem;
  font-weight: 700;
  cursor: pointer;
  transition: all var(--transition-fast, 0.2s);
}

.profile-tab-btn:hover {
  background: var(--bg-subtle);
  color: var(--text-main);
}

.profile-tab-btn.is-active {
  background: var(--color-primary-light);
  border-color: var(--color-primary);
  color: var(--color-primary);
}

/* Tab Content Layout */
.profile-grid-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 1.5rem;
  align-items: start;
}

@media (max-width: 900px) {
  .profile-grid-layout {
    grid-template-columns: 1fr;
  }
}

.profile-card {
  margin-bottom: 0;
}

.profile-form-body {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.form-feedback-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0.75rem 1rem;
  width: 100%;
  border-radius: 10px;
  font-size: 0.85rem;
  white-space: normal;
}

.form-hint {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
  margin-top: 4px;
}

.form-actions-bar {
  display: flex;
  justify-content: flex-start;
  padding-top: 0.5rem;
}

/* Password eye toggle */
.input-password-wrapper {
  position: relative;
  display: flex;
  align-items: center;
}

.input-password-wrapper .form-input {
  padding-right: 44px;
}

.password-toggle-btn {
  position: absolute;
  right: 10px;
  background: transparent;
  border: none;
  color: var(--text-muted);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  border-radius: 6px;
  transition: color 0.15s;
}

.password-toggle-btn:hover {
  color: var(--text-main);
}

/* Side Panel */
.profile-side-panel {
  margin-bottom: 0;
}

.side-info-list {
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.side-info-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.side-info-label {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--text-muted);
}

.side-info-val {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.95rem;
  color: var(--text-main);
}

.side-info-note {
  font-size: 0.75rem;
  color: var(--text-subtle);
  line-height: 1.35;
}

.status-indicator-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-success);
  box-shadow: 0 0 6px rgba(34, 197, 94, 0.6);
}

/* Password rules list */
.password-rules-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.password-rules-list li {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.825rem;
  color: var(--text-muted);
  transition: color 0.2s ease;
}

.password-rules-list li .rule-icon {
  color: var(--color-danger, #ef4444);
  flex-shrink: 0;
}

.password-rules-list li.is-met {
  color: var(--text-main);
  font-weight: 600;
}

.password-rules-list li.is-met .rule-icon {
  color: var(--color-success, #16a34a);
}
</style>
