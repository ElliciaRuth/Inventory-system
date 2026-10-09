<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Mail, Send, Save, Eye, EyeOff, CheckCircle2, AlertTriangle, Info } from 'lucide-vue-next'
import { adminApi } from '../api/admin'
import { useAuthStore } from '../stores/authStore'
import { toast, errorMessage } from '../composables/useToast'

// Gmail account the system sends password-reset codes from (Technical Staff only)
const authStore = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const showPassword = ref(false)
const status = ref({ configured: false })
const form = reactive({ smtp_email: '', smtp_password: '' })
const testTo = ref(authStore.user?.email || '')
// Result of the last test send, shown inline so a failure reason stays visible
const testResult = ref(null) // { ok: boolean, message: string }

async function load() {
  try {
    const res = await adminApi.getEmailSettings()
    status.value = res.data || { configured: false }
    form.smtp_email = status.value.smtp_email || ''
  } catch (err) {
    toast(errorMessage(err, 'Failed to load email settings.'), 'error')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  testResult.value = null
  try {
    const res = await adminApi.saveEmailSettings(form.smtp_email.trim(), form.smtp_password)
    toast(res.message || 'Email settings saved.')
    form.smtp_password = ''
    showPassword.value = false
    await load()
  } catch (err) {
    toast(errorMessage(err, 'Failed to save email settings.'), 'error')
  } finally {
    saving.value = false
  }
}

async function sendTest() {
  testing.value = true
  testResult.value = null
  try {
    const res = await adminApi.sendTestEmail(testTo.value.trim())
    testResult.value = { ok: true, message: res.message || 'Test email sent.' }
  } catch (err) {
    testResult.value = { ok: false, message: errorMessage(err, 'The test email could not be sent.') }
  } finally {
    testing.value = false
  }
}

function formatDate(value) {
  if (!value) return ''
  const d = new Date(value.replace(' ', 'T'))
  return isNaN(d) ? value : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

onMounted(load)
</script>

<template>
  <section class="panel es-panel">
    <div v-if="loading" class="es-loading">Loading email settings…</div>

    <template v-else>
      <!-- Status -->
      <div class="es-status" :class="status.configured ? 'is-on' : 'is-off'">
        <component :is="status.configured ? CheckCircle2 : AlertTriangle" :size="20" class="es-status-icon" />
        <div>
          <strong v-if="status.configured">Password recovery is on</strong>
          <strong v-else>Password recovery is off</strong>
          <p v-if="status.configured">
            Reset codes are sent from <b>{{ status.smtp_email }}</b>.
            <span v-if="status.updated_at">
              Last updated {{ formatDate(status.updated_at) }}<span v-if="status.configured_by"> by {{ status.configured_by }}</span>.
            </span>
          </p>
          <p v-else>
            No sending account is set up, so "Forgot Password" can't send codes. Add a Gmail account below.
          </p>
        </div>
      </div>

      <div class="es-grid">
        <!-- Account form -->
        <form class="es-card" @submit.prevent="save">
          <h3 class="es-card-title"><Mail :size="16" /> Sending Gmail account</h3>

          <div class="form-group">
            <label class="form-label" for="smtp_email">Gmail address</label>
            <input
              id="smtp_email"
              v-model="form.smtp_email"
              type="email"
              class="form-input"
              placeholder="e.g. bsu.inventory@gmail.com"
              autocomplete="off"
              required
            />
          </div>

          <div class="form-group">
            <label class="form-label" for="smtp_password">App Password</label>
            <div class="es-password">
              <input
                id="smtp_password"
                v-model="form.smtp_password"
                :type="showPassword ? 'text' : 'password'"
                class="form-input"
                :placeholder="status.configured ? 'Leave blank to keep the current password' : 'xxxx xxxx xxxx xxxx'"
                autocomplete="new-password"
                :required="!status.configured"
              />
              <button
                type="button"
                class="es-eye"
                :title="showPassword ? 'Hide password' : 'Show password'"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="showPassword = !showPassword"
              >
                <component :is="showPassword ? EyeOff : Eye" :size="16" />
              </button>
            </div>
            <small class="form-hint">The 16-character App Password from Google, not the normal Gmail password.</small>
          </div>

          <button type="submit" class="btn btn-primary es-btn" :disabled="saving">
            <Save :size="15" />
            <span>{{ saving ? 'Saving…' : 'Save Email Settings' }}</span>
          </button>
        </form>

        <!-- Help + test -->
        <div class="es-side">
          <div class="es-card es-help">
            <h3 class="es-card-title"><Info :size="16" /> Getting an App Password</h3>
            <ol>
              <li>Sign in to the Gmail account at <b>myaccount.google.com</b>.</li>
              <li>Open <b>Security</b> and turn on <b>2-Step Verification</b>.</li>
              <li>Search for <b>App passwords</b>, create one (name it "BSU Inventory").</li>
              <li>Copy the 16-character password into the field here and save.</li>
            </ol>
          </div>

          <form class="es-card" @submit.prevent="sendTest">
            <h3 class="es-card-title"><Send :size="16" /> Send a test email</h3>
            <div class="es-test-row">
              <input
                v-model="testTo"
                type="email"
                class="form-input"
                placeholder="Send the test to…"
                aria-label="Test recipient email"
                required
              />
              <button type="submit" class="btn btn-secondary es-btn" :disabled="testing || !status.configured">
                {{ testing ? 'Sending…' : 'Send Test' }}
              </button>
            </div>
            <small v-if="!status.configured" class="form-hint">Save the email settings first.</small>
            <p v-if="testResult" class="es-test-result" :class="testResult.ok ? 'is-ok' : 'is-error'" aria-live="polite">
              {{ testResult.message }}
            </p>
          </form>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.es-panel {
  padding: 1.5rem;
}

.es-loading {
  padding: 2rem;
  text-align: center;
  color: var(--text-muted);
}

.es-status {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px 16px;
  margin-bottom: 1.25rem;
  border-radius: var(--radius-md);
  border: 1px solid;
}

.es-status p {
  margin: 4px 0 0;
  font-size: 13.5px;
  color: var(--text-muted);
  overflow-wrap: anywhere;
}

.es-status.is-on {
  background: var(--color-success-bg);
  border-color: color-mix(in srgb, var(--color-success) 30%, transparent);
}

.es-status.is-on .es-status-icon,
.es-status.is-on strong {
  color: var(--color-success);
}

.es-status.is-off {
  background: var(--color-warning-bg);
  border-color: color-mix(in srgb, var(--color-warning) 30%, transparent);
}

.es-status.is-off .es-status-icon,
.es-status.is-off strong {
  color: var(--color-warning);
}

.es-status-icon {
  flex-shrink: 0;
  margin-top: 1px;
}

.es-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.25rem;
  align-items: start;
}

.es-side {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.es-card {
  padding: 1.25rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-surface);
}

.es-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 1rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text-main);
}

.es-password {
  position: relative;
}

.es-password .form-input {
  padding-right: 44px;
}

.es-eye {
  position: absolute;
  top: 50%;
  right: 8px;
  transform: translateY(-50%);
  display: inline-flex;
  padding: 6px;
  border: none;
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

.es-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}

.es-help {
  background: var(--bg-subtle);
}

.es-help ol {
  margin: 0;
  padding-left: 1.2rem;
  font-size: 13.5px;
  line-height: 1.7;
  color: var(--text-muted);
}

.es-test-row {
  display: flex;
  gap: 0.6rem;
}

.es-test-row .form-input {
  flex: 1;
  min-width: 0;
}

.es-test-result {
  margin: 0.75rem 0 0;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 500;
}

.es-test-result.is-ok {
  background: var(--color-success-bg);
  color: var(--color-success);
}

.es-test-result.is-error {
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

@media (max-width: 860px) {
  .es-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 480px) {
  .es-panel {
    padding: 1rem;
  }
  .es-test-row {
    flex-direction: column;
  }
}
</style>
