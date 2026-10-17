<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import {
  X, FileSpreadsheet, Upload, AlertTriangle, CheckCircle2, Info, Trash2, Plus, ShieldCheck, SpellCheck, PencilLine,
  Lock, Eye, EyeOff, OctagonAlert, RotateCcw,
} from 'lucide-vue-next'
import { importApi } from '../api/import'
import StockcardCardEditor from './StockcardCardEditor.vue'
import { triggerAutoReload } from '../composables/useAutoReload'
import { confirmDialog } from '../composables/useConfirm'
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
// Sheets that are not stock cards at all (a cover page, an unused template) are only listed
const skippedCards = computed(() => (plan.value?.cards || []).filter((c) => c.status !== 'ok' && !c.ignored))
const ignoredCards = computed(() => (plan.value?.cards || []).filter((c) => c.status !== 'ok' && c.ignored))
const officeUnknown = computed(() => skippedCards.value.some((c) => /office/i.test(c.reason)))

// ── Problems and guesses ──
// The server sorts every message (StockcardImportService::issues): an error is wrong or missing
// stock that must be corrected, or its product left out; an assumption is a guess the reader
// already made (a missing day, a merged card…), confirmed with one click per group. The server
// checks the same rules again on import.
const issues = computed(() => plan.value?.issues || [])
const errors = computed(() => issues.value.filter((i) => i.severity === 'error'))
const assumptions = computed(() => issues.value.filter((i) => i.severity === 'assumption'))
// A problem with the whole file can't be left out with a product
const fileErrors = computed(() => errors.value.filter((i) => !i.card))

const ISSUE_KINDS = [
  { kind: 'negative', one: 'row has negative stock', many: 'rows have negative stock' },
  { kind: 'missing', one: 'card or product not readable', many: 'cards or products not readable' },
  { kind: 'balance', one: "balance doesn't add up", many: "balances don't add up" },
  { kind: 'date', one: 'date problem', many: 'date problems' },
  { kind: 'other', one: 'other problem', many: 'other problems' },
]

// "3 rows have negative stock": one line per kind of error found
const issueSummary = computed(() =>
  ISSUE_KINDS.map((k) => {
    const count = errors.value.filter((i) => i.kind === k.kind).length
    return count ? { ...k, count, text: `${count} ${count === 1 ? k.one : k.many}` } : null
  }).filter(Boolean)
)

// A list of messages grouped under the card they are about (file-wide ones last)
function byCard(list) {
  const groups = new Map()
  for (const issue of list) {
    const key = issue.card || ''
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(issue)
  }
  return [...groups.entries()]
    .sort(([a], [b]) => Number(a === '') - Number(b === ''))
    .map(([card, items]) => ({ card, list: [...items].sort((x, y) => (x.row ?? 0) - (y.row ?? 0)) }))
}
const errorsByCard = computed(() => byCard(errors.value))

// Leave out the products that have errors and import the rest
const skipErrors = ref(false)
const productOfCard = computed(() => {
  const map = {}
  for (const p of okProducts.value) for (const label of p.cards) map[label] = p.key
  return map
})
const errorProductKeys = computed(() => new Set(errors.value.map((i) => productOfCard.value[i.card]).filter(Boolean)))
const isLeftOut = (p) => skipErrors.value && errorProductKeys.value.has(p.key)
const productsToImport = computed(() => okProducts.value.filter((p) => !isLeftOut(p)))

const GROUPS = {
  date: { name: 'Date guesses', hint: 'A missing day, month or year was filled in from the rows around it.' },
  order: { name: 'Dates out of order', hint: 'A row is dated before the row above it. Entries are imported in date order.' },
  column: { name: 'Values in the wrong column', hint: 'A reference in the Date column, or a count typed under Issue, was moved where it belongs.' },
  merged: { name: 'Cards merged into one product', hint: 'A continuation card for the same item was added to the earlier card.' },
  correction: { name: 'Stock corrected to match the card', hint: "Where the card's carried-forward figure differs from the entries, a receipt or adjustment was added so the stock matches the card." },
  unit: { name: 'Units filled in', hint: 'Cards without a unit of measurement use "pcs".' },
}
// Guesses about products being left out, or cards that import nothing, don't need confirming
const relevantAssumption = (i) => !i.card || (productOfCard.value[i.card] && !isLeftOut({ key: productOfCard.value[i.card] }))
const assumptionGroups = computed(() =>
  Object.entries(GROUPS)
    .map(([group, info]) => {
      const all = assumptions.value.filter((i) => i.group === group)
      const list = all.filter(relevantAssumption)
      return list.length ? { group, ...info, list, byCard: byCard(list), total: all.length } : null
    })
    .filter(Boolean)
)
// An accepted group remembers how many guesses it had: new ones (after a correction) ask again
const accepted = ref({})
const isAccepted = (g) => accepted.value[g.group] === g.total
function acceptGroup(g) {
  accepted.value = { ...accepted.value, [g.group]: g.total }
}
function undoGroup(g) {
  const next = { ...accepted.value }
  delete next[g.group]
  accepted.value = next
}
function acceptAllGroups() {
  for (const g of assumptionGroups.value) acceptGroup(g)
}
const pendingGroups = computed(() => assumptionGroups.value.filter((g) => !isAccepted(g)))
const pendingGuesses = computed(() => pendingGroups.value.reduce((n, g) => n + g.list.length, 0))

// Why the import is locked (empty when it isn't)
const lockReason = computed(() => {
  if (fileErrors.value.length) return 'Fix the problem with the whole file'
  if (errors.value.length && !skipErrors.value) {
    return `Fix ${errors.value.length} problem${errors.value.length === 1 ? '' : 's'}, or leave those products out`
  }
  if (!productsToImport.value.length) return 'Nothing left to import'
  if (pendingGroups.value.length) return `Accept ${pendingGuesses.value} guess${pendingGuesses.value === 1 ? '' : 'es'}`
  return ''
})
const blocked = computed(() => lockReason.value !== '')

const cardIssues = (label) => issues.value.filter((i) => i.card === label)
const groupAccepted = (group) => accepted.value[group] === assumptions.value.filter((i) => i.group === group).length
function cardTone(label) {
  const list = cardIssues(label)
  if (list.some((i) => i.severity === 'error')) return 'is-danger'
  return list.some((i) => i.severity === 'assumption' && !groupAccepted(i.group)) ? 'is-warning' : ''
}
// A product row takes the strongest tone of its cards
function productTone(p) {
  if (isLeftOut(p)) return 'is-left-out'
  const tones = p.cards.map(cardTone)
  return tones.includes('is-danger') ? 'is-danger' : tones.includes('is-warning') ? 'is-warning' : ''
}

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

// A blank category falls back to the detected one (as the server does)
const typeOf = (p) => String(types.value[p.key] || '').trim() || p.type

// "12 Product Label, 3 Raw Materials" for the current choices
const typeSummary = computed(() => {
  const counts = {}
  for (const p of okProducts.value) {
    const t = typeOf(p)
    counts[t] = (counts[t] || 0) + 1
  }
  return Object.entries(counts).map(([t, n]) => `${n} ${t}`).join(', ')
})

function applyTypeToAll() {
  const name = setAllType.value.trim()
  if (!name) return
  for (const p of okProducts.value) types.value[p.key] = name
  setAllType.value = ''
}

const canImport = computed(() =>
  !busy.value &&
  !blocked.value &&
  productsToImport.value.length > 0 &&
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
  skipErrors.value = false
  accepted.value = {}
  result.value = null
  editingCard.value = null
  if (fileInput.value) fileInput.value.value = ''
}

watch(() => props.isOpen, (open) => {
  if (open) reset()
})

// Cancel & Re-upload: drop this preview and open the file picker for the corrected file
async function reupload() {
  if (busy.value) return
  reset()
  await nextTick()
  fileInput.value?.click()
}

// The X, a click outside and Cancel: a preview (and any corrections made in it) is only
// thrown away after asking; nothing has been imported yet at that point
async function close() {
  if (busy.value) return
  if (step.value === 'preview') {
    const corrections = Number(plan.value?.edits || 0)
    const ok = await confirmDialog({
      title: 'Discard this import?',
      message: `Nothing has been imported yet. The preview${corrections ? ` and the ${corrections} correction${corrections === 1 ? '' : 's'} made in it` : ''} will be lost, and the file has to be chosen again.`,
      details: [
        ['File', plan.value?.file_name],
        ['Products', okProducts.value.length],
        ['Problems', errors.value.length || ''],
        ['Guesses', assumptions.value.length || ''],
      ],
      confirmText: 'Discard import',
      cancelText: 'Keep reviewing',
      variant: 'warning',
    })
    if (!ok) return
  }
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
async function startImport() {
  if (!canImport.value) return
  if (mode.value === 'replace') return openPasswordPrompt()
  const ok = await confirmDialog({
    title: 'Import these stock cards?',
    message: 'The stock cards will be added. Your existing records are kept.',
    details: [
      ['File', plan.value?.file_name],
      ['Products', productsToImport.value.length],
      ['Left out (problems)', okProducts.value.length - productsToImport.value.length || ''],
      ['Offices', officeList.value],
      ['Categories', typeSummary.value],
    ],
    confirmText: 'Import',
  })
  if (ok) runImport()
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
      accept: assumptionGroups.value.filter(isAccepted).map((g) => g.group),
      skip_errors: skipErrors.value,
    })
    result.value = res.data
    step.value = 'done'
    closePasswordPrompt(true)
    triggerAutoReload('stock-import')
    emit('imported')
  } catch (err) {
    const message = err.response?.data?.message || 'Import failed; nothing was changed.'
    // The server found warnings the preview didn't show yet: show them and keep the import locked
    const serverIssues = err.response?.data?.errors?.issues
    if (Array.isArray(serverIssues) && plan.value) plan.value = { ...plan.value, issues: serverIssues }
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
          <div v-if="errors.length" class="import-blocked" role="alert">
            <div class="import-blocked-head">
              <OctagonAlert :size="22" />
              <div>
                <strong>{{ errors.length }} problem{{ errors.length === 1 ? '' : 's' }} to fix</strong>
                <template v-if="fileErrors.length">One concerns the whole file, so it has to be corrected before anything can be imported.</template>
                <template v-else>
                  in {{ errorProductKeys.size || 'some' }} product{{ errorProductKeys.size === 1 ? '' : 's' }}.
                  Correct them, or leave those products out and import the rest.
                </template>
              </div>
            </div>
            <ul class="import-blocked-summary">
              <li v-for="k in issueSummary" :key="k.kind" class="is-danger">{{ k.text }}</li>
            </ul>
            <div class="import-blocked-actions">
              <span>Click a card below to correct its rows here, or fix the Excel file and upload it again.</span>
              <button type="button" class="btn btn-sm import-btn-reupload" :disabled="busy" @click="reupload">
                <RotateCcw :size="14" /> Cancel &amp; Re-upload
              </button>
            </div>
          </div>
          <div v-else-if="pendingGroups.length" class="import-alert import-alert-warning">
            <AlertTriangle :size="16" />
            <span>
              <strong>No problems.</strong> The reader made {{ pendingGuesses }} guess{{ pendingGuesses === 1 ? '' : 'es' }} while reading the file:
              check and accept them below.
            </span>
          </div>
          <div v-else class="import-alert import-alert-success">
            <CheckCircle2 :size="16" />
            <span><strong>Ready to import.</strong> Choose how below.</span>
          </div>

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
            <h3 class="import-section-title">
              Products to import ({{ productsToImport.length }}<template v-if="productsToImport.length !== okProducts.length"> of {{ okProducts.length }}</template>)
            </h3>
            <div class="import-setall">
              <span class="import-muted">{{ typeSummary }}</span>
              <div class="import-setall-field">
                <input
                  v-model="setAllType"
                  type="text"
                  class="form-input"
                  list="import-type-options"
                  maxlength="100"
                  placeholder="Set all categories to…"
                  aria-label="Category for every product"
                  @keydown.enter.prevent="applyTypeToAll"
                />
                <button type="button" class="btn btn-sm btn-secondary" :disabled="!setAllType.trim()" @click="applyTypeToAll">Apply</button>
              </div>
              <datalist id="import-type-options">
                <option v-for="t in typeOptions" :key="t" :value="t" />
              </datalist>
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
                <tr v-for="p in okProducts" :key="p.key" :class="productTone(p)">
                  <td>
                    <strong>{{ p.name }}</strong>
                    <span v-if="isLeftOut(p)" class="import-left-out-badge">Left out</span>
                    <div class="import-card-links">
                      <span class="import-muted">{{ p.cards.length > 1 ? 'Cards:' : 'Card:' }}</span>
                      <button
                        v-for="label in p.cards"
                        :key="label"
                        type="button"
                        class="import-card-link"
                        :class="cardTone(label)"
                        :title="cardIssues(label).length ? `${cardIssues(label).length} warning(s): click to correct` : 'View and correct this card'"
                        @click="editingCard = label"
                      >
                        <component :is="cardIssues(label).length ? AlertTriangle : PencilLine" :size="11" /> {{ label }}
                        <span v-if="cardIssues(label).length" class="import-card-count">{{ cardIssues(label).length }}</span>
                      </button>
                    </div>
                    <div v-for="s in p.spelling" :key="s" class="import-fix-line">
                      <SpellCheck :size="12" /> Spelling: {{ s }}
                    </div>
                  </td>
                  <td>
                    <input
                      v-model="types[p.key]"
                      type="text"
                      class="form-input import-type-select"
                      list="import-type-options"
                      maxlength="100"
                      :placeholder="p.type"
                      :aria-label="`Category for ${p.name}`"
                    />
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

          <template v-if="errors.length">
            <h3 class="import-section-title import-issues-title">
              <AlertTriangle :size="15" /> Problems to fix ({{ errors.length }})
            </h3>
            <div class="import-issues">
              <div v-for="g in errorsByCard" :key="g.card || '__file'" class="import-issue-card">
                <button
                  v-if="g.card"
                  type="button"
                  class="import-card-link is-title"
                  :class="cardTone(g.card)"
                  title="View and correct this card"
                  @click="editingCard = g.card"
                >
                  <PencilLine :size="12" /> {{ g.card }}
                </button>
                <strong v-else class="import-issue-file">Whole file</strong>
                <ul class="import-issue-list">
                  <li v-for="(i, n) in g.list" :key="n" class="is-danger">
                    <span v-if="i.row" class="import-issue-row">Row {{ i.row }}</span>
                    <span>{{ i.message }}</span>
                  </li>
                </ul>
              </div>
            </div>
          </template>

          <template v-if="assumptionGroups.length">
            <div class="import-section-head">
              <h3 class="import-section-title import-guesses-title">
                <Info :size="15" /> Guesses to confirm ({{ assumptionGroups.reduce((n, g) => n + g.list.length, 0) }})
              </h3>
              <button v-if="pendingGroups.length > 1" type="button" class="btn btn-sm btn-secondary" @click="acceptAllGroups">
                <CheckCircle2 :size="14" /> Accept all guesses
              </button>
            </div>
            <div class="import-guess-groups">
              <div v-for="g in assumptionGroups" :key="g.group" class="import-guess-group" :class="{ 'is-accepted': isAccepted(g) }">
                <div class="import-guess-head">
                  <div>
                    <strong>{{ g.name }} ({{ g.list.length }})</strong>
                    <span class="import-muted">{{ g.hint }}</span>
                  </div>
                  <button v-if="!isAccepted(g)" type="button" class="btn btn-sm btn-primary" @click="acceptGroup(g)">
                    Accept {{ g.list.length === 1 ? 'it' : `all ${g.list.length}` }}
                  </button>
                  <span v-else class="import-guess-done">
                    <CheckCircle2 :size="14" /> Accepted
                    <button type="button" class="import-guess-undo" @click="undoGroup(g)">Undo</button>
                  </span>
                </div>
                <details class="import-guess-details">
                  <summary>Show {{ g.list.length === 1 ? 'it' : `all ${g.list.length}` }}</summary>
                  <div v-for="c in g.byCard" :key="c.card || '__file'" class="import-issue-card">
                    <button
                      v-if="c.card"
                      type="button"
                      class="import-card-link is-title"
                      title="View and correct this card"
                      @click="editingCard = c.card"
                    >
                      <PencilLine :size="12" /> {{ c.card }}
                    </button>
                    <strong v-else class="import-issue-file">Whole file</strong>
                    <ul class="import-issue-list">
                      <li v-for="(i, n) in c.list" :key="n" class="is-warning">
                        <span v-if="i.row" class="import-issue-row">Row {{ i.row }}</span>
                        <span>{{ i.message }}</span>
                      </li>
                    </ul>
                  </div>
                </details>
              </div>
            </div>
          </template>

          <p v-if="ignoredCards.length" class="import-muted import-ignored">
            <Info :size="13" />
            <span>
              Not stock cards, ignored: {{ ignoredCards.map((c) => c.label).join(', ') }}
              (no Date / Receipt / Issue / Balance columns, or no entries).
            </span>
          </p>

          <h3 class="import-section-title">Import options</h3>
          <label v-if="errors.length && !fileErrors.length" class="import-option import-skip-option" :class="{ 'is-selected': skipErrors }">
            <input v-model="skipErrors" type="checkbox" />
            <div>
              <strong>Leave out the {{ errorProductKeys.size }} product{{ errorProductKeys.size === 1 ? '' : 's' }} with problems and import the other {{ okProducts.length - errorProductKeys.size }}</strong>
              <small>
                Correct them in the Excel file later and import it again with "Keep existing data":
                rows already imported are skipped, so nothing is added twice.
              </small>
            </div>
          </label>
          <fieldset class="import-choice" :disabled="blocked" :class="{ 'is-locked': blocked }">
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
              <span>A backup of {{ officeList }} is made automatically before anything is deleted. It can be restored from <strong>Office Settings → Data Backup &amp; Restore</strong>.</span>
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
          <div v-if="result.left_out?.length" class="import-alert import-alert-warning">
            <AlertTriangle :size="16" />
            <div>
              <strong>Left out because of problems ({{ result.left_out.length }}):</strong>
              {{ result.left_out.join(', ') }}.
              Correct them in the Excel file and import it again with "Keep existing data".
            </div>
          </div>
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
          <button type="button" class="btn btn-secondary" :disabled="busy" style="margin-right: auto;" @click="reupload">
            <RotateCcw :size="15" /> Cancel &amp; Re-upload
          </button>
          <span v-if="blocked" class="import-locked-note">
            <Lock :size="13" /> {{ lockReason }} to import
          </span>
          <button
            type="button"
            class="btn btn-primary"
            :class="{ 'import-btn-danger': mode === 'replace' && !blocked }"
            :disabled="!canImport"
            :title="blocked ? `Import locked: ${lockReason}.` : undefined"
            @click="startImport"
          >
            <template v-if="busy">Importing… this can take a minute</template>
            <template v-else-if="blocked"><Lock :size="14" /> Import locked</template>
            <template v-else-if="mode === 'replace'">Delete &amp; Import {{ productsToImport.length }} products</template>
            <template v-else>Import {{ productsToImport.length }} products</template>
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
            <strong>{{ officeList }}</strong>, then imports {{ productsToImport.length }} products from the Excel file.
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
      :issues="cardIssues(editingCard)"
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

.import-setall-field {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.import-setall .form-input {
  width: 220px;
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

.import-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.82rem;
  color: var(--text-muted);
  line-height: 1.6;
}

/* ── Strict preview: the red banner, the warnings list and the highlighted rows ── */
.import-blocked {
  margin-bottom: 1rem;
  padding: 1rem 1.1rem;
  border: 2px solid var(--color-danger);
  border-radius: var(--radius-md);
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

.import-blocked-head {
  display: flex;
  gap: 0.7rem;
  align-items: flex-start;
  font-size: 0.9rem;
  line-height: 1.5;
}

.import-blocked-head svg {
  flex-shrink: 0;
}

.import-blocked-head strong {
  display: block;
  font-size: 1rem;
}

.import-blocked-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.import-blocked-summary li {
  padding: 0.2rem 0.65rem;
  border: 1px solid currentColor;
  border-radius: var(--radius-full);
  background: var(--bg-surface);
  font-size: 0.78rem;
  font-weight: 700;
}

.import-blocked-summary li.is-danger { color: var(--color-danger); }
.import-blocked-summary li.is-warning { color: var(--color-warning); }

.import-blocked-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 0.75rem;
  font-size: 0.8rem;
  color: var(--text-main);
}

.import-btn-reupload {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  border-color: var(--color-danger);
  background: var(--color-danger);
  color: #fff;
}

.import-btn-reupload:hover:not(:disabled) {
  filter: brightness(1.08);
}

.import-issues-title {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--color-danger);
}

/* ── Guesses to confirm: one block per group, accepted with one click ── */
.import-guesses-title {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--color-warning);
}

.import-guess-groups {
  display: grid;
  gap: 0.6rem;
}

.import-guess-group {
  padding: 0.75rem 1rem;
  border: 1.5px solid var(--color-warning);
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
}

.import-guess-group.is-accepted {
  border-color: var(--border-subtle);
  background: var(--bg-subtle);
}

.import-guess-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.import-guess-head strong {
  display: block;
  font-size: 0.9rem;
  color: var(--text-main);
}

.import-guess-head .btn {
  flex-shrink: 0;
}

.import-guess-done {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--color-success);
}

.import-guess-undo {
  border: 0;
  background: none;
  padding: 0 0.2rem;
  color: var(--text-muted);
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
  cursor: pointer;
}

.import-guess-details {
  margin-top: 0.5rem;
  font-size: 0.82rem;
}

.import-guess-details summary {
  cursor: pointer;
  color: var(--color-primary);
  font-weight: 600;
}

.import-guess-details .import-issue-card {
  margin-top: 0.6rem;
}

/* ── Products left out because of problems ── */
.import-skip-option {
  margin-bottom: 0.75rem;
}

.import-table tbody tr.is-left-out td {
  opacity: 0.5;
}

.import-left-out-badge {
  display: inline-block;
  margin-left: 0.4rem;
  padding: 0.05rem 0.45rem;
  border-radius: var(--radius-full);
  background: var(--color-danger-bg);
  color: var(--color-danger);
  font-size: 0.7rem;
  font-weight: 700;
  vertical-align: 1px;
}

.import-issues {
  display: grid;
  gap: 0.75rem;
  max-height: 340px;
  overflow: auto;
  padding: 0.75rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
}

.import-issue-file {
  font-size: 0.85rem;
}

.import-issue-list {
  display: grid;
  gap: 0.3rem;
  margin: 0.4rem 0 0;
  padding: 0;
  list-style: none;
  font-size: 0.8rem;
  line-height: 1.45;
}

.import-issue-list li {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  padding: 0.35rem 0.6rem;
  border-left: 3px solid currentColor;
  border-radius: var(--radius-xs);
}

.import-issue-list li > span:last-child {
  color: var(--text-main);
}

.import-issue-list li.is-danger { color: var(--color-danger); background: var(--color-danger-bg); }
.import-issue-list li.is-warning { color: var(--color-warning); background: var(--color-warning-bg); }

.import-issue-row {
  flex-shrink: 0;
  font-family: var(--font-mono);
  font-size: 0.72rem;
  font-weight: 700;
}

.import-table tbody tr.is-danger td { background: var(--color-danger-bg); }
.import-table tbody tr.is-warning td { background: var(--color-warning-bg); }
.import-table tbody tr.is-danger td:first-child { box-shadow: inset 4px 0 0 var(--color-danger); }
.import-table tbody tr.is-warning td:first-child { box-shadow: inset 4px 0 0 var(--color-warning); }

.import-card-link.is-danger { border-color: var(--color-danger); background: var(--bg-surface); color: var(--color-danger); }
.import-card-link.is-warning { border-color: var(--color-warning); background: var(--bg-surface); color: var(--color-warning); }

.import-card-count {
  min-width: 1.1rem;
  padding: 0 0.3rem;
  border-radius: var(--radius-full);
  color: #fff;
  font-size: 0.68rem;
  text-align: center;
}

.import-card-link.is-danger .import-card-count { background: var(--color-danger); }
.import-card-link.is-warning .import-card-count { background: var(--color-warning); }

.import-ignored {
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
  margin: 0.75rem 0 0;
}

.import-choice.is-locked {
  opacity: 0.55;
}

.import-choice.is-locked .import-option {
  cursor: not-allowed;
}

.import-locked-note {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-danger);
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
