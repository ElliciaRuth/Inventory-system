<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { authApi } from '../api/auth'
import { useThemeStore } from '../stores/themeStore'
import AuthLogoHeader from '../components/AuthLogoHeader.vue'
import PasswordMatchHint, { matchClass } from '../components/PasswordMatchHint.vue'
import { AlertTriangle, CheckCircle2, Palette, Moon, Sun } from 'lucide-vue-next'

const router = useRouter()
const themeStore = useThemeStore()

const form = ref({
  first_name: '',
  last_name: '',
  middle_name: '',
  suffix: '',
  username: '',
  email: '',
  password: '',
  confirm_password: '',
  lvl_of_access_id: '',
  user_office_id: '',
})

const showPassword = ref(false)
const showConfirmPassword = ref(false)
const loading = ref(false)
const loadingOptions = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

const levels = ref([])
const userOffices = ref([])

// Sequential numbers test: detects 3+ consecutive ascending digits e.g. 123, 456
function hasSequentialNumbers(val) {
  if (!val || val.length < 3) return false
  for (let i = 0; i < val.length - 2; i++) {
    const a = val.charCodeAt(i)
    const b = val.charCodeAt(i + 1)
    const c = val.charCodeAt(i + 2)
    if (a >= 48 && a <= 57 && b === a + 1 && c === a + 2) {
      return true
    }
  }
  return false
}

// Password rule evaluators
const ruleLen = computed(() => form.value.password.length >= 6)
const ruleUpper = computed(() => /[A-Z]/.test(form.value.password))
const ruleLower = computed(() => /[a-z]/.test(form.value.password))
const ruleNum = computed(() => /[0-9]/.test(form.value.password))
const ruleSeq = computed(() => form.value.password.length > 0 && !hasSequentialNumbers(form.value.password))

const isPasswordValid = computed(() => {
  return ruleLen.value && ruleUpper.value && ruleLower.value && ruleNum.value && ruleSeq.value
})

// Names: letters (incl. ñ/accents), spaces, hyphens, apostrophes and periods — mirrors the backend
const NAME_PATTERN = /^\p{L}[\p{L} .'-]*$/u
const NAME_FIELDS = { first_name: 'First name', last_name: 'Last name', middle_name: 'Middle name' }
const SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'VI']

function nameError(field) {
  const value = form.value[field].trim()
  if (value === '' || NAME_PATTERN.test(value)) return ''
  return `${NAME_FIELDS[field]} can only contain letters, spaces, hyphens (-), apostrophes (') and periods (.).`
}
const nameErrors = computed(() => ({
  first_name: nameError('first_name'),
  last_name: nameError('last_name'),
  middle_name: nameError('middle_name'),
}))

// On blur: collapse spaces and capitalise each word ("eduardo  gimeno" → "Eduardo Gimeno")
function tidyName(field) {
  form.value[field] = form.value[field]
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/(^|[\s'-])(\p{Ll})/gu, (_, sep, ch) => sep + ch.toUpperCase())
}

async function fetchOptions() {
  loadingOptions.value = true
  try {
    const res = await authApi.getRegisterOptions()
    if (res.data) {
      levels.value = res.data.levels || []
      userOffices.value = res.data.userOffices || []
    }
  } catch (err) {
    console.error('Could not load register options', err)
    errorMessage.value = 'Could not load access levels and offices. Please refresh the page to try again.'
  } finally {
    loadingOptions.value = false
  }
}

async function handleRegister() {
  if (loading.value) return
  errorMessage.value = ''
  successMessage.value = ''

  if (!form.value.first_name.trim() || !form.value.last_name.trim()) {
    errorMessage.value = 'Please provide both First Name and Last Name.'
    return
  }
  const badName = Object.values(nameErrors.value).find(Boolean)
  if (badName) {
    errorMessage.value = badName
    return
  }
  if (!form.value.username || !form.value.email) {
    errorMessage.value = 'Username and Email are required.'
    return
  }
  if (!form.value.password) {
    errorMessage.value = 'Please enter a password.'
    return
  }
  if (!isPasswordValid.value) {
    errorMessage.value = 'Password does not meet all the required criteria.'
    return
  }
  if (form.value.password !== form.value.confirm_password) {
    errorMessage.value = 'Password and Confirm Password do not match.'
    return
  }
  if (!form.value.lvl_of_access_id) {
    errorMessage.value = 'Please select a Level of Access.'
    return
  }
  if (!form.value.user_office_id) {
    errorMessage.value = 'Please select a User Office.'
    return
  }

  loading.value = true
  try {
    const res = await authApi.register(form.value)
    successMessage.value =
      res.message ||
      'Account created successfully. Please wait for an administrator to activate your account.'

    // Reset form
    form.value = {
      first_name: '',
      last_name: '',
      middle_name: '',
      suffix: '',
      username: '',
      email: '',
      password: '',
      confirm_password: '',
      lvl_of_access_id: '',
      user_office_id: '',
    }

    // Redirect to login after 3.5 seconds
    setTimeout(() => {
      router.push('/login')
    }, 3500)
  } catch (err) {
    errorMessage.value =
      err.response?.data?.message ||
      err.response?.data?.error ||
      'Failed to register. Please check your information and try again.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchOptions()
})
</script>

<template>
  <div class="login-page-screen" style="align-items: flex-start; padding: 40px 20px;">
    <div class="login-shell-register">
      <!-- Left Brand Panel -->
      <section class="login-brand-panel" style="min-height: 100%;">
        <div>
          <h1 class="login-brand-title">
            Set up a new inventory user
          </h1>
        </div>

        <div class="login-feature-list" style="margin-top: 60px;">
          <div class="login-feature-item">
            Fill up the form.
          </div>
          <div class="login-feature-item">
            Assign each account to an office for organized stock movement.
          </div>
          <div class="login-feature-item">
            An administrator will review and activate your account.
          </div>
        </div>
      </section>

      <!-- Right Registration Form Card -->
      <section class="login-card">
        <div class="login-card-content">
          <!-- Institution & Department Branding Logos -->
          <AuthLogoHeader />

          <h2 class="login-card-title">Create Account</h2>
          <p class="login-card-subtitle">
            Create a new user. Your account will be pending until an admin activates it.
          </p>

          <!-- Error Alert Banner -->
          <div v-if="errorMessage" class="login-error-banner">
            <span><AlertTriangle :size="15" /> {{ errorMessage }}</span>
          </div>

          <!-- Success Alert Banner -->
          <div v-if="successMessage" class="login-success-banner">
            <div><CheckCircle2 :size="15" /> {{ successMessage }}</div>
            <div style="font-size: 12.5px; opacity: 0.9; font-weight: 500;">
              Redirecting to login page in a few moments...
            </div>
          </div>

          <form class="login-form-body" @submit.prevent="handleRegister">
            <!-- First Name -->
            <div class="login-field-group">
              <label class="login-field-label">
                First Name <span style="color: #e74c3c;">*</span>
              </label>
              <input
                v-model="form.first_name"
                type="text"
                class="login-field-input"
                :class="{ 'is-invalid': nameErrors.first_name }"
                placeholder="Enter first name"
                :aria-invalid="!!nameErrors.first_name"
                @blur="tidyName('first_name')"
                required
              />
              <small v-if="nameErrors.first_name" class="field-error">{{ nameErrors.first_name }}</small>
            </div>

            <!-- Family Name (Last Name) -->
            <div class="login-field-group">
              <label class="login-field-label">
                Family Name (Last Name) <span style="color: #e74c3c;">*</span>
              </label>
              <input
                v-model="form.last_name"
                type="text"
                class="login-field-input"
                :class="{ 'is-invalid': nameErrors.last_name }"
                placeholder="Enter family / last name"
                :aria-invalid="!!nameErrors.last_name"
                @blur="tidyName('last_name')"
                required
              />
              <small v-if="nameErrors.last_name" class="field-error">{{ nameErrors.last_name }}</small>
            </div>

            <!-- Middle Name (optional) -->
            <div class="login-field-group">
              <label class="login-field-label">
                Middle Name <span style="color: #94a3b8; font-weight: 400; font-size: 0.85em;">(optional)</span>
              </label>
              <input
                v-model="form.middle_name"
                type="text"
                class="login-field-input"
                :class="{ 'is-invalid': nameErrors.middle_name }"
                placeholder="Enter middle name"
                :aria-invalid="!!nameErrors.middle_name"
                @blur="tidyName('middle_name')"
              />
              <small v-if="nameErrors.middle_name" class="field-error">{{ nameErrors.middle_name }}</small>
            </div>

            <!-- Suffix (optional) -->
            <div class="login-field-group">
              <label class="login-field-label">
                Suffix <span style="color: #94a3b8; font-weight: 400; font-size: 0.85em;">(optional — e.g. Jr., Sr., III)</span>
              </label>
              <select v-model="form.suffix" class="login-field-select" style="max-width: 180px;">
                <option value="">None</option>
                <option v-for="sfx in SUFFIXES" :key="sfx" :value="sfx">{{ sfx }}</option>
              </select>
            </div>

            <!-- Username -->
            <div class="login-field-group">
              <label class="login-field-label">Username</label>
              <input
                v-model="form.username"
                type="text"
                class="login-field-input"
                placeholder="Choose a unique username"
                autocomplete="username"
                required
              />
            </div>

            <!-- Email -->
            <div class="login-field-group">
              <label class="login-field-label">Email</label>
              <input
                v-model="form.email"
                type="email"
                class="login-field-input"
                placeholder="Enter email address"
                autocomplete="email"
                required
              />
            </div>

            <!-- Password with Eye Toggle -->
            <div class="login-field-group">
              <label class="login-field-label">Password</label>
              <div class="login-password-wrapper">
                <input
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  class="login-field-input"
                  placeholder="Create password"
                  autocomplete="new-password"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  @click="showPassword = !showPassword"
                  :title="showPassword ? 'Hide password' : 'Show password'"
                  aria-label="Toggle password visibility"
                >
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

            <!-- Live Password Strength Checklist (Matches screenshot exactly) -->
            <ul class="pw-checklist" aria-live="polite">
              <li :class="{ ok: ruleLen }">At least 6 characters</li>
              <li :class="{ ok: ruleUpper }">At least one uppercase letter (A-Z)</li>
              <li :class="{ ok: ruleLower }">At least one lowercase letter (a-z)</li>
              <li :class="{ ok: ruleNum }">At least one number (0-9)</li>
              <li :class="{ ok: ruleSeq }">No sequential numbers (e.g. 123, 456)</li>
            </ul>

            <!-- Confirm Password with Eye Toggle -->
            <div class="login-field-group">
              <label class="login-field-label">Confirm Password</label>
              <div class="login-password-wrapper">
                <input
                  v-model="form.confirm_password"
                  :type="showConfirmPassword ? 'text' : 'password'"
                  class="login-field-input"
                  :class="matchClass(form.password, form.confirm_password)"
                  placeholder="Confirm password"
                  autocomplete="new-password"
                  required
                />
                <button
                  type="button"
                  class="login-password-toggle"
                  @click="showConfirmPassword = !showConfirmPassword"
                  :title="showConfirmPassword ? 'Hide password' : 'Show password'"
                  aria-label="Toggle confirm password visibility"
                >
                  <svg
                    v-if="!showConfirmPassword"
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
              <PasswordMatchHint :password="form.password" :confirm="form.confirm_password" />
            </div>

            <!-- Level of Access -->
            <div class="login-field-group">
              <label class="login-field-label">Level of Access</label>
              <select v-model="form.lvl_of_access_id" class="login-field-select" required>
                <option value="">Select Level</option>
                <option
                  v-for="lvl in levels"
                  :key="lvl.lvl_of_access_id"
                  :value="lvl.lvl_of_access_id"
                >
                  {{ lvl.role }} (Level {{ lvl.lvl_of_access }})
                </option>
              </select>
            </div>

            <!-- User Office -->
            <div class="login-field-group">
              <label class="login-field-label">User Office</label>
              <select v-model="form.user_office_id" class="login-field-select" required>
                <option value="">Select User Office</option>
                <option
                  v-for="uo in userOffices"
                  :key="uo.user_office_id"
                  :value="uo.user_office_id"
                >
                  {{ uo.user_office_name }}
                </option>
              </select>
            </div>

            <!-- Submit Button -->
            <button
              type="submit"
              class="login-submit-button"
              :disabled="loading"
              style="margin-top: 14px;"
            >
              {{ loading ? 'Creating Account...' : 'Create Account' }}
            </button>
          </form>

          <!-- Back to login Pill -->
          <div class="login-inline-actions" style="margin-top: 20px;">
            <router-link to="/login" class="login-secondary-pill">
              Back to login
            </router-link>
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
      <Palette v-if="themeStore.current === 'bsu'" :size="18" />
      <Moon v-else-if="themeStore.current === 'dark'" :size="18" />
      <Sun v-else :size="18" />
    </button>
  </div>
</template>

<style scoped>
.login-field-input.is-invalid,
.login-field-input.is-invalid:focus {
  border-color: #dc2626;
  box-shadow: 0 0 0 4px #dc26261f;
}

.field-error {
  display: block;
  margin-top: 6px;
  font-size: 12.5px;
  font-weight: 500;
  color: #dc2626;
}
</style>
