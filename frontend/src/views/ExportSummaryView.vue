<script setup>
import { ref, reactive, onMounted } from 'vue'
import { exportApi, triggerBlobDownload } from '../api/export'
import { toast, errorMessage } from '../composables/useToast'
import { ArrowLeft, ArrowRight, AlertTriangle, File, FileText } from 'lucide-vue-next'

const thisMonth = new Date().toISOString().slice(0, 7)

const form = reactive({ month_from: thisMonth, month_to: thisMonth, type_id: 0, paper_size: 'long' })
const productTypes = ref([])
const downloading = ref('')
const formError = ref('')

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
  } catch (err) {
    toast(errorMessage(err, 'Failed to load product types.'), 'error')
  }
}

async function download(format, ext) {
  formError.value = ''
  if (!form.month_from || !form.month_to) {
    formError.value = 'Please select a date range.'
    return
  }
  if (form.month_from > form.month_to) {
    formError.value = 'From Month must be before To Month.'
    return
  }

  downloading.value = format
  try {
    const res = await exportApi.downloadSummary({ ...form, format })
    let filename = `SummaryReport_${form.month_from}_to_${form.month_to}.${ext}`
    const match = (res.headers['content-disposition'] || '').match(/filename="?([^";]+)"?/)
    if (match) filename = match[1]
    triggerBlobDownload(res.data, filename)
    toast(`Downloaded ${filename}`)
  } catch (err) {
    formError.value = errorMessage(err, 'Failed to generate the report.')
  } finally {
    downloading.value = ''
  }
}

onMounted(loadOptions)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Reports & Audit</p>
        <h1 class="hero-title">Export Summary Report</h1>
        <p class="hero-subtitle">Download the monthly inventory summary (beginning, purchase, used, spoiled, ending) for a range of months.</p>
      </div>
      <router-link to="/reports/summary" class="btn btn-secondary"><ArrowLeft :size="15" /> Back to Report</router-link>
    </div>

    <section class="panel es-card">
      <div v-if="formError" class="es-error"><AlertTriangle :size="15" /> {{ formError }}</div>

      <div class="es-grid">
        <!-- Left: what goes in the report -->
        <div class="es-col">
          <div class="form-group">
            <label class="form-label" for="es-from">Date Period (From / To)</label>
            <div class="es-period">
              <input id="es-from" v-model="form.month_from" type="month" class="form-input es-month" aria-label="From month" />
              <ArrowRight :size="16" class="es-arrow" aria-hidden="true" />
              <input v-model="form.month_to" type="month" class="form-input es-month" aria-label="To month" />
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="es-type">Product Type Filter</label>
            <select id="es-type" v-model.number="form.type_id" class="form-select">
              <option :value="0">— All Product Types —</option>
              <option v-for="t in productTypes" :key="t.type_id" :value="Number(t.type_id)">{{ t.type }}</option>
            </select>
          </div>
        </div>

        <!-- Right: how it is printed and downloaded -->
        <div class="es-col es-col-right">
          <div class="form-group">
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

          <div class="form-group">
            <span class="form-label">Export Target File Format</span>
            <div class="es-formats">
              <button
                v-for="[format, label, ext] in FORMATS"
                :key="format"
                type="button"
                class="btn es-format"
                :class="format === 'pdf' ? 'btn-primary' : 'btn-secondary'"
                :disabled="!!downloading"
                @click="download(format, ext)"
              >
                {{ downloading === format ? 'Generating…' : label }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.es-card {
  padding: 1.75rem 2rem;
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

/* Two columns split by a thin divider; stacked on narrow screens */
.es-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

.es-col {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 0;
}

.es-col:first-child {
  padding-right: 2.25rem;
}

.es-col-right {
  padding-left: 2.25rem;
  border-left: 1px solid var(--border-subtle);
}

.es-hint {
  font-weight: 400;
  color: var(--text-subtle);
}

/* From → To */
.es-period {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.es-month {
  flex: 1;
  min-width: 0;
  font-weight: 700;
}

.es-arrow {
  flex-shrink: 0;
  color: var(--text-subtle);
}

/* Paper size: radio row, the chosen one bold with a filled dot */
.es-label-icon {
  color: var(--color-primary);
  vertical-align: -2px;
}

/* Paper size: stacked cards, the chosen one tinted and outlined */
.es-paper-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.es-paper {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
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

/* Three equal format buttons */
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

@media (max-width: 900px) {
  .es-card {
    padding: 1.25rem;
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
