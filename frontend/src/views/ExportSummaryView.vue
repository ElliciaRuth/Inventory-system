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
  ArrowLeft,
  Calendar,
  Check,
  Download,
  File,
  FileText,
  Layers,
  PencilLine,
  RefreshCw,
  Save,
  Search,
} from 'lucide-vue-next'

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

// Opened from a product type card of the Summary Report: ?type_id=&month=YYYY-MM[&search=][&back_type=]
const route = useRoute()
const router = useRouter()
const thisMonth = new Date().toISOString().slice(0, 7)
const month = /^\d{4}-(0[1-9]|1[0-2])$/.test(String(route.query.month || '')) ? String(route.query.month) : thisMonth
const typeId = Number(route.query.type_id) > 0 ? Number(route.query.type_id) : 0
const search = String(route.query.search || '').trim()

const form = reactive({ month_from: month, month_to: month, type_id: typeId, search, paper_size: 'long' })

const periodLabel = `${MONTH_NAMES[Number(month.slice(5, 7)) - 1]} ${month.slice(0, 4)}`
const productTypes = ref([])
const typeLabel = computed(() => {
  if (!typeId) return 'All Product Types'
  return productTypes.value.find((t) => Number(t.type_id) === typeId)?.type || 'Product type'
})

// Back to the Summary Report as it was: same month, year, product type filter and search
const backLink = computed(() => {
  const query = { month: month.slice(5, 7), year: month.slice(0, 4) }
  if (Number(route.query.back_type) > 0) query.type_id = Number(route.query.back_type)
  if (search) query.search = search
  return { name: 'summary-report', query }
})

const downloading = ref('')
const formError = ref('')

// ── The report, editable (TinyMCE, downloaded only when this page opens) ──
const ReportEditor = defineAsyncComponent(() => import('../components/ReportEditor.vue'))
const themeStore = useThemeStore()

const loadingPreview = ref(false)
const previewError = ref('')
const loadedOnce = ref(false)   // the editor stays mounted from then on; a rebuild only swaps its content
let replacing = false           // content set by the page itself, not typed by the user
const reportHtml = ref('')      // what the editor shows, changing as the user types
const savedHtml = ref('')       // the version downloads use, set by Save Changes
const savedAt = ref(null)
const editorReady = ref(false)
const edited = ref(false)

watch(reportHtml, () => {
  if (editorReady.value && !replacing) edited.value = true
})

const unsaved = computed(() => reportHtml.value !== savedHtml.value)

// Edits live only on this page until downloaded, so leaving asks first (saved or not)
// Set once the user has confirmed leaving, so the leave guard below does not ask a second time
let leaving = false
// Downloaded and not changed since: nothing is lost by going back
const downloadedClean = ref(false)
watch(reportHtml, () => {
  if (editorReady.value && !replacing) downloadedClean.value = false
})

useUnsavedChanges(() => edited.value && !leaving, {
  message: 'Your edits to the report are kept only on this page. Download the report first if you need them.',
})

function saveEdits(quiet = false) {
  savedHtml.value = reportHtml.value
  savedAt.value = new Date()
  if (!quiet) toast('Changes saved. PDF Print and MS Word use this version.')
}

const savedLabel = computed(() =>
  savedAt.value ? `Saved at ${savedAt.value.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}` : 'No changes yet'
)

// Pictures are kept inside the report (data: URIs) so the PDF needs no files
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
  height: 680,
  menubar: 'edit view insert format table',
  plugins: 'advlist lists link image table charmap searchreplace visualblocks code fullscreen pagebreak',
  toolbar:
    'undo redo | blocks fontsize | bold italic underline | forecolor backcolor | ' +
    'alignleft aligncenter alignright | bullist numlist | table image pagebreak | removeformat | code fullscreen',
  font_size_formats: '6pt 7pt 7.5pt 8pt 9pt 10pt 11pt 12pt 14pt 18pt',
  // The report's own styles (the page stays white: it is what gets printed), as a stylesheet
  // the server serves: inline styles are blocked by its Content-Security-Policy
  content_css: [exportApi.summaryEditorStyleUrl()],
  // No dashed guides around the borderless heading and signature tables: the page looks as printed
  // (View → Visual aids shows them again)
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
  // Ctrl+S inside the editor saves the report instead of the browser's "save page"
  setup: (editor) => editor.addShortcut('meta+s', 'Save changes', () => saveEdits()),
}))

async function loadReport() {
  loadingPreview.value = true
  previewError.value = ''
  try {
    const res = await exportApi.previewSummary(form)
    // A freshly built report is not an edit: the editor takes the new content without counting it as typing
    replacing = true
    reportHtml.value = res.data?.html || ''
    savedHtml.value = reportHtml.value
    savedAt.value = null
    loadedOnce.value = true
    await nextTick()
    edited.value = false
  } catch (err) {
    previewError.value = errorMessage(err, 'Failed to load the report.')
  } finally {
    replacing = false
    loadingPreview.value = false
  }
}

const hasReport = computed(() => loadedOnce.value && !previewError.value)

// Start over from the recorded figures, dropping the edits
async function rebuildReport() {
  if (edited.value) {
    const ok = await confirmDialog({
      title: 'Rebuild the report?',
      message: 'The report is built again from the recorded figures. The changes you made will be lost.',
      confirmText: 'Rebuild',
      variant: 'warning',
    })
    if (!ok) return
  }
  await loadReport()
}

const FORMATS = [
  ['pdf', 'PDF Print', 'pdf'],
  ['word', 'MS Word', 'doc'],
]

// [value, name, size]; the chosen one shows the lined page icon
const PAPER_SIZES = [
  ['long', 'Long', '8.5″ × 13″ Folio'],
  ['short', 'Short', '8.5″ × 11″ Letter'],
  ['a4', 'A4', '8.27″ × 11.69″ (210 × 297 mm)'],
]

async function loadOptions() {
  try {
    const res = await exportApi.getSummaryOptions()
    productTypes.value = res.data?.productTypes || []
  } catch {
    // Only the type's name is missing from the heading
  }
}

async function download(format, ext) {
  if (loadingPreview.value || !hasReport.value) return

  // Exports use the saved version: unsaved edits would silently be left out
  if (unsaved.value) {
    const ok = await confirmDialog({
      title: 'Save your changes before exporting',
      message: 'The report has changes that are not saved yet. Exports use the saved version, so save first to include them.',
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
    const res = await exportApi.downloadSummary({ ...form, format, html: savedHtml.value })
    let filename = `SummaryReport_${form.month_from}.${ext}`
    const match = (res.headers['content-disposition'] || '').match(/filename="?([^";]+)"?/)
    if (match) filename = match[1]
    triggerBlobDownload(res.data, filename)
    toast(`Downloaded ${filename}`)
    downloadedClean.value = !unsaved.value
  } catch (err) {
    formError.value = errorMessage(err, 'Failed to generate the report.')
  } finally {
    downloading.value = ''
  }
}

// Back to the report: ask first, unless there is nothing to lose (not built, or just downloaded unchanged)
async function goBack() {
  if (hasReport.value && !downloadedClean.value) {
    const ok = await confirmDialog({
      title: 'Discard this export?',
      message: edited.value
        ? 'The report has not been downloaded and your edits will be lost.'
        : 'The report has not been downloaded yet. You can export it again from the Summary Report.',
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

onMounted(() => {
  loadOptions()
  loadReport()
})
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Reports</p>
        <h1 class="hero-title">Preview &amp; Edit Report</h1>
        <p class="hero-subtitle">Check the summary report, change anything you need, then choose the paper size and download it.</p>
        <div class="es-chips">
          <span class="es-chip"><Layers :size="13" /> {{ typeLabel }}</span>
          <span class="es-chip"><Calendar :size="13" /> {{ periodLabel }}</span>
          <span v-if="search" class="es-chip"><Search :size="13" /> “{{ search }}”</span>
        </div>
      </div>
      <button type="button" class="btn btn-secondary" @click="goBack"><ArrowLeft :size="15" /> Back to Report</button>
    </div>

    <!-- 1. The report itself -->
    <section class="panel es-card">
      <div class="es-section-head">
        <div>
          <h2 class="es-section-title"><PencilLine :size="17" /> Report Preview</h2>
          <p class="es-hint">Laid out like the printed copy. Each dashed line is a new page; the page-break button starts one.</p>
        </div>
        <div v-if="loadedOnce" class="es-editor-actions">
          <span class="es-save-state" :class="{ 'is-unsaved': unsaved }" aria-live="polite">
            <component :is="unsaved ? AlertTriangle : Check" :size="14" />
            {{ unsaved ? 'Unsaved changes' : savedLabel }}
          </span>
          <button type="button" class="btn btn-secondary btn-sm" :disabled="loadingPreview || !!downloading" title="Build the report again from the recorded figures" @click="rebuildReport">
            <RefreshCw :size="14" :class="{ 'es-spin': loadingPreview }" /> {{ loadingPreview ? 'Rebuilding…' : 'Rebuild from Data' }}
          </button>
          <button type="button" class="btn btn-primary btn-sm" :disabled="!unsaved || loadingPreview" title="Save changes (Ctrl+S)" @click="saveEdits()">
            <Save :size="14" /> Save Changes
          </button>
        </div>
      </div>

      <!-- A failed rebuild keeps the report that is already open -->
      <div v-if="previewError && loadedOnce" class="es-error">
        <AlertTriangle :size="15" />
        <span>{{ previewError }} The report below was not changed.</span>
      </div>

      <!-- One stable box: the editor is mounted once and never swapped out, so it cannot lose its place -->
      <div class="es-editor-wrap" :class="{ 'is-empty': !loadedOnce }">
        <ReportEditor
          v-if="loadedOnce"
          v-model="reportHtml"
          :dark="themeStore.current === 'dark'"
          :init="editorInit"
          @init="editorReady = true"
        />

        <div v-if="!loadedOnce && previewError" class="es-state">
          <AlertTriangle :size="22" class="es-state-icon is-error" />
          <strong>The report could not be loaded</strong>
          <span>{{ previewError }}</span>
          <button type="button" class="btn btn-secondary btn-sm" @click="loadReport"><RefreshCw :size="14" /> Try Again</button>
        </div>

        <!-- Loading: fills the empty box at first, then lies over the open editor while it starts or rebuilds -->
        <Transition name="es-fade">
          <div v-if="loadingPreview || (loadedOnce && !editorReady)" class="es-loading" :class="{ 'is-over': loadedOnce }">
            <RefreshCw :size="22" class="es-state-icon es-spin" />
            <span>{{ loadedOnce && editorReady ? 'Rebuilding the report…' : 'Building the report…' }}</span>
          </div>
        </Transition>
      </div>
    </section>

    <!-- 2. How it is printed and downloaded -->
    <section class="panel es-card es-options">
      <div v-if="formError" class="es-error"><AlertTriangle :size="15" /> {{ formError }}</div>

      <div class="es-grid">
        <div class="form-group es-col">
          <span class="form-label">
            <FileText :size="15" class="es-label-icon" /> Paper Size &amp; Layout <small class="es-hint">(PDF / Word)</small>
          </span>
          <div class="es-paper-group" role="radiogroup" aria-label="Paper size">
            <label v-for="[value, name, size] in PAPER_SIZES" :key="value" class="es-paper" :class="{ 'is-on': form.paper_size === value }">
              <input v-model="form.paper_size" type="radio" name="paper_size" :value="value" />
              <component :is="form.paper_size === value ? FileText : File" :size="20" class="es-paper-icon" aria-hidden="true" />
              <span class="es-paper-text">
                <strong>{{ name }}</strong>
                <small>{{ size }}</small>
              </span>
            </label>
          </div>
        </div>

        <div class="form-group es-col es-col-right">
          <span class="form-label"><Download :size="15" class="es-label-icon" /> Export Target File Format</span>
          <div class="es-formats">
            <button
              v-for="[format, label, ext] in FORMATS"
              :key="format"
              type="button"
              class="btn es-format"
              :class="format === 'pdf' ? 'btn-primary' : 'btn-secondary'"
              :disabled="!!downloading || loadingPreview || !hasReport"
              @click="download(format, ext)"
            >
              {{ downloading === format ? 'Generating…' : label }}
            </button>
          </div>
          <small class="es-hint" :class="{ 'es-hint-warn': unsaved }">
            {{ unsaved ? 'You have unsaved changes: save them to include them in the download.' : 'Downloads use the saved version of the report above.' }}
          </small>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.es-card {
  padding: 1.75rem 2rem;
}

.es-options {
  margin-top: 1.5rem;
}

/* Type, month and search the report was opened for */
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

.es-error {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 1.25rem;
  padding: 0.65rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-danger-bg);
  color: var(--color-danger);
  font-size: 0.875rem;
  font-weight: 600;
}

.es-hint {
  font-weight: 400;
  color: var(--text-subtle);
}

.es-hint-warn {
  color: var(--color-warning);
  font-weight: 600;
}

/* Report heading with its actions (kept on one line beside the text) */
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

.es-section-head .es-hint {
  margin: 0;
  font-size: 0.85rem;
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

.es-editor-actions {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  justify-content: flex-end;
  gap: 0.5rem;
  white-space: nowrap;
}

/* The editor's box: holds the editor, or the loading / error message before it opens */
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

.es-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 360px;
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  color: var(--text-muted);
  font-weight: 600;
}

/* Over the open editor while it starts or rebuilds: the report stays in place underneath */
.es-loading.is-over {
  position: absolute;
  inset: 0;
  z-index: 2;
  min-height: 0;
  border-style: solid;
  background: color-mix(in srgb, var(--bg-surface) 82%, transparent);
  backdrop-filter: blur(2px);
}

.es-fade-enter-active,
.es-fade-leave-active {
  transition: opacity var(--transition-normal);
}

.es-fade-enter-from,
.es-fade-leave-to {
  opacity: 0;
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

/* Loading / failed report */
.es-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 320px;
  padding: 2rem 1rem;
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  color: var(--text-muted);
  text-align: center;
}

.es-state strong {
  color: var(--text-main);
}

.es-state-icon {
  color: var(--color-primary);
}

.es-state-icon.is-error {
  color: var(--color-danger);
}

.es-spin {
  animation: es-spin 1s linear infinite;
}

@keyframes es-spin {
  to {
    transform: rotate(360deg);
  }
}

/* Paper size and format side by side, split by a thin divider; stacked on narrow screens */
.es-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
}

.es-col {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 0;
  margin: 0;
}

.es-col:first-child {
  padding-right: 2.25rem;
}

.es-col-right {
  padding-left: 2.25rem;
  border-left: 1px solid var(--border-subtle);
}

.es-label-icon {
  color: var(--color-primary);
  vertical-align: -2px;
}

/* Paper size: cards in a row, the chosen one tinted and outlined */
.es-paper-group {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.es-paper {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 14px;
  border: 1.5px solid var(--border-subtle);
  background: var(--bg-subtle);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.es-paper:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.es-paper.is-on {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
  box-shadow: 0 0 0 3px var(--color-primary-glow);
}

.es-paper:has(input:focus-visible) {
  outline: 2px solid var(--border-focus);
  outline-offset: 2px;
}

/* Hidden but still reachable with the keyboard */
.es-paper input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.es-paper-icon {
  color: var(--color-primary);
  flex-shrink: 0;
}

.es-paper-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.es-paper-text strong {
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--text-main);
}

.es-paper-text small {
  font-size: 0.75rem;
  color: var(--text-muted);
}

/* Two equal format buttons */
.es-formats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.es-format {
  justify-content: center;
  min-height: 48px;
  font-weight: 700;
}

@media (max-width: 1100px) {
  .es-paper-group {
    grid-template-columns: 1fr;
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
  .es-grid {
    grid-template-columns: 1fr;
    gap: 1.25rem;
  }
  .es-col:first-child {
    padding-right: 0;
  }
  .es-col-right {
    padding-left: 0;
    padding-top: 1.25rem;
    border-left: 0;
    border-top: 1px solid var(--border-subtle);
  }
}

@media (max-width: 480px) {
  .es-formats {
    grid-template-columns: 1fr;
  }
}
</style>
