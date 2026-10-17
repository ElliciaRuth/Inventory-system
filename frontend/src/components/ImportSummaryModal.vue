<script setup>
import { ref, computed, watch } from 'vue'
import {
  X, FileSpreadsheet, Upload, AlertTriangle, CheckCircle2, Info, Lock, Eye, EyeOff,
} from 'lucide-vue-next'
import { importApi } from '../api/import'
import { triggerAutoReload } from '../composables/useAutoReload'
import { useAuthStore } from '../stores/authStore'
import { toast } from '../composables/useToast'

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
  (mode.value === 'append' || mode.value === 'replace')
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

function money(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function qty(v) {
  const n = Number(v || 0)
  return n === 0 ? '-' : (Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, ''))
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
      <div class="modal-card" style="max-width: 860px;">
        <!-- Header -->
        <div class="modal-header">
          <div style="display:flex; align-items:center; gap:10px;">
            <FileSpreadsheet :size="20" />
            <h2 class="modal-title">Import Summary Report</h2>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="busy" @click="close"><X :size="16" /></button>
        </div>

        <!-- STEP: choose -->
        <div v-if="step === 'choose'" class="modal-body">
          <p class="is-help" style="margin-bottom:1.25rem;">
            Upload a <strong>.xlsx</strong> file whose sheets match the Summary Report layout
            (one sheet per product type, columns: BEGINNING INVENTORY · PURCHASES · USED · BREAKAGE · ENDING INVENTORY).
          </p>

          <div v-if="errorMessage" class="alert alert-danger" style="margin-bottom:1rem;">{{ errorMessage }}</div>

          <div class="form-group">
            <label class="form-label">Excel file (.xlsx)</label>
            <input
              ref="fileInput"
              type="file"
              accept=".xlsx"
              class="form-input"
              @change="onFileChange"
            />
          </div>

          <div class="form-group">
            <label class="form-label">Report Month <small class="text-muted">(optional — used when the file has no date)</small></label>
            <input v-model="monthHint" type="month" class="form-input" style="max-width:200px;" />
          </div>

          <div class="modal-footer" style="margin-top:1.5rem;">
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button>
            <button
              class="btn btn-primary"
              :disabled="!file || busy"
              @click="uploadPreview"
            >
              <Upload :size="15" />
              {{ busy ? 'Reading…' : 'Preview' }}
            </button>
          </div>
        </div>

        <!-- STEP: preview -->
        <div v-else-if="step === 'preview'" class="modal-body">
          <!-- Notes / warnings -->
          <div v-if="plan?.notes?.length" class="alert alert-warning" style="margin-bottom:1rem;">
            <div v-for="note in plan.notes" :key="note" style="display:flex;gap:8px;align-items:flex-start;">
              <AlertTriangle :size="14" style="flex-shrink:0;margin-top:2px;" />
              <span>{{ note }}</span>
            </div>
          </div>

          <div v-if="errorMessage" class="alert alert-danger" style="margin-bottom:1rem;">{{ errorMessage }}</div>

          <!-- Summary badges -->
          <div class="sim-badges">
            <span class="badge badge-success">{{ okProducts.length }} product{{ okProducts.length !== 1 ? 's' : '' }} ready</span>
            <span v-if="skippedProducts.length" class="badge badge-warning">{{ skippedProducts.length }} skipped</span>
            <span v-if="detectedMonth" class="badge badge-info">{{ detectedMonth }}</span>
            <span v-if="plan?.summary?.types?.length" class="badge badge-secondary">{{ plan.summary.types.join(' · ') }}</span>
          </div>

          <!-- Product table -->
          <div class="table-responsive" style="max-height:320px;overflow-y:auto;margin:1rem 0;">
            <table class="data-table" style="font-size:0.8rem;">
              <thead>
                <tr>
                  <th rowspan="2">Product</th>
                  <th rowspan="2">Unit</th>
                  <th rowspan="2">Type</th>
                  <th colspan="2" class="text-center">Beginning</th>
                  <th colspan="2" class="text-center">Purchase</th>
                  <th colspan="2" class="text-center">Used</th>
                  <th colspan="2" class="text-center">Spoiled</th>
                </tr>
                <tr>
                  <th>Qty</th><th>Cost</th>
                  <th>Qty</th><th>Cost</th>
                  <th>Qty</th><th>Cost</th>
                  <th>Qty</th><th>Cost</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in okProducts" :key="p.key" :class="{ 'has-warning': p.warnings?.length }">
                  <td>{{ p.item }}</td>
                  <td>{{ p.unit }}</td>
                  <td><span class="badge badge-secondary" style="font-size:0.7rem;">{{ p.type }}</span></td>
                  <td class="text-right">{{ qty(p.begin_qty) }}</td>
                  <td class="text-right">{{ p.begin_cost > 0 ? money(p.begin_cost) : '-' }}</td>
                  <td class="text-right">{{ qty(p.purch_qty) }}</td>
                  <td class="text-right">{{ p.purch_cost > 0 ? money(p.purch_cost) : '-' }}</td>
                  <td class="text-right">{{ qty(p.used_qty) }}</td>
                  <td class="text-right">{{ p.used_cost > 0 ? money(p.used_cost) : '-' }}</td>
                  <td class="text-right">{{ qty(p.spoil_qty) }}</td>
                  <td class="text-right">{{ p.spoil_cost > 0 ? money(p.spoil_cost) : '-' }}</td>
                </tr>
                <tr v-if="!okProducts.length">
                  <td colspan="11" class="text-center text-muted" style="padding:1rem;">No importable products found in this file.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Skipped products -->
          <details v-if="skippedProducts.length" style="margin-bottom:1rem;">
            <summary style="cursor:pointer;color:var(--color-warning);font-size:0.85rem;">{{ skippedProducts.length }} row{{ skippedProducts.length !== 1 ? 's' : '' }} skipped</summary>
            <ul style="margin:.5rem 0 0 1rem;font-size:0.8rem;">
              <li v-for="p in skippedProducts" :key="p.key">{{ p.item || '(empty)' }} — {{ p.warnings?.join('; ') || p.status }}</li>
            </ul>
          </details>

          <!-- Mode selector -->
          <div class="sim-mode-section">
            <p class="form-label" style="margin-bottom:.5rem;">How should this import be applied?</p>
            <label class="sim-radio">
              <input v-model="mode" type="radio" value="append" />
              <div>
                <strong>Append</strong>
                <small>Add to or update existing products. Previously imported rows are skipped automatically.</small>
              </div>
            </label>
            <label class="sim-radio">
              <input v-model="mode" type="radio" value="replace" />
              <div>
                <strong>Replace</strong>
                <small>
                  Delete all existing inventory for this office first, then import.
                  This currently has
                  <strong>{{ existing.products }} product{{ existing.products !== 1 ? 's' : '' }}</strong>,
                  {{ existing.batches }} batch{{ existing.batches !== 1 ? 'es' : '' }},
                  {{ existing.transactions }} transaction{{ existing.transactions !== 1 ? 's' : '' }}.
                  A backup is created automatically.
                </small>
              </div>
            </label>

            <!-- Manager password for replace mode -->
            <div v-if="mode === 'replace'" class="form-group" style="margin-top:1rem;">
              <label class="form-label">
                <Lock :size="13" style="vertical-align:-2px;" />
                {{ isManager ? 'Your password' : 'Manager password' }}
              </label>
              <div style="display:flex;gap:.5rem;align-items:center;">
                <input
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  class="form-input"
                  style="max-width:280px;"
                  autocomplete="current-password"
                  placeholder="Enter password to confirm deletion"
                />
                <button type="button" class="btn btn-sm btn-secondary" @click="showPassword = !showPassword">
                  <component :is="showPassword ? EyeOff : Eye" :size="15" />
                </button>
              </div>
              <p v-if="passwordError" class="form-error">{{ passwordError }}</p>
            </div>
          </div>

          <div class="modal-footer" style="margin-top:1.5rem;">
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="step = 'choose'">Back</button>
            <button
              class="btn btn-primary"
              :disabled="!canImport"
              @click="commitImport"
            >
              <CheckCircle2 v-if="!busy" :size="15" />
              {{ busy ? 'Importing…' : 'Import' }}
            </button>
          </div>
        </div>

        <!-- STEP: done -->
        <div v-else-if="step === 'done'" class="modal-body">
          <div class="sim-done">
            <CheckCircle2 :size="40" class="sim-done-icon" />
            <h3>Import complete</h3>
            <div class="sim-stats">
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.products_created ?? 0 }}</span>
                <span class="sim-stat-label">Products created</span>
              </div>
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.products_reused ?? 0 }}</span>
                <span class="sim-stat-label">Products reused</span>
              </div>
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.receipts ?? 0 }}</span>
                <span class="sim-stat-label">Receipts</span>
              </div>
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.issues ?? 0 }}</span>
                <span class="sim-stat-label">Issues</span>
              </div>
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.adjustments ?? 0 }}</span>
                <span class="sim-stat-label">Adjustments</span>
              </div>
              <div class="sim-stat">
                <span class="sim-stat-value">{{ result?.already_imported ?? 0 }}</span>
                <span class="sim-stat-label">Already imported</span>
              </div>
            </div>
            <div v-if="result?.backups?.length" style="margin-top:.75rem;font-size:.85rem;color:var(--text-muted);">
              <Info :size="13" style="vertical-align:-2px;" /> Backup created for: {{ result.backups.join(', ') }}
            </div>
          </div>
          <div class="modal-footer" style="margin-top:1.5rem;">
            <button class="btn btn-primary" @click="close">Close</button>
          </div>
        </div>
      </div>
    </div>
  </teleport>
</template>

<style scoped>
.sim-badges {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  margin-bottom: .75rem;
}

.sim-mode-section {
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: .75rem;
}

.sim-radio {
  display: flex;
  gap: .75rem;
  align-items: flex-start;
  cursor: pointer;
}

.sim-radio input { margin-top: 3px; flex-shrink: 0; }

.sim-radio div {
  display: flex;
  flex-direction: column;
  gap: .2rem;
}

.sim-radio strong {
  font-size: .9rem;
  color: var(--text-main);
}

.sim-radio small {
  font-size: .8rem;
  color: var(--text-muted);
}

.has-warning {
  background: var(--color-warning-bg, #fff8e1);
}

.text-right { text-align: right; }
.text-center { text-align: center; }
.text-muted { color: var(--text-muted); }

.sim-done {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 2rem 0;
  text-align: center;
}

.sim-done-icon { color: var(--color-success, #22c55e); }

.sim-done h3 {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--text-main);
  margin: 0;
}

.sim-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
  width: 100%;
  max-width: 480px;
}

.sim-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .25rem;
  background: var(--bg-subtle);
  border-radius: var(--radius-md);
  padding: .75rem;
}

.sim-stat-value {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--color-primary);
}

.sim-stat-label {
  font-size: .75rem;
  color: var(--text-muted);
  text-align: center;
}
</style>
