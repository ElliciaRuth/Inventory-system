<script setup>
import { ref, reactive, onMounted } from 'vue'
import { exportApi, triggerBlobDownload } from '../api/export'
import { toast, errorMessage } from '../composables/useToast'
import { FileText, FilePen, FileSpreadsheet, ArrowLeft, AlertTriangle } from 'lucide-vue-next'

const thisMonth = new Date().toISOString().slice(0, 7)

const form = reactive({ month_from: thisMonth, month_to: thisMonth, type_id: 0, paper_size: 'long' })
const productTypes = ref([])
const downloading = ref('')
const formError = ref('')

const FORMATS = [
  ['pdf', 'Download PDF', 'pdf', FileText],
  ['word', 'Download Word', 'doc', FilePen],
  ['csv', 'Download CSV', 'csv', FileSpreadsheet],
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

    <section class="panel" style="padding: 1.5rem; max-width: 760px;">
      <div v-if="formError" class="badge badge-danger" style="display: flex; margin-bottom: 1rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;"><AlertTriangle :size="15" /> {{ formError }}</div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">From Month</label>
          <input v-model="form.month_from" type="month" class="form-input" />
        </div>
        <div class="form-group">
          <label class="form-label">To Month</label>
          <input v-model="form.month_to" type="month" class="form-input" />
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Product Type</label>
        <select v-model.number="form.type_id" class="form-select">
          <option :value="0">— All Product Types —</option>
          <option v-for="t in productTypes" :key="t.type_id" :value="Number(t.type_id)">{{ t.type }}</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Paper Size (PDF / Word)</label>
        <div style="display: flex; gap: 1.25rem; flex-wrap: wrap;">
          <label><input v-model="form.paper_size" type="radio" value="long" /> Long (8.5 × 13 in)</label>
          <label><input v-model="form.paper_size" type="radio" value="short" /> Short / Letter (8.5 × 11 in)</label>
          <label><input v-model="form.paper_size" type="radio" value="a4" /> A4</label>
        </div>
      </div>

      <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 0.5rem;">
        <button
          v-for="[format, label, ext, icon] in FORMATS"
          :key="format"
          type="button"
          class="btn"
          :class="format === 'pdf' ? 'btn-primary' : 'btn-secondary'"
          :disabled="!!downloading"
          @click="download(format, ext)"
        >
          <component :is="icon" :size="15" />
          {{ downloading === format ? 'Generating…' : label }}
        </button>
      </div>
    </section>
  </div>
</template>
