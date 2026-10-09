<script setup>
import { ref, computed, watch } from 'vue'
import {
  X, FileSpreadsheet, Upload, AlertTriangle, CheckCircle2, Info, ArrowLeft, Trash2, Plus, ShieldCheck, SpellCheck, PencilLine,
  Lock, Eye, EyeOff,
} from 'lucide-vue-next'
import { importApi } from '../api/import'
import StockcardCardEditor from './StockcardCardEditor.vue'
import { triggerAutoReload } from '../composables/useAutoReload'
import { useAuthStore } from '../stores/authStore'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'imported'])

// Managers confirm a delete-and-import with their own password; custodians need a manager's
const authStore = useAuthStore()
const isManager = computed(() => authStore.levelId >= 3)

const step = ref('choose') // 'choose' | 'preview' | 'done'
const file = ref(null)
const fileInput = ref(null)
const busy = ref(false)
const errorMessage = ref('')

const token = ref('')
const plan = ref(null)
const fallbackOfficeId = ref(0)
const mode = ref('') // 'replace' | 'append' — deliberately no default
// Deleting the existing inventory asks for the manager's password in a pop-up
const askPassword = ref(false)
const password = ref('')
const showPassword = ref(false)
const passwordError = ref('')
const types = ref({}) // product key → type; starts as the detected type
const setAllType = ref('')
const result = ref(null)

const okProducts = computed(() => (plan.value?.products || []).filter((p) => p.status === 'ok'))
const skippedProducts = computed(() => (plan.value?.products || []).filter((p) => p.status !== 'ok'))
const skippedCards = computed(() => (plan.value?.cards || []).filter((c) => c.status !== 'ok'))
const cardsWithNotes = computed(() => (plan.value?.cards || []).filter((c) => c.status === 'ok' && c.warnings.length))
const noteCount = computed(() => cardsWithNotes.value.reduce((n, c) => n + c.warnings.length, 0))
const officeUnknown = computed(() => skippedCards.value.some((c) => /office/i.test(c.reason)))

// ── Correcting a card ──
const editingCard = ref(null)

// Everything said about a card: its own notes, why it was skipped, and product notes naming it
function notesFor(label) {
  const card = (plan.value?.cards || []).find((c) => c.label === label)
  const notes = [...(card?.warnings || [])]
  if (card?.status !== 'ok' && card?.reason) notes.unshift(card.reason)
  for (const p of plan.value?.products || []) {
    for (const w of [...(p.warnings || []), p.reason || '']) {
      if (w && w.startsWith(label + ' ')) notes.push(w)
    }
  }
  return [...new Set(notes)]
}

function applyPlan(newPlan) {
  plan.value = newPlan
  // Keep type choices for products that still exist; new ones start with their detected type
  const kept = {}
  for (const p of newPlan.products) kept[p.key] = types.value[p.key] || p.type
  types.value = kept
}

function onCardApplied(newPlan) {
  applyPlan(newPlan)
  editingCard.value = null
}

// Choosing an office for cards without one keeps the corrections already made
async function applyFallbackOffice() {
  if (!fallbackOfficeId.value || busy.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    const res = await importApi.reviseStockcards(token.value, [], fallbackOfficeId.value)
    applyPlan(res.data.plan)
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'The preview could not be updated.'
  } finally {
    busy.value = false
  }
}
const officeList = computed(() => plan.value?.summary?.offices?.join(', ') || '')
const existing = computed(() => plan.value?.existing || { products: 0, batches: 0, transactions: 0, requests: 0 })
const typeOptions = computed(() => plan.value?.types || [])
const spellingFixes = computed(() => okProducts.value.filter((p) => p.spelling?.length))

// "12 Product Label, 3 Raw Materials" for the current choices
const typeSummary = computed(() => {
  const counts = {}
  for (const p of okProducts.value) {
    const t = types.value[p.key] || p.type
    counts[t] = (counts[t] || 0) + 1
  }
  return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ')
})

function applyTypeToAll() {
  if (!setAllType.value) return
  for (const p of okProducts.value) types.value[p.key] = setAllType.value
  setAllType.value = ''
}

const canImport = computed(() =>
  !busy.value &&
  okProducts.value.length > 0 &&
  (mode.value === 'append' || mode.value === 'replace')
)

function reset() {
  step.value = 'choose'
  file.value = null
  busy.value = false
  errorMessage.value = ''
  token.value = ''
  plan.value = null
  fallbackOfficeId.value = 0
  mode.value = ''
  closePasswordPrompt(true)
  types.value = {}
  setAllType.value = ''
  result.value = null
  editingCard.value = null
  if (fileInput.value) fileInput.value.value = ''
}

watch(() => props.isOpen, (open) => {
  if (open) reset()
})

function close() {
  if (busy.value) return
  emit('close')
}

function onFileChange(event) {
  file.value = event.target.files?.[0] || null
  errorMessage.value = ''
}

async function runPreview() {
  if (!file.value || busy.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    const res = await importApi.previewStockcards(file.value, fallbackOfficeId.value)
    token.value = res.data.token
    plan.value = res.data.plan
    types.value = Object.fromEntries(res.data.plan.products.map((p) => [p.key, p.type]))
    mode.value = ''
    step.value = 'preview'
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'The file could not be read.'
  } finally {
    busy.value = false
  }
}

function openPasswordPrompt() {
  password.value = ''
  passwordError.value = ''
  showPassword.value = false
  askPassword.value = true
}

function closePasswordPrompt(force = false) {
  if (busy.value && !force) return
  askPassword.value = false
  password.value = ''
  passwordError.value = ''
}

// "Delete & Import" first asks for the password; "Keep existing" imports straight away
function startImport() {
  if (!canImport.value) return
  if (mode.value === 'replace') openPasswordPrompt()
  else runImport()
}

function confirmWithPassword() {
  if (!password.value) {
    passwordError.value = isManager.value ? 'Enter your password.' : "Enter a manager's password."
    return
  }
  runImport()
}

async function runImport() {
  if (!canImport.value) return
  busy.value = true
  errorMessage.value = ''
  passwordError.value = ''
  try {
    const res = await importApi.commitStockcards({
      token: token.value,
      mode: mode.value,
      password: mode.value === 'replace' ? password.value : undefined,
      types: types.value,
    })
    result.value = res.data
    step.value = 'done'
    closePasswordPrompt(true)
    triggerAutoReload('stock-import')
    emit('imported')
  } catch (err) {
    const message = err.response?.data?.message || 'Import failed; nothing was changed.'
    // A wrong password keeps the pop-up open so it can be typed again
    if (askPassword.value && err.response?.data?.errors?.password) {
      passwordError.value = message
      password.value = ''
    } else {
      closePasswordPrompt(true)
      errorMessage.value = message
    }
  } finally {
    busy.value = false
  }
}

function num(value) {
  return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="close">
    <div class="modal-card import-card">
      <div class="modal-header">
        <div>
          <h2 style="font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
            <FileSpreadsheet :size="20" style="color: var(--color-primary);" />
            Import Stock Cards from Excel
          </h2>
          <p class="panel-subtitle">
            <template v-if="step === 'choose'">Each sheet is read as an Appendix 58 stock card.</template>
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

          <div class="import-alert import-alert-info" style="margin-top: 1rem;">
            <Info :size="16" />
            <div>
              Every row of every card is imported with its date and reference (RIS, SI, PO…).
              Cards for the same item, such as "sugar" and "sugar (2)", become one product.
              Unit costs are set to ₱0 and can be edited on the Stockcard later.
              <strong>Nothing is saved until you confirm on the next screen.</strong>
            </div>
          </div>
        </template>

        <!-- ── Step 2: preview and options ── -->
        <template v-else-if="step === 'preview'">
          <div class="import-stats">
            <div><strong>{{ plan.summary.products }}</strong><span>products</span></div>
            <div><strong>{{ plan.summary.receipts }}</strong><span>receipts</span></div>
            <div><strong>{{ plan.summary.issues }}</strong><span>issues</span></div>
            <div><strong>{{ officeList || '—' }}</strong><span>office</span></div>
          </div>

          <div v-if="officeUnknown" class="import-alert import-alert-warning">
            <AlertTriangle :size="16" />
            <div style="flex: 1;">
              Some cards don't name a known office in "Entity Name". Choose the office for them and preview again:
              <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                <select v-model.number="fallbackOfficeId" class="form-select" style="max-width: 200px;">
                  <option :value="0">— Choose office —</option>
                  <option v-for="o in plan.offices" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-secondary" :disabled="!fallbackOfficeId || busy" @click="applyFallbackOffice">Preview again</button>
              </div>
            </div>
          </div>

          <div class="import-section-head">
            <h3 class="import-section-title">Products to import ({{ okProducts.length }})</h3>
            <div class="import-setall">
              <span class="import-muted">{{ typeSummary }}</span>
              <select v-model="setAllType" class="form-select" @change="applyTypeToAll">
                <option value="">Set all types to…</option>
                <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
          </div>
          <div class="import-hint">
            <PencilLine :size="13" />
            <span>
              Spotted a misinput? Click a card name to view and correct its rows; the file is checked again.
              <strong v-if="plan.edits">{{ plan.edits }} correction{{ plan.edits === 1 ? '' : 's' }} applied.</strong>
            </span>
          </div>
          <div v-if="spellingFixes.length" class="import-alert import-alert-info">
            <Info :size="16" />
            <span>
              Spelling corrected automatically in {{ spellingFixes.length }} item name{{ spellingFixes.length === 1 ? '' : 's' }}
              (compared with the other names in the file and your existing products). Each one is marked below.
            </span>
          </div>
          <div class="table-responsive import-table-wrap">
            <table class="data-table import-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Type</th>
                  <th>Unit</th>
                  <th>Stock No.</th>
                  <th style="text-align: right;">In</th>
                  <th style="text-align: right;">Out</th>
                  <th style="text-align: right;">Balance</th>
                  <th>Period</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in okProducts" :key="p.key">
                  <td>
                    <strong>{{ p.name }}</strong>
                    <div class="import-card-links">
                      <span class="import-muted">{{ p.cards.length > 1 ? 'Cards:' : 'Card:' }}</span>
                      <button
                        v-for="label in p.cards"
                        :key="label"
                        type="button"
                        class="import-card-link"
                        title="View and correct this card"
                        @click="editingCard = label"
                      >
                        <PencilLine :size="11" /> {{ label }}
                      </button>
                    </div>
                    <div v-for="s in p.spelling" :key="s" class="import-fix-line">
                      <SpellCheck :size="12" /> Spelling: {{ s }}
                    </div>
                    <div v-for="w in p.warnings" :key="w" class="import-warn-line">
                      <AlertTriangle :size="12" /> {{ w }}
                    </div>
                  </td>
                  <td>
                    <select v-model="types[p.key]" class="form-select import-type-select">
                      <option v-for="t in typeOptions" :key="t" :value="t">{{ t }}</option>
                    </select>
                  </td>
                  <td>{{ p.unit }}</td>
                  <td class="import-mono">{{ p.stock_no || 'auto' }}</td>
                  <td style="text-align: right;">{{ p.receipts }}</td>
                  <td style="text-align: right;">{{ p.issues + (p.adjustments || 0) }}</td>
                  <td style="text-align: right; font-weight: 700;">{{ num(p.balance) }}</td>
                  <td class="import-muted" style="white-space: nowrap;">{{ p.first_date }} → {{ p.last_date }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <template v-if="skippedProducts.length || skippedCards.length">
            <h3 class="import-section-title">Not imported ({{ skippedProducts.length + skippedCards.length }})</h3>
            <ul class="import-list">
              <li v-for="c in skippedCards" :key="c.label">
                <button type="button" class="import-card-link" title="View and correct this card" @click="editingCard = c.label">
                  <PencilLine :size="11" /> {{ c.label }}
                </button>
                — {{ c.reason }}
              </li>
              <li v-for="p in skippedProducts" :key="p.key"><strong>{{ p.name }}</strong> — {{ p.reason }}</li>
            </ul>
          </template>

          <div v-for="n in plan.notes" :key="n" class="import-alert import-alert-info">
            <Info :size="16" />
            <span>{{ n }}</span>
          </div>

          <details v-if="noteCount" class="import-notes">
            <summary>{{ noteCount }} notes about dates and rows (assumed years, missing days, skipped lines)</summary>
            <div v-for="c in cardsWithNotes" :key="c.label" class="import-notes-card">
              <button type="button" class="import-card-link is-title" title="View and correct this card" @click="editingCard = c.label">
                <PencilLine :size="12" /> {{ c.label }}
              </button>
              <ul class="import-list">
                <li v-for="w in c.warnings" :key="w">{{ w }}</li>
              </ul>
            </div>
          </details>

          <h3 class="import-section-title">Import options</h3>
          <fieldset class="import-choice">
            <legend class="form-label">Existing inventory of {{ officeList }} *</legend>

            <label class="import-option" :class="{ 'is-selected': mode === 'replace', 'is-danger': mode === 'replace' }">
              <input v-model="mode" type="radio" name="importMode" value="replace" />
              <div>
                <strong><Trash2 :size="14" /> Delete existing inventory first, then import</strong>
                <small>
                  Removes {{ num(existing.products) }} products, {{ num(existing.batches) }} batches,
                  {{ num(existing.transactions) }} transactions and {{ num(existing.requests) }} stock-out requests of {{ officeList }}.
                  Users, offices, units, references and settings are kept.
                </small>
              </div>
            </label>

            <label class="import-option" :class="{ 'is-selected': mode === 'append' }">
              <input v-model="mode" type="radio" name="importMode" value="append" />
              <div>
                <strong><Plus :size="14" /> Keep existing data and add the Excel entries</strong>
                <small>Products with the same name and unit are reused. Rows imported before are skipped, so importing the same file twice adds nothing.</small>
              </div>
            </label>
          </fieldset>

          <div v-if="mode === 'replace'" class="import-confirm">
            <div class="import-alert import-alert-info" style="margin-bottom: 0.75rem;">
              <ShieldCheck :size="16" />
              <span>A backup of {{ officeList }} is made automatically before anything is deleted. It can be restored from <strong>Others Management → Data Backup &amp; Restore</strong>.</span>
            </div>
            <p class="import-muted import-password-note">
              <Lock :size="13" />
              {{ isManager ? 'You will be asked for your password before anything is deleted.' : 'A manager of your office has to enter their password before anything is deleted.' }}
            </p>
          </div>
        </template>

        <!-- ── Step 3: result ── -->
        <template v-else-if="result">
          <div class="import-alert import-alert-success">
            <CheckCircle2 :size="18" />
            <div>
              <strong>Imported into {{ result.offices.join(', ') }}.</strong>
              <div v-if="result.backups.length">Backup made first: {{ result.backups.join(', ') }}.</div>
              <div v-if="result.deleted">
                Deleted beforehand: {{ num(result.deleted.products) }} products, {{ num(result.deleted.transactions) }} transactions.
              </div>
            </div>
          </div>
          <div class="import-stats">
            <div><strong>{{ result.products_created }}</strong><span>products created</span></div>
            <div><strong>{{ result.products_reused }}</strong><span>products reused</span></div>
            <div><strong>{{ result.receipts }}</strong><span>receipts</span></div>
            <div><strong>{{ result.issues }}</strong><span>issues</span></div>
            <div v-if="result.adjustments"><strong>{{ result.adjustments }}</strong><span>count adjustments</span></div>
          </div>
          <p v-if="result.already_imported" class="import-muted">
            {{ result.already_imported }} rows were already in the system from an earlier import and were skipped.
          </p>
          <p class="import-muted">Unit costs were imported as ₱0. Set them on the Stockcard with "Edit report cost" when you have them.</p>
        </template>
      </div>

      <div class="modal-footer">
        <template v-if="step === 'choose'">
          <button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="!file || busy" @click="runPreview">
            {{ busy ? 'Reading file…' : 'Preview' }}
          </button>
        </template>

        <template v-else-if="step === 'preview'">
          <button type="button" class="btn btn-secondary" :disabled="busy" style="margin-right: auto;" @click="reset">
            <ArrowLeft :size="15" /> Choose another file
          </button>
          <button
            type="button"
            class="btn btn-primary"
            :class="{ 'import-btn-danger': mode === 'replace' }"
            :disabled="!canImport"
            @click="startImport"
          >
            <template v-if="busy">Importing… this can take a minute</template>
            <template v-else-if="mode === 'replace'">Delete &amp; Import {{ okProducts.length }} products</template>
            <template v-else>Import {{ okProducts.length }} products</template>
          </button>
        </template>

        <template v-else>
          <button type="button" class="btn btn-primary" @click="close">Done</button>
        </template>
      </div>
    </div>

    <!-- Password check before deleting the existing inventory -->
    <div v-if="askPassword" class="modal-backdrop import-password-backdrop" @click.self="closePasswordPrompt()">
      <form class="modal-card import-password-card" role="alertdialog" aria-labelledby="importPasswordTitle" @submit.prevent="confirmWithPassword">
        <div class="import-password-head">
          <span class="import-password-icon"><Trash2 :size="22" /></span>
          <h2 id="importPasswordTitle">Delete existing inventory?</h2>
          <p>
            This removes <strong>{{ num(existing.products) }} products</strong>, {{ num(existing.batches) }} batches,
            {{ num(existing.transactions) }} transactions and {{ num(existing.requests) }} stock-out requests of
            <strong>{{ officeList }}</strong>, then imports {{ okProducts.length }} products from the Excel file.
          </p>
        </div>

        <div class="import-password-body">
          <div class="import-alert import-alert-info" style="margin-bottom: 1rem;">
            <ShieldCheck :size="16" />
            <span>A backup is made automatically first and can be restored from Data Backup &amp; Restore.</span>
          </div>

          <label class="form-label" for="importPassword">{{ isManager ? 'Enter your password to confirm' : 'Manager: enter your password to approve' }}</label>
          <div class="import-password-field">
            <input
              id="importPassword"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              class="form-input"
              :class="{ 'is-invalid': passwordError }"
              autocomplete="current-password"
              :placeholder="isManager ? 'Your password' : 'Manager\'s password'"
              :disabled="busy"
              autofocus
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

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="busy" @click="closePasswordPrompt()">Cancel</button>
          <button type="submit" class="btn btn-primary import-btn-danger" :disabled="busy || !password">
            {{ busy ? 'Deleting & importing…' : 'Delete & Import' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Correcting one card's misinputs -->
    <StockcardCardEditor
      v-if="editingCard && token"
      :token="token"
      :label="editingCard"
      :warnings="notesFor(editingCard)"
      @close="editingCard = null"
      @applied="onCardApplied"
    />
  </div>
</template>

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

.import-table thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: var(--bg-subtle);
}

.import-mono {
  font-family: var(--font-mono);
  font-size: 0.75rem;
}

.import-section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin: 1.25rem 0 0.6rem;
}

.import-section-head .import-section-title {
  margin: 0;
}

.import-setall {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.import-setall .form-select {
  width: auto;
  min-width: 180px;
  padding-top: 0.35rem;
  padding-bottom: 0.35rem;
  font-size: 0.82rem;
}

.import-type-select {
  min-width: 150px;
  padding: 0.3rem 0.5rem;
  font-size: 0.8rem;
}

.import-fix-line {
  display: flex;
  gap: 0.3rem;
  align-items: flex-start;
  margin-top: 0.2rem;
  font-size: 0.75rem;
  color: var(--color-info);
}

.import-hint {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.75rem;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.import-hint strong {
  color: var(--color-info);
}

.import-card-links {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.15rem;
}

.import-card-link {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.05rem 0.4rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-full);
  background: var(--bg-subtle);
  color: var(--color-primary);
  font: inherit;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
}

.import-card-link:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.import-card-link.is-title {
  font-size: 0.85rem;
  padding: 0.1rem 0.55rem;
}

.import-warn-line {
  display: flex;
  gap: 0.3rem;
  align-items: flex-start;
  margin-top: 0.2rem;
  font-size: 0.75rem;
  color: var(--color-warning);
}

.import-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.82rem;
  color: var(--text-muted);
  line-height: 1.6;
}

.import-notes {
  margin-top: 1rem;
  font-size: 0.85rem;
}

.import-notes summary {
  cursor: pointer;
  color: var(--color-primary);
  font-weight: 600;
}

.import-notes-card {
  margin-top: 0.6rem;
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

.import-password-note {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
}

/* ── Password pop-up ── */
.import-password-backdrop {
  z-index: 1100;
}

.import-password-card {
  max-width: 440px;
  width: calc(100vw - 32px);
}

.import-password-head {
  padding: 1.5rem 1.5rem 0.5rem;
  text-align: center;
}

.import-password-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  margin-bottom: 0.75rem;
  border-radius: 50%;
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

.import-password-head h2 {
  margin: 0 0 0.4rem;
  font-size: 1.15rem;
}

.import-password-head p {
  margin: 0;
  font-size: 0.85rem;
  line-height: 1.55;
  color: var(--text-muted);
}

.import-password-head strong {
  color: var(--text-main);
}

.import-password-body {
  padding: 1rem 1.5rem 1.25rem;
}

.import-password-field {
  position: relative;
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
