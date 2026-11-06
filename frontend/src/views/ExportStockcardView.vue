<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted, defineAsyncComponent } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { exportApi, triggerBlobDownload } from '../api/export'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'
import { useUnsavedChanges } from '../composables/useUnsavedChanges'
import { useThemeStore } from '../stores/themeStore'
import {
  AlertTriangle,
  ArrowDownWideNarrow,
  ArrowLeft,
  ArrowRight,
  ArrowUpDown,
  ArrowUpNarrowWide,
  Calendar,
  Check,
  ClipboardList,
  File,
  FilePen,
  FileText,
  Info,
  Layers,
  Package,
  PencilLine,
  RefreshCw,
  Save,
  Settings,
} from 'lucide-vue-next'

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// Opened from the Stockcard Ledger, which passes what is already chosen there:
//   ?scope=item&product_id=&product=          one item's card
//   ?scope=category&type_id=&category=        every item of a category (type_id 0: all items)
//   &month=MM&year=YYYY&order=latest|oldest   the ledger's period and order
const route = useRoute()
const router = useRouter()
const q = route.query
const scope = q.scope === 'category' ? 'category' : 'item'
const productId = Number(q.product_id) || 0
const productName = String(q.product || '')
const typeId = Number(q.type_id) || 0
const categoryName = String(q.category || '')
const month = /^(0[1-9]|1[0-2])$/.test(String(q.month || '')) ? String(q.month) : ''
const year = /^\d{4}$/.test(String(q.year || '')) ? String(q.year) : ''
const ledgerOrder = q.order === 'oldest' ? 'oldest' : 'latest'

// The ledger's period as a range: a month of a year, a whole year, or everything on record
const period = (() => {
  if (month && year) return { month_from: `${year}-${month}`, month_to: `${year}-${month}`, label: `${MONTH_NAMES[Number(month) - 1]} ${year}` }
  if (year) return { month_from: `${year}-01`, month_to: `${year}-12`, label: `January – December ${year}` }
  if (month) return null // a month of any year cannot be printed as one range
  return { all_time: 1, label: 'All months on record' }
})()

const form = reactive({
  product_id: scope === 'item' ? productId : 0,
  type_id: scope === 'category' ? typeId : 0,
  month_from: period?.month_from || '',
  month_to: period?.month_to || '',
  all_time: period?.all_time || 0,
  sort_order: ledgerOrder === 'oldest' ? 'ASC' : 'DESC',
  paper_size: 'long',
})

const scopeLabel = computed(() => {
  if (scope === 'item') return productName || 'Selected item'
  return categoryName ? `All items in ${categoryName}` : 'All items'
})

// Back to the ledger as it was: same item, period, order and category
const backLink = computed(() => {
  const query = {}
  if (Number(q.back_item) > 0) query.item_id = Number(q.back_item)
  else if (productId) query.item_id = productId
  if (month) query.month = month
  if (year) query.year = year
  query.order = ledgerOrder
  if (categoryName) query.category = categoryName
  return { name: 'stockcard', query }
})

const PAPER_SIZES = [
  ['long', 'Long', '8.5″ × 13″ Folio', FileText],
  ['short', 'Short', '8.5″ × 11″ Letter', File],
  ['a4', 'A4', '8.27″ × 11.69″ (210 × 297 mm)', File],
]
const ORDERS = [
  ['ASC', 'Ascending', 'Oldest first (Ledger flow)', ArrowUpNarrowWide],
  ['DESC', 'Descending', 'Newest first', ArrowDownWideNarrow],
]
const paperLabel = computed(() => {
  const [, name, size] = PAPER_SIZES.find(([v]) => v === form.paper_size) || PAPER_SIZES[0]
  return `${name} (${size.replace(' Folio', '').replace(' Letter', '').replace(/ \(.*\)/, '')})`
})
const orderLabel = computed(() => (form.sort_order === 'ASC' ? 'Ascending (Oldest first)' : 'Descending (Newest first)'))

const downloading = ref('')
const formError = ref('')

// ── The stock cards, editable (TinyMCE, downloaded only when this page opens) ──
const ReportEditor = defineAsyncComponent(() => import('../components/ReportEditor.vue'))
const themeStore = useThemeStore()

const loadingPreview = ref(false)
const previewError = ref('')
const loadedOnce = ref(false)   // the editor stays mounted from then on; a rebuild only swaps its content
const cardCount = ref(0)
let replacing = false           // content set by the page itself, not typed by the user
const reportHtml = ref('')
const savedHtml = ref('')
const savedAt = ref(null)
const editorReady = ref(false)
const edited = ref(false)
const builtOrder = ref(form.sort_order) // the order the cards on screen were built in

watch(reportHtml, () => {
  if (editorReady.value && !replacing) edited.value = true
})

const unsaved = computed(() => reportHtml.value !== savedHtml.value)
const hasReport = computed(() => loadedOnce.value && !previewError.value)

// Set once the user has confirmed leaving, so the leave guard below does not ask a second time
let leaving = false
// Downloaded and not changed since: nothing is lost by going back
const downloadedClean = ref(false)
watch(reportHtml, () => {
  if (editorReady.value && !replacing) downloadedClean.value = false
})

useUnsavedChanges(() => edited.value && !leaving, {
  message: 'Your edits to the stock card are kept only on this page. Download it first if you need them.',
})

function saveEdits(quiet = false) {
  savedHtml.value = reportHtml.value
  savedAt.value = new Date()
  if (!quiet) toast('Changes saved. PDF and Word use this version.')
}

const savedLabel = computed(() =>
  savedAt.value ? `Saved at ${savedAt.value.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}` : 'No changes yet'
)

// Pictures are kept inside the card (data: URIs) so the PDF needs no files
function pickImage(callback) {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/png,image/jpeg,image/gif'
  input.onchange = () => {
    const file = input.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => callback(reader.result, { alt: file.name })
    reader.readAsDataURL(file)
  }
  input.click()
}

const editorInit = computed(() => ({
  height: 760,
  menubar: 'edit view insert format table',
  plugins: 'advlist lists link image table charmap searchreplace visualblocks code fullscreen pagebreak',
  toolbar:
    'undo redo | blocks fontsize | bold italic underline | forecolor backcolor | ' +
    'alignleft aligncenter alignright | bullist numlist | table image pagebreak | removeformat | code fullscreen',
  font_size_formats: '8pt 9pt 10pt 11pt 12pt 14pt 15pt 18pt',
  // The Appendix 58 styles, served by the server (inline styles are blocked by its CSP)
  content_css: [exportApi.stockcardEditorStyleUrl()],
  visual: false,
  pagebreak_separator: '<div style="page-break-before:always;"></div>',
  invalid_elements: 'script,iframe,object,embed,form',
  convert_urls: false,
  paste_data_images: true,
  automatic_uploads: false,
  images_file_types: 'png,jpg,jpeg,gif',
  file_picker_types: 'image',
  file_picker_callback: (callback) => pickImage(callback),
  promotion: false,
  branding: false,
  setup: (editor) => editor.addShortcut('meta+s', 'Save changes', () => saveEdits()),
}))

async function loadReport() {
  if (!period) {
    previewError.value = 'A month without a year cannot be exported. Go back and pick a year too.'
    return
  }
  if (scope === 'item' && !productId) {
    previewError.value = 'No item was chosen. Go back and open an item first.'
    return
  }
  loadingPreview.value = true
  previewError.value = ''
  try {
    const res = await exportApi.previewStockcard(form)
    replacing = true
    reportHtml.value = res.data?.html || ''
    savedHtml.value = reportHtml.value
    savedAt.value = null
    cardCount.value = Number(res.data?.cards) || 0
    builtOrder.value = form.sort_order
    loadedOnce.value = true
    await nextTick()
    edited.value = false
  } catch (err) {
    previewError.value = errorMessage(err, 'Failed to load the stock card.')
  } finally {
    replacing = false
    loadingPreview.value = false
  }
}

async function confirmDropEdits(title) {
  if (!edited.value) return true
  return confirmDialog({
    title,
    message: 'The stock card is built again from the ledger. The changes you made will be lost.',
    confirmText: 'Rebuild',
    variant: 'warning',
  })
}

async function rebuildReport() {
  if (!(await confirmDropEdits('Rebuild the stock card?'))) return
  await loadReport()
}

// The order changes the rows themselves, so the cards are built again (paper size only affects the file)
async function setOrder(value) {
  if (value === form.sort_order || loadingPreview.value) return
  if (!(await confirmDropEdits('Change the date order?'))) return
  form.sort_order = value
  await loadReport()
}

async function download(format, ext) {
  if (loadingPreview.value || !hasReport.value) return

  if (unsaved.value) {
    const ok = await confirmDialog({
      title: 'Save your changes before exporting',
      message: 'The stock card has changes that are not saved yet. Exports use the saved version, so save first to include them.',
      confirmText: 'Save and export',
      cancelText: 'Keep editing',
      variant: 'warning',
    })
    if (!ok) return
    saveEdits(true)
  }

  formError.value = ''
  downloading.value = format
  try {
    const res = await exportApi.downloadStockcard({ ...form, format, html: savedHtml.value })
    let filename = `Stockcard.${ext}`
    const match = (res.headers['content-disposition'] || '').match(/filename="?([^";]+)"?/)
    if (match) filename = match[1]
    triggerBlobDownload(res.data, filename)
    toast(`Downloaded ${filename}`)
    downloadedClean.value = !unsaved.value
  } catch (err) {
    // A failed download answers with a JSON message inside the blob
    let message = ''
    if (err.response?.data instanceof Blob) {
      try {
        message = JSON.parse(await err.response.data.text()).message || ''
      } catch {
        message = ''
      }
    }
    formError.value = message || errorMessage(err, 'Failed to generate the file.')
  } finally {
    downloading.value = ''
  }
}

// Back to the ledger: ask first, unless there is nothing to lose (not built, or just downloaded unchanged)
async function goBack() {
  if (hasReport.value && !downloadedClean.value) {
    const ok = await confirmDialog({
      title: 'Discard this export?',
      message: edited.value
        ? 'The stock card has not been downloaded and your edits will be lost.'
        : 'The stock card has not been downloaded yet. You can export it again from the Stockcard Ledger.',
      confirmText: 'Discard export',
      cancelText: 'Stay here',
      variant: 'warning',
    })
    if (!ok) return
  }
  leaving = true
  try {
    await router.push(backLink.value)
  } finally {
    leaving = false
  }
}

onMounted(loadReport)
</script>

<template>
  <div class="export-page">
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Audited Inventory Ledger</p>
        <h1 class="hero-title">Preview &amp; Edit Stock Card</h1>
        <p class="hero-subtitle">Check the Appendix 58 stock card, change anything you need, then choose the paper size and download it.</p>
        <div class="es-chips">
          <span class="es-chip"><component :is="scope === 'item' ? Package : Layers" :size="13" /> {{ scopeLabel }}</span>
          <span class="es-chip"><Calendar :size="13" /> {{ period?.label || 'No period' }}</span>
        </div>
      </div>
      <button type="button" class="btn btn-secondary" @click="goBack"><ArrowLeft :size="15" /> Back to Stockcard</button>
    </div>

    <!-- 1. The stock cards themselves -->
    <section class="panel es-card">
      <div class="es-section-head">
        <div>
          <h2 class="es-section-title"><PencilLine :size="17" /> Stock Card Preview</h2>
          <p class="es-hint">
            Laid out like the printed Appendix 58.
            <template v-if="cardCount > 1">{{ cardCount }} cards, each on its own page (dashed lines).</template>
          </p>
        </div>
        <div v-if="loadedOnce" class="es-editor-actions">
          <span class="es-save-state" :class="{ 'is-unsaved': unsaved }" aria-live="polite">
            <component :is="unsaved ? AlertTriangle : Check" :size="14" />
            {{ unsaved ? 'Unsaved changes' : savedLabel }}
          </span>
          <button type="button" class="btn btn-secondary btn-sm" :disabled="loadingPreview || !!downloading" title="Build the stock card again from the ledger" @click="rebuildReport">
            <RefreshCw :size="14" :class="{ 'es-spin': loadingPreview }" /> {{ loadingPreview ? 'Rebuilding…' : 'Rebuild from Data' }}
          </button>
          <button type="button" class="btn btn-primary btn-sm" :disabled="!unsaved || loadingPreview" title="Save changes (Ctrl+S)" @click="saveEdits()">
            <Save :size="14" /> Save Changes
          </button>
        </div>
      </div>

      <div v-if="previewError && loadedOnce" class="es-error">
        <AlertTriangle :size="15" />
        <span>{{ previewError }} The stock card below was not changed.</span>
      </div>

      <!-- One stable box: the editor is mounted once and never swapped out -->
      <div class="es-editor-wrap" :class="{ 'is-empty': !loadedOnce }">
        <ReportEditor
          v-if="loadedOnce"
          v-model="reportHtml"
          :dark="themeStore.current === 'dark'"
          :init="editorInit"
          @init="editorReady = true"
        />

        <div v-if="!loadedOnce && previewError && !loadingPreview" class="es-state">
          <AlertTriangle :size="22" class="es-state-icon is-error" />
          <strong>The stock card could not be loaded</strong>
          <span>{{ previewError }}</span>
          <div class="es-state-actions">
            <router-link :to="backLink" class="btn btn-secondary btn-sm"><ArrowLeft :size="14" /> Back to Stockcard</router-link>
            <button v-if="period" type="button" class="btn btn-secondary btn-sm" @click="loadReport"><RefreshCw :size="14" /> Try Again</button>
          </div>
        </div>

        <Transition name="es-fade">
          <div v-if="loadingPreview || (loadedOnce && !editorReady)" class="es-loading" :class="{ 'is-over': loadedOnce }">
            <RefreshCw :size="22" class="es-state-icon es-spin" />
            <span>{{ loadedOnce && editorReady ? 'Rebuilding the stock card…' : 'Building the stock card…' }}</span>
          </div>
        </Transition>
      </div>
    </section>

    <!-- 2. How it is printed and downloaded -->
    <div v-if="formError" class="es-error es-error-block"><AlertTriangle :size="15" /> {{ formError }}</div>

    <div class="ex-layout">
      <div class="panel ex-panel">
        <div class="panel-header ex-panel-header">
          <div class="ex-header-title-wrap">
            <span class="ex-panel-icon"><Settings :size="20" /></span>
            <div>
              <h2 class="ex-panel-title">Output Options</h2>
              <p class="ex-panel-sub">Configure document sheet size and chronological order</p>
            </div>
          </div>
        </div>

        <div class="ex-panel-body">
          <div class="ex-two-col">
            <div class="form-group">
              <span class="form-label ex-field-label"><FileText :size="15" class="ex-label-icon" /> Paper Size</span>
              <div class="ex-card-radio-group" role="radiogroup" aria-label="Paper size">
                <label v-for="[value, name, size, icon] in PAPER_SIZES" :key="value" class="ex-card-radio" :class="{ 'is-selected': form.paper_size === value }">
                  <input v-model="form.paper_size" type="radio" name="paper_size" :value="value" />
                  <div class="ex-card-radio-body">
                    <component :is="form.paper_size === value ? FileText : icon" :size="20" class="ex-card-radio-icon" />
                    <div class="ex-card-radio-text">
                      <strong>{{ name }}</strong>
                      <small>{{ size }}</small>
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <div class="form-group">
              <span class="form-label ex-field-label"><ArrowUpDown :size="15" class="ex-label-icon" /> Date Order</span>
              <div class="ex-card-radio-group" role="radiogroup" aria-label="Date order">
                <label
                  v-for="[value, name, hint, icon] in ORDERS"
                  :key="value"
                  class="ex-card-radio"
                  :class="{ 'is-selected': form.sort_order === value, 'is-disabled': loadingPreview }"
                >
                  <input type="radio" name="sort_order" :value="value" :checked="form.sort_order === value" :disabled="loadingPreview" @change="setOrder(value)" />
                  <div class="ex-card-radio-body">
                    <component :is="icon" :size="20" class="ex-card-radio-icon" />
                    <div class="ex-card-radio-text">
                      <strong>{{ name }}</strong>
                      <small>{{ hint }}</small>
                    </div>
                  </div>
                </label>
              </div>
              <small class="es-hint">Changing the order rebuilds the preview.</small>
            </div>
          </div>
        </div>
      </div>

      <div class="ex-sidebar">
        <div class="ex-preview-card">
          <div class="ex-preview-header">
            <ClipboardList :size="17" />
            <span>Export Summary</span>
          </div>
          <div class="ex-preview-body">
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Product</span>
              <span class="ex-preview-val" :title="scopeLabel">{{ scopeLabel }}</span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Period</span>
              <span class="ex-preview-val">{{ period?.label || '—' }}</span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Paper Size</span>
              <span class="ex-preview-val">{{ paperLabel }}</span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Date Order</span>
              <span class="ex-preview-val">{{ orderLabel }}</span>
            </div>
            <div class="ex-preview-divider"></div>
            <div class="ex-preview-meta">
              <span>Standard: <strong>Appendix 58</strong></span>
              <span>Running balances &amp; office issue logs</span>
            </div>
          </div>
        </div>

        <div class="ex-download-card">
          <p class="ex-download-title">Download Formats</p>

          <button type="button" class="ex-dl-btn ex-dl-pdf" :disabled="!!downloading || loadingPreview || !hasReport" @click="download('pdf', 'pdf')">
            <span class="ex-dl-icon">
              <span v-if="downloading === 'pdf'" class="spinner"></span>
              <FileText v-else :size="24" />
            </span>
            <span class="ex-dl-info">
              <strong>PDF Document</strong>
              <small>{{ downloading === 'pdf' ? 'Generating PDF…' : 'Print-ready Appendix 58' }}</small>
            </span>
            <ArrowRight :size="18" class="ex-dl-arrow" />
          </button>

          <button type="button" class="ex-dl-btn ex-dl-word" :disabled="!!downloading || loadingPreview || !hasReport" @click="download('word', 'doc')">
            <span class="ex-dl-icon">
              <span v-if="downloading === 'word'" class="spinner"></span>
              <FilePen v-else :size="24" />
            </span>
            <span class="ex-dl-info">
              <strong>Word Document</strong>
              <small>{{ downloading === 'word' ? 'Generating Word…' : 'Editable .doc with tables' }}</small>
            </span>
            <ArrowRight :size="18" class="ex-dl-arrow" />
          </button>

          <small v-if="hasReport" class="es-hint" :class="{ 'es-hint-warn': unsaved }">
            {{ unsaved ? 'You have unsaved changes: save them to include them in the download.' : 'Downloads use the saved version of the stock card above.' }}
          </small>
        </div>

        <div class="ex-info-note">
          <p>
            <Info :size="15" class="ex-info-icon" /> <strong>Official Perpetual Record</strong>: The generated Stock Card adheres to government audit standards with automatic pagination, opening balances, verified references, and office transaction logs.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.export-page {
  animation: fadeIn 0.25s ease-in-out;
}

/* ── Hero chips: what is being exported ─────────────────── */
.es-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.85rem;
}

.es-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0.3rem 0.75rem;
  border-radius: var(--radius-full);
  border: 1px solid var(--border-subtle);
  background: var(--color-primary-light);
  color: var(--color-primary);
  font-size: 0.8rem;
  font-weight: 700;
}

/* ── Preview card ───────────────────────────────────────── */
.es-card {
  padding: 1.75rem 2rem;
  margin-bottom: 24px;
}

.es-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem 1.5rem;
  margin-bottom: 1rem;
}

.es-section-head > div:first-child {
  min-width: 0;
}

.es-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 2px;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-main);
}

.es-hint {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 400;
  color: var(--text-subtle);
}

.es-hint-warn {
  color: var(--color-warning);
  font-weight: 600;
}

.es-editor-actions {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  justify-content: flex-end;
  gap: 0.5rem;
  white-space: nowrap;
}

.es-save-state {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-right: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-success);
}

.es-save-state.is-unsaved {
  color: var(--color-warning);
}

.es-error {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 1rem;
  padding: 0.65rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-danger-bg);
  color: var(--color-danger);
  font-size: 0.875rem;
  font-weight: 600;
}

.es-error-block {
  margin-bottom: 24px;
}

.es-editor-wrap {
  position: relative;
  min-height: 360px;
}

.es-editor-wrap.is-empty {
  display: flex;
  flex-direction: column;
}

.es-editor-wrap.is-empty > .es-state,
.es-editor-wrap.is-empty > .es-loading {
  flex: 1;
}

.es-state,
.es-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 360px;
  padding: 2rem 1rem;
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  color: var(--text-muted);
  text-align: center;
}

.es-loading {
  font-weight: 600;
}

.es-state strong {
  color: var(--text-main);
}

.es-state-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

.es-state-icon {
  color: var(--color-primary);
}

.es-state-icon.is-error {
  color: var(--color-danger);
}

/* Over the open editor while it starts or rebuilds: the card stays in place underneath */
.es-loading.is-over {
  position: absolute;
  inset: 0;
  z-index: 2;
  min-height: 0;
  border-style: solid;
  background: color-mix(in srgb, var(--bg-surface) 82%, transparent);
  backdrop-filter: blur(2px);
}

.es-spin {
  animation: spin 1s linear infinite;
}

.es-fade-enter-active,
.es-fade-leave-active {
  transition: opacity var(--transition-normal);
}

.es-fade-enter-from,
.es-fade-leave-to {
  opacity: 0;
}

/* ── Options + sidebar ──────────────────────────────────── */
.ex-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}

.ex-panel {
  border-radius: 20px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.ex-panel-header {
  padding: 18px 24px;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-subtle);
}

.ex-header-title-wrap {
  display: flex;
  align-items: center;
  gap: 14px;
}

.ex-panel-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--color-primary-light);
  color: var(--color-primary);
  flex-shrink: 0;
}

.ex-panel-title {
  font-size: 1.15rem;
  font-weight: 700;
  margin: 0 0 2px;
  color: var(--text-main);
}

.ex-panel-sub {
  font-size: 13px;
  color: var(--text-muted);
  margin: 0;
}

.ex-panel-body {
  padding: 24px;
}

.ex-field-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-main);
  margin-bottom: 6px;
}

.ex-label-icon {
  color: var(--color-primary);
  flex-shrink: 0;
}

.ex-info-icon {
  color: var(--color-info);
  vertical-align: -3px;
}

.ex-two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.ex-two-col .form-group {
  margin: 0;
}

/* Radio cards (paper size, date order) */
.ex-card-radio-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.ex-card-radio {
  position: relative;
  display: block;
  cursor: pointer;
}

/* Hidden but still reachable with the keyboard */
.ex-card-radio input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.ex-card-radio:has(input:focus-visible) .ex-card-radio-body {
  outline: 2px solid var(--border-focus);
  outline-offset: 2px;
}

.ex-card-radio.is-disabled {
  cursor: progress;
  opacity: 0.7;
}

.ex-card-radio-body {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-radius: 14px;
  border: 1.5px solid var(--border-subtle);
  background: var(--bg-subtle);
  transition: all var(--transition-fast);
}

.ex-card-radio:not(.is-disabled):hover .ex-card-radio-body {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
  transform: translateY(-1px);
}

.ex-card-radio.is-selected .ex-card-radio-body {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
  box-shadow: 0 0 0 3px var(--color-primary-glow);
}

.ex-card-radio-icon {
  color: var(--color-primary);
  flex-shrink: 0;
}

.ex-card-radio-text {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.ex-card-radio-text strong {
  font-size: 13.5px;
  color: var(--text-main);
  font-weight: 700;
}

.ex-card-radio-text small {
  font-size: 11.5px;
  color: var(--text-muted);
}

/* Sidebar */
.ex-sidebar {
  display: flex;
  flex-direction: column;
  gap: 20px;
  position: sticky;
  top: 92px;
}

.ex-preview-card {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.ex-preview-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 20px;
  font-size: 13.5px;
  font-weight: 700;
  color: #fff;
  background: var(--color-nav-bg);
  box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.1);
}

.ex-preview-body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ex-preview-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.ex-preview-lbl {
  font-size: 12.5px;
  color: var(--text-muted);
  font-weight: 600;
  white-space: nowrap;
}

.ex-preview-val {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-main);
  text-align: right;
  word-break: break-word;
  max-width: 190px;
}

.ex-preview-divider {
  height: 1px;
  background: var(--border-subtle);
  margin: 4px 0;
}

.ex-preview-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: var(--text-subtle);
}

.ex-download-card {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: 20px;
  padding: 22px;
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.ex-download-title {
  font-size: 12px;
  font-weight: 800;
  color: var(--text-muted);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  margin: 0 0 2px;
}

.ex-dl-btn {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  padding: 16px 18px;
  border-radius: 16px;
  border: none;
  cursor: pointer;
  font-family: inherit;
  transition: all var(--transition-fast);
  text-align: left;
}

.ex-dl-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  filter: brightness(1.06);
  box-shadow: var(--shadow-lg);
}

.ex-dl-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.ex-dl-pdf {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: #fff;
  box-shadow: 0 8px 20px rgba(239, 68, 68, 0.28);
}

.ex-dl-word {
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  color: #fff;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.28);
}

.ex-dl-icon {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
}

.ex-dl-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
}

.ex-dl-info strong {
  font-size: 14.5px;
  font-weight: 800;
}

.ex-dl-info small {
  font-size: 11.5px;
  opacity: 0.9;
}

.ex-dl-arrow {
  opacity: 0.8;
  margin-left: auto;
}

.spinner {
  display: inline-block;
  width: 22px;
  height: 22px;
  border: 3px solid rgba(255, 255, 255, 0.35);
  border-radius: 50%;
  border-top-color: #fff;
  animation: spin 0.75s ease-in-out infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.ex-info-note {
  padding: 14px 18px;
  border-radius: 14px;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
}

.ex-info-note p {
  font-size: 12px;
  color: var(--text-muted);
  line-height: 1.5;
  margin: 0;
}

@media (max-width: 960px) {
  .ex-layout {
    grid-template-columns: 1fr;
  }
  .ex-sidebar {
    position: static;
  }
}

@media (max-width: 900px) {
  .es-card {
    padding: 1.25rem;
  }
  .es-section-head {
    flex-direction: column;
    align-items: flex-start;
  }
  .es-editor-actions {
    flex-wrap: wrap;
    justify-content: flex-start;
    white-space: normal;
  }
}

@media (max-width: 580px) {
  .ex-two-col {
    grid-template-columns: 1fr;
  }
  .ex-panel-header {
    padding: 14px 16px;
  }
  .ex-panel-body {
    padding: 16px;
  }
}
</style>
