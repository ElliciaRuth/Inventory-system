<script setup>
import { ref, computed, watch } from 'vue'
import {
  X, FileSpreadsheet, Upload, AlertTriangle, CheckCircle2, Info, Lock, Eye, EyeOff,
  Trash2, Plus, ShieldCheck, RotateCcw,
} from 'lucide-vue-next'
import { importApi } from '../api/import'
import { triggerAutoReload } from '../composables/useAutoReload'
import { useAuthStore } from '../stores/authStore'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'imported'])

const authStore = useAuthStore()
const isManager = computed(() => authStore.levelId >= 3)

// Step: 'choose' | 'preview' | 'done'
const step = ref('choose')
const file = ref(null)
const fileInput = ref(null)
const monthHint = ref('')  // YYYY-MM typed by user
const busy = ref(false)
const errorMessage = ref('')

const token = ref('')
const plan = ref(null)

const mode = ref('')           // 'replace' | 'append'
const password = ref('')
const showPassword = ref(false)
const passwordError = ref('')
const result = ref(null)

const okProducts = computed(() => (plan.value?.products || []).filter((p) => p.status === 'ok'))
const skippedProducts = computed(() => (plan.value?.products || []).filter((p) => p.status !== 'ok'))
const existing = computed(() => plan.value?.existing || { products: 0, batches: 0, transactions: 0 })
const detectedMonth = computed(() => plan.value?.month || '')

const canImport = computed(() =>
  !busy.value &&
  okProducts.value.length > 0 &&
  (mode.value === 'append' || (mode.value === 'replace' && password.value !== ''))
)

function reset() {
  step.value = 'choose'
  file.value = null
  monthHint.value = ''
  busy.value = false
  errorMessage.value = ''
  token.value = ''
  plan.value = null
  mode.value = ''
  password.value = ''
  showPassword.value = false
  passwordError.value = ''
  result.value = null
  if (fileInput.value) fileInput.value.value = ''
}

watch(() => props.isOpen, (open) => { if (open) reset() })

function close() {
  if (busy.value) return
  emit('close')
}

function onFileChange(event) {
  file.value = event.target.files?.[0] || null
  errorMessage.value = ''
}

function reupload() {
  const hint = monthHint.value
  reset()
  monthHint.value = hint
}

function money(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function qty(v) {
  const n = Number(v || 0)
  return n === 0 ? '-' : (Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, ''))
}
function num(value) {
  return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
}
// "2026-09" → "September 2026"
function monthLabel(ym) {
  const [y, m] = String(ym).split('-').map(Number)
  if (!y || !m) return ym
  return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
}

async function uploadPreview() {
  if (!file.value || busy.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    const res = await importApi.previewSummary(file.value, monthHint.value)
    token.value = res.data.token
    plan.value = res.data.plan
    step.value = 'preview'
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'The file could not be read. Make sure it is a valid .xlsx Summary Report.'
  } finally {
    busy.value = false
  }
}

async function commitImport() {
  if (!canImport.value) return
  if (mode.value === 'replace' && !password.value) {
    passwordError.value = 'Password is required to delete the existing inventory.'
    return
  }
  busy.value = true
  passwordError.value = ''
  errorMessage.value = ''
  try {
    const payload = { token: token.value, mode: mode.value }
    if (mode.value === 'replace') payload.password = password.value
    const res = await importApi.commitSummary(payload)
    result.value = res.data
    step.value = 'done'
    triggerAutoReload()
    emit('imported')
  } catch (err) {
    const data = err.response?.data
    if (data?.errors?.password) {
      passwordError.value = data.errors.password
    } else {
      errorMessage.value = data?.message || 'Import failed. Please try again.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <teleport to="body">
    <div v-if="isOpen" class="modal-backdrop" @click.self="close">
<<<<<<< HEAD
      <div class="modal-card import-card">
=======
      <div class="modal-card" style="max-width: 860px;">
        <!-- Header -->
>>>>>>> eb8a10f1b2b7069df3589e297fdaed6f4d2b2025
        <div class="modal-header">
          <div>
            <h2 style="font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
              <FileSpreadsheet :size="20" style="color: var(--color-primary);" />
              Import Summary Report from Excel
            </h2>
            <p class="panel-subtitle">
              <template v-if="step === 'choose'">Each sheet is read as one product type of the monthly Summary Report.</template>
              <template v-else-if="step === 'preview'">{{ plan.file_name }} — check the preview, then choose how to import.</template>
              <template v-else>Import finished.</template>
            </p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="busy" @click="close">
            <X :size="16" />
          </button>
        </div>

        <div class="modal-body">
          <div v-if="errorMessage" class="import-alert import-alert-danger">
            <AlertTriangle :size="16" />
            <span>{{ errorMessage }}</span>
          </div>

          <!-- ── Step 1: choose the file ── -->
          <template v-if="step === 'choose'">
            <label class="import-drop">
              <input ref="fileInput" type="file" accept=".xlsx" @change="onFileChange" />
              <Upload :size="28" />
              <strong>{{ file ? file.name : 'Choose an Excel file (.xlsx)' }}</strong>
              <small>{{ file ? `${(file.size / 1024).toFixed(0)} KB` : 'Click to browse' }}</small>
            </label>

            <div class="form-group import-month">
              <label class="form-label" for="summaryMonth">Report month <span class="import-muted">(optional)</span></label>
              <input id="summaryMonth" v-model="monthHint" type="month" class="form-input" />
              <small class="import-muted">Used only when the file doesn't say which month it is for.</small>
            </div>

            <div class="import-alert import-alert-info">
              <Info :size="16" />
              <div>
                Each sheet needs the Summary Report columns: Beginning Inventory, Purchases, Used,
                Breakage and Ending Inventory. Products with the same name and unit are reused.
                <strong>Nothing is saved until you confirm on the next screen.</strong>
              </div>
            </div>
          </template>

          <!-- ── Step 2: preview and options ── -->
          <template v-else-if="step === 'preview'">
            <div v-if="plan?.notes?.length" class="import-alert import-alert-warning">
              <AlertTriangle :size="16" />
              <div>
                <div v-for="note in plan.notes" :key="note">{{ note }}</div>
              </div>
            </div>
            <div v-else-if="okProducts.length" class="import-alert import-alert-success">
              <CheckCircle2 :size="16" />
              <span><strong>No warnings.</strong> The file is ready to import: choose how below.</span>
            </div>

            <div class="import-stats">
              <div><strong>{{ okProducts.length }}</strong><span>products ready</span></div>
              <div v-if="skippedProducts.length"><strong>{{ skippedProducts.length }}</strong><span>rows skipped</span></div>
              <div><strong>{{ detectedMonth ? monthLabel(detectedMonth) : '—' }}</strong><span>month</span></div>
              <div><strong>{{ plan?.summary?.types?.length || 0 }}</strong><span>product types</span></div>
            </div>

            <h3 class="import-section-title">Products to import ({{ okProducts.length }})</h3>
            <div class="import-table-wrap">
              <table class="data-table import-table">
                <thead>
                  <tr>
                    <th rowspan="2">Product</th>
                    <th rowspan="2">Unit</th>
                    <th rowspan="2">Type</th>
                    <th colspan="2" class="is-center">Beginning</th>
                    <th colspan="2" class="is-center">Purchases</th>
                    <th colspan="2" class="is-center">Used</th>
                    <th colspan="2" class="is-center">Breakage</th>
                  </tr>
                  <tr class="import-subhead">
                    <th class="is-num">Qty</th><th class="is-num">Cost</th>
                    <th class="is-num">Qty</th><th class="is-num">Cost</th>
                    <th class="is-num">Qty</th><th class="is-num">Cost</th>
                    <th class="is-num">Qty</th><th class="is-num">Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in okProducts" :key="p.key" :class="{ 'is-warning': p.warnings?.length }">
                    <td>
                      <strong>{{ p.item }}</strong>
                      <div v-if="p.stock_no" class="import-mono import-muted">{{ p.stock_no }}</div>
                    </td>
                    <td>{{ p.unit }}</td>
                    <td class="import-muted">{{ p.type }}</td>
                    <td class="is-num">{{ qty(p.begin_qty) }}</td>
                    <td class="is-num">{{ p.begin_cost > 0 ? money(p.begin_cost) : '-' }}</td>
                    <td class="is-num">{{ qty(p.purch_qty) }}</td>
                    <td class="is-num">{{ p.purch_cost > 0 ? money(p.purch_cost) : '-' }}</td>
                    <td class="is-num">{{ qty(p.used_qty) }}</td>
                    <td class="is-num">{{ p.used_cost > 0 ? money(p.used_cost) : '-' }}</td>
                    <td class="is-num">{{ qty(p.spoil_qty) }}</td>
                    <td class="is-num">{{ p.spoil_cost > 0 ? money(p.spoil_cost) : '-' }}</td>
                  </tr>
                  <tr v-if="!okProducts.length">
                    <td colspan="11" class="import-muted" style="padding: 1rem; text-align: center;">No importable products found in this file.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="skippedProducts.length" class="import-alert import-alert-warning" style="margin-top: 1rem;">
              <AlertTriangle :size="16" />
              <div>
                <strong>{{ skippedProducts.length }} row{{ skippedProducts.length === 1 ? '' : 's' }} skipped:</strong>
                <ul class="import-list">
                  <li v-for="p in skippedProducts" :key="p.key">{{ p.item || '(empty)' }} — {{ p.warnings?.join('; ') || p.status }}</li>
                </ul>
              </div>
            </div>

            <h3 class="import-section-title">Import options</h3>
            <fieldset class="import-choice">
              <legend class="form-label">Existing inventory of your office *</legend>

              <label class="import-option" :class="{ 'is-selected': mode === 'replace', 'is-danger': mode === 'replace' }">
                <input v-model="mode" type="radio" name="summaryImportMode" value="replace" />
                <div>
                  <strong><Trash2 :size="14" /> Delete existing inventory first, then import</strong>
                  <small>
                    Removes {{ num(existing.products) }} products, {{ num(existing.batches) }} batches and
                    {{ num(existing.transactions) }} transactions of your office.
                    Users, offices, units, references and settings are kept.
                  </small>
                </div>
              </label>

              <label class="import-option" :class="{ 'is-selected': mode === 'append' }">
                <input v-model="mode" type="radio" name="summaryImportMode" value="append" />
                <div>
                  <strong><Plus :size="14" /> Keep existing data and add the Excel entries</strong>
                  <small>Products with the same name and unit are reused. Rows imported before are skipped, so importing the same file twice adds nothing.</small>
                </div>
              </label>
            </fieldset>

            <div v-if="mode === 'replace'" class="import-confirm">
              <div class="import-alert import-alert-info">
                <ShieldCheck :size="16" />
                <span>A backup of your office is made automatically before anything is deleted. It can be restored from <strong>Office Settings → Data Backup &amp; Restore</strong>.</span>
              </div>
              <label class="form-label" for="summaryImportPassword">
                <Lock :size="13" style="vertical-align: -2px;" />
                {{ isManager ? 'Enter your password to confirm' : 'Manager: enter your password to approve' }}
              </label>
              <div class="import-password-field">
                <input
                  id="summaryImportPassword"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  class="form-input"
                  :class="{ 'is-invalid': passwordError }"
                  autocomplete="current-password"
                  :placeholder="isManager ? 'Your password' : 'Manager\'s password'"
                  :disabled="busy"
                  @input="passwordError = ''"
                />
                <button
                  type="button"
                  class="import-password-toggle"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  @click="showPassword = !showPassword"
                >
                  <component :is="showPassword ? EyeOff : Eye" :size="16" />
                </button>
              </div>
              <p v-if="passwordError" class="import-password-error">{{ passwordError }}</p>
            </div>
          </template>

          <!-- ── Step 3: result ── -->
          <template v-else-if="result">
            <div class="import-alert import-alert-success">
              <CheckCircle2 :size="18" />
              <div>
                <strong>Summary Report imported{{ detectedMonth ? ` for ${monthLabel(detectedMonth)}` : '' }}.</strong>
                <div v-if="result.backups?.length">Backup made first: {{ result.backups.join(', ') }}.</div>
                <div v-if="result.deleted">
                  Deleted beforehand: {{ num(result.deleted.products) }} products, {{ num(result.deleted.transactions) }} transactions.
                </div>
              </div>
            </div>
            <div class="import-stats">
              <div><strong>{{ result.products_created ?? 0 }}</strong><span>products created</span></div>
              <div><strong>{{ result.products_reused ?? 0 }}</strong><span>products reused</span></div>
              <div><strong>{{ result.receipts ?? 0 }}</strong><span>receipts</span></div>
              <div><strong>{{ result.issues ?? 0 }}</strong><span>issues</span></div>
              <div><strong>{{ result.adjustments ?? 0 }}</strong><span>breakage</span></div>
            </div>
            <p v-if="result.already_imported" class="import-muted">
              {{ result.already_imported }} rows were already in the system from an earlier import and were skipped.
            </p>
          </template>
        </div>

        <div class="modal-footer">
          <template v-if="step === 'choose'">
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="!file || busy" @click="uploadPreview">
              {{ busy ? 'Reading file…' : 'Preview' }}
            </button>
          </template>

          <template v-else-if="step === 'preview'">
            <button type="button" class="btn btn-secondary" :disabled="busy" style="margin-right: auto;" @click="reupload">
              <RotateCcw :size="15" /> Cancel &amp; Re-upload
            </button>
            <button
              type="button"
              class="btn btn-primary"
              :class="{ 'import-btn-danger': mode === 'replace' }"
              :disabled="!canImport"
              @click="commitImport"
            >
              <template v-if="busy">Importing…</template>
              <template v-else-if="mode === 'replace'">Delete &amp; Import {{ okProducts.length }} products</template>
              <template v-else>Import {{ okProducts.length }} products</template>
            </button>
          </template>

          <template v-else>
            <button type="button" class="btn btn-primary" @click="close">Done</button>
          </template>
        </div>
      </div>
    </div>
  </teleport>
</template>

<!-- Same look as ImportStockcardsModal -->
<style scoped>
.import-card {
  max-width: 920px;
}

.import-drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 2rem 1rem;
  border: 2px dashed var(--border-hover);
  border-radius: var(--radius-lg);
  background: var(--bg-subtle);
  color: var(--color-primary);
  text-align: center;
  cursor: pointer;
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.import-drop:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.import-drop input {
  display: none;
}

.import-drop strong {
  color: var(--text-main);
  word-break: break-all;
}

.import-drop small,
.import-muted {
  color: var(--text-muted);
  font-size: 0.8rem;
}

.import-month {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 1.25rem 0 1rem;
}

.import-month .form-input {
  max-width: 220px;
}

.import-alert {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-md);
  font-size: 0.85rem;
  line-height: 1.5;
  margin-bottom: 1rem;
}

.import-alert > svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

.import-alert-danger { background: var(--color-danger-bg); color: var(--color-danger); }
.import-alert-warning { background: var(--color-warning-bg); color: var(--color-warning); }
.import-alert-info { background: var(--color-info-bg); color: var(--color-info); }
.import-alert-success { background: var(--color-success-bg); color: var(--color-success); }

.import-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.import-stats > div {
  display: flex;
  flex-direction: column;
  padding: 0.75rem 1rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
}

.import-stats strong {
  font-size: 1.35rem;
  color: var(--color-primary);
  line-height: 1.2;
}

.import-stats span {
  font-size: 0.75rem;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.import-section-title {
  font-size: 0.95rem;
  margin: 1.25rem 0 0.6rem;
}

.import-table-wrap {
  max-height: 320px;
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
}

.import-table {
  font-size: 0.82rem;
}

/* Both header rows stay in view while scrolling */
.import-table thead {
  position: sticky;
  top: 0;
  z-index: 1;
}

.import-table thead th {
  background: var(--bg-subtle);
}

.import-table th,
.import-table td {
  padding: 0.5rem 0.65rem;
}

.import-table td:first-child {
  min-width: 170px;
}

.import-table .is-center { text-align: center; }
.import-table .is-num { text-align: right; white-space: nowrap; }

.import-table tbody tr.is-warning td { background: var(--color-warning-bg); }
.import-table tbody tr.is-warning td:first-child { box-shadow: inset 4px 0 0 var(--color-warning); }

.import-mono {
  font-family: var(--font-mono);
  font-size: 0.75rem;
}

.import-list {
  margin: 0.3rem 0 0;
  padding-left: 1.1rem;
  font-size: 0.82rem;
  line-height: 1.6;
}

.import-choice {
  border: 0;
  padding: 0;
  margin: 0 0 1rem;
  display: grid;
  gap: 0.6rem;
}

.import-option {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  padding: 0.85rem 1rem;
  border: 1.5px solid var(--border-subtle);
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.import-option input {
  margin-top: 0.25rem;
  accent-color: var(--color-primary);
}

.import-option.is-selected {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.import-option.is-danger {
  border-color: var(--color-danger);
  background: var(--color-danger-bg);
}

.import-option strong {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.9rem;
  color: var(--text-main);
}

.import-option small {
  display: block;
  margin-top: 0.2rem;
  color: var(--text-muted);
  font-size: 0.8rem;
  line-height: 1.5;
}

.import-btn-danger,
.import-btn-danger:hover:not(:disabled) {
  background: var(--color-danger);
  border-color: var(--color-danger);
}

.import-confirm .form-label {
  display: block;
  margin-bottom: 0.35rem;
}

.import-password-field {
  position: relative;
  max-width: 340px;
}

.import-password-field .form-input {
  width: 100%;
  padding-right: 2.5rem;
}

.import-password-field .form-input.is-invalid {
  border-color: var(--color-danger);
}

.import-password-toggle {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  padding: 4px;
  border: 0;
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

.import-password-toggle:hover {
  color: var(--text-main);
}

.import-password-error {
  margin: 0.45rem 0 0;
  font-size: 0.82rem;
  color: var(--color-danger);
}

@media (max-width: 640px) {
  .modal-footer {
    flex-wrap: wrap;
  }
}
</style>
