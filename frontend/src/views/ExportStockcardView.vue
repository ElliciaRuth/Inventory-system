<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { exportApi, triggerBlobDownload } from '../api/export'

const router = useRouter()

// Filter Form State
const products = ref([])
const selectedProductId = ref('0')
const monthFrom = ref('')
const monthTo = ref('')
const paperSize = ref('long')
const sortOrder = ref('ASC')

// UI State
const loadingOptions = ref(true)
const isDownloadingPdf = ref(false)
const isDownloadingWord = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// Initialize default dates (previous month and current month)
function initDefaultDates() {
  const now = new Date()
  const currentYear = now.getFullYear()
  const currentMonth = String(now.getMonth() + 1).padStart(2, '0')
  monthTo.value = `${currentYear}-${currentMonth}`

  // Previous month
  const prevDate = new Date(now.getFullYear(), now.getMonth() - 1, 1)
  const prevYear = prevDate.getFullYear()
  const prevMonth = String(prevDate.getMonth() + 1).padStart(2, '0')
  monthFrom.value = `${prevYear}-${prevMonth}`
}

async function loadOptions() {
  loadingOptions.value = true
  errorMessage.value = ''
  try {
    const res = await exportApi.getStockcardOptions()
    if (res && res.status && res.data) {
      products.value = res.data.products || []
    }
  } catch (err) {
    console.error('Failed to load product list', err)
    errorMessage.value = err.response?.data?.message || 'Failed to load product list. Please check connection.'
  } finally {
    loadingOptions.value = false
  }
}

// Quick Period Presets
function setPreset(preset) {
  const now = new Date()
  const curY = now.getFullYear()
  const curM = String(now.getMonth() + 1).padStart(2, '0')
  monthTo.value = `${curY}-${curM}`

  if (preset === 'current') {
    monthFrom.value = `${curY}-${curM}`
  } else if (preset === 'last3') {
    const d3 = new Date(now.getFullYear(), now.getMonth() - 2, 1)
    monthFrom.value = `${d3.getFullYear()}-${String(d3.getMonth() + 1).padStart(2, '0')}`
  } else if (preset === 'ytd') {
    monthFrom.value = `${curY}-01`
  }
}

// Format period for display in preview
const formattedPeriod = computed(() => {
  if (!monthFrom.value && !monthTo.value) return '—'
  const fmt = (val) => {
    if (!val) return '—'
    const [y, m] = val.split('-')
    if (!y || !m) return val
    const date = new Date(parseInt(y, 10), parseInt(m, 10) - 1, 1)
    return date.toLocaleString('default', { month: 'short', year: 'numeric' })
  }

  if (monthFrom.value === monthTo.value) {
    return fmt(monthFrom.value)
  }
  return `${fmt(monthFrom.value)} – ${fmt(monthTo.value)}`
})

// Selected product label for preview
const selectedProductLabel = computed(() => {
  if (!selectedProductId.value || selectedProductId.value === '0') {
    return '— All Products —'
  }
  const found = products.value.find(p => String(p.product_id) === String(selectedProductId.value))
  return found ? found.product : 'Selected Product'
})

// Paper size description for preview
const paperSizeLabel = computed(() => {
  return paperSize.value === 'short' ? 'Short (8.5″ × 11″ Letter)' : 'Long (8.5″ × 13″ Folio)'
})

// Sort order description for preview
const sortOrderLabel = computed(() => {
  return sortOrder.value === 'DESC' ? 'Descending (Newest first)' : 'Ascending (Oldest first)'
})

// Download Handler
async function handleDownload(format) {
  errorMessage.value = ''
  successMessage.value = ''

  if (!monthFrom.value || !monthTo.value) {
    errorMessage.value = 'Please select both From Month and To Month.'
    return
  }

  if (monthFrom.value > monthTo.value) {
    errorMessage.value = 'From Month must be earlier than or equal to To Month.'
    return
  }

  const isPdf = format === 'pdf'
  if (isPdf) isDownloadingPdf.value = true
  else isDownloadingWord.value = true

  const payload = {
    format,
    product_id: parseInt(selectedProductId.value, 10) || 0,
    month_from: monthFrom.value,
    month_to: monthTo.value,
    paper_size: paperSize.value,
    sort_order: sortOrder.value,
  }

  try {
    const response = await exportApi.downloadStockcard(payload)

    // Check if response is error disguised in blob
    if (response.data && response.data.type && response.data.type.includes('application/json')) {
      const text = await response.data.text()
      try {
        const json = JSON.parse(text)
        errorMessage.value = json.message || 'Export failed.'
        return
      } catch {
        // Not JSON
      }
    }

    // Determine filename
    let filename = `Stockcard_${monthFrom.value}_to_${monthTo.value}.${isPdf ? 'pdf' : 'doc'}`
    const disposition = response.headers['content-disposition']
    if (disposition && disposition.indexOf('filename=') !== -1) {
      const match = disposition.match(/filename="?([^";]+)"?/)
      if (match && match[1]) {
        filename = match[1]
      }
    }

    // Trigger download
    triggerBlobDownload(response.data, filename)
    successMessage.value = `Successfully downloaded ${filename}`
  } catch (err) {
    console.error('Download error:', err)
    if (err.response && err.response.data instanceof Blob) {
      try {
        const errorText = await err.response.data.text()
        const errorJson = JSON.parse(errorText)
        errorMessage.value = errorJson.message || 'Failed to generate document.'
      } catch {
        errorMessage.value = 'Failed to generate export file. Please check server logs.'
      }
    } else {
      errorMessage.value = err.response?.data?.message || err.message || 'Failed to generate export file.'
    }
  } finally {
    isDownloadingPdf.value = false
    isDownloadingWord.value = false
  }
}

onMounted(() => {
  initDefaultDates()
  loadOptions()
})
</script>

<template>
  <div class="export-page">
    <!-- Page Hero Header -->
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">
          <span>📊</span> Reports & Audit
        </p>
        <h1 class="hero-title">Export Stock Card</h1>
        <p class="hero-subtitle">
          Generate an audited, official perpetual inventory stock card (Appendix 58). Download as a print-ready PDF or an editable Word (.doc) document.
        </p>
      </div>

      <div>
        <router-link to="/stockcard" class="btn btn-secondary">
          <span>←</span> Back to Stockcard Ledger
        </router-link>
      </div>
    </div>

    <!-- Alert / Flash Messages -->
    <div v-if="errorMessage" class="login-error-banner" style="margin-bottom: 24px;">
      ⚠️ {{ errorMessage }}
    </div>

    <div v-if="successMessage" class="login-success-banner" style="margin-bottom: 24px;">
      ✅ {{ successMessage }}
    </div>

    <!-- Main Two-Column Export Layout -->
    <div class="ex-layout">
      <!-- ── LEFT COLUMN: Filter & Output Options ── -->
      <div class="ex-main">
        <!-- Panel 1: Filter -->
        <div class="panel ex-panel">
          <div class="panel-header ex-panel-header">
            <div class="ex-header-title-wrap">
              <span class="ex-panel-icon">🔍</span>
              <div>
                <h2 class="ex-panel-title">Filter Selection</h2>
                <p class="ex-panel-sub">Choose product scope and date range for the stock card ledger</p>
              </div>
            </div>
          </div>

          <div class="ex-panel-body">
            <!-- Product Field -->
            <div class="form-group">
              <label class="form-label ex-field-label" for="export_product">
                <span class="ex-label-icon">📦</span> Target Product
              </label>
              <select
                id="export_product"
                v-model="selectedProductId"
                class="form-select ex-select"
                :disabled="loadingOptions"
              >
                <option value="0">— All Products in Office —</option>
                <option
                  v-for="p in products"
                  :key="p.product_id"
                  :value="String(p.product_id)"
                >
                  {{ p.product }}
                </option>
              </select>
              <small v-if="loadingOptions" style="color: var(--text-subtle); font-size: 12px;">
                Loading available products...
              </small>
            </div>

            <!-- Date Range Fields -->
            <div class="ex-two-col">
              <div class="form-group">
                <label class="form-label ex-field-label" for="month_from">
                  <span class="ex-label-icon">📅</span> From Month
                </label>
                <input
                  id="month_from"
                  v-model="monthFrom"
                  type="month"
                  class="form-input ex-input"
                  required
                />
              </div>

              <div class="form-group">
                <label class="form-label ex-field-label" for="month_to">
                  <span class="ex-label-icon">📅</span> To Month
                </label>
                <input
                  id="month_to"
                  v-model="monthTo"
                  type="month"
                  class="form-input ex-input"
                  required
                />
              </div>
            </div>

            <!-- Quick Presets -->
            <div class="ex-presets">
              <span class="ex-presets-lbl">Quick Presets:</span>
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="setPreset('current')"
              >
                Current Month
              </button>
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="setPreset('last3')"
              >
                Last 3 Months
              </button>
              <button
                type="button"
                class="btn btn-secondary btn-sm"
                @click="setPreset('ytd')"
              >
                Year to Date
              </button>
            </div>
          </div>
        </div>

        <!-- Panel 2: Output Options -->
        <div class="panel ex-panel">
          <div class="panel-header ex-panel-header">
            <div class="ex-header-title-wrap">
              <span class="ex-panel-icon">⚙️</span>
              <div>
                <h2 class="ex-panel-title">Output Options</h2>
                <p class="ex-panel-sub">Configure document sheet size and chronological order</p>
              </div>
            </div>
          </div>

          <div class="ex-panel-body">
            <div class="ex-two-col">
              <!-- Paper Size Selection -->
              <div class="form-group">
                <label class="form-label ex-field-label">
                  <span class="ex-label-icon">📄</span> Paper Size
                </label>
                <div class="ex-card-radio-group">
                  <label
                    class="ex-card-radio"
                    :class="{ 'is-selected': paperSize === 'long' }"
                  >
                    <input
                      v-model="paperSize"
                      type="radio"
                      name="paper_size"
                      value="long"
                    />
                    <div class="ex-card-radio-body">
                      <span class="ex-card-radio-icon">📄</span>
                      <div class="ex-card-radio-text">
                        <strong>Long</strong>
                        <small>8.5″ × 13″ Folio</small>
                      </div>
                    </div>
                  </label>

                  <label
                    class="ex-card-radio"
                    :class="{ 'is-selected': paperSize === 'short' }"
                  >
                    <input
                      v-model="paperSize"
                      type="radio"
                      name="paper_size"
                      value="short"
                    />
                    <div class="ex-card-radio-body">
                      <span class="ex-card-radio-icon">📃</span>
                      <div class="ex-card-radio-text">
                        <strong>Short</strong>
                        <small>8.5″ × 11″ Letter</small>
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <!-- Date Ordering Selection -->
              <div class="form-group">
                <label class="form-label ex-field-label">
                  <span class="ex-label-icon">↕️</span> Date Order
                </label>
                <div class="ex-card-radio-group">
                  <label
                    class="ex-card-radio"
                    :class="{ 'is-selected': sortOrder === 'ASC' }"
                  >
                    <input
                      v-model="sortOrder"
                      type="radio"
                      name="sort_order"
                      value="ASC"
                    />
                    <div class="ex-card-radio-body">
                      <span class="ex-card-radio-icon">⬆️</span>
                      <div class="ex-card-radio-text">
                        <strong>Ascending</strong>
                        <small>Oldest first (Ledger flow)</small>
                      </div>
                    </div>
                  </label>

                  <label
                    class="ex-card-radio"
                    :class="{ 'is-selected': sortOrder === 'DESC' }"
                  >
                    <input
                      v-model="sortOrder"
                      type="radio"
                      name="sort_order"
                      value="DESC"
                    />
                    <div class="ex-card-radio-body">
                      <span class="ex-card-radio-icon">⬇️</span>
                      <div class="ex-card-radio-text">
                        <strong>Descending</strong>
                        <small>Newest first</small>
                      </div>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── RIGHT COLUMN: Sidebar Preview & Downloads ── -->
      <div class="ex-sidebar">
        <!-- Live Preview Summary Card -->
        <div class="ex-preview-card">
          <div class="ex-preview-header">
            <span>📋</span>
            <span>Export Summary</span>
          </div>
          <div class="ex-preview-body">
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Product</span>
              <span class="ex-preview-val" :title="selectedProductLabel">
                {{ selectedProductLabel }}
              </span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Period</span>
              <span class="ex-preview-val">{{ formattedPeriod }}</span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Paper Size</span>
              <span class="ex-preview-val">{{ paperSizeLabel }}</span>
            </div>
            <div class="ex-preview-row">
              <span class="ex-preview-lbl">Date Order</span>
              <span class="ex-preview-val">{{ sortOrderLabel }}</span>
            </div>
            <div class="ex-preview-divider"></div>
            <div class="ex-preview-meta">
              <span>Standard: <strong>Appendix 58</strong></span>
              <span>Running balances & office issue logs</span>
            </div>
          </div>
        </div>

        <!-- Download Buttons Card -->
        <div class="ex-download-card">
          <p class="ex-download-title">Download Formats</p>

          <!-- PDF Button -->
          <button
            type="button"
            class="ex-dl-btn ex-dl-pdf"
            :disabled="isDownloadingPdf || isDownloadingWord"
            @click="handleDownload('pdf')"
          >
            <span class="ex-dl-icon">
              <span v-if="isDownloadingPdf" class="spinner"></span>
              <span v-else>📄</span>
            </span>
            <span class="ex-dl-info">
              <strong>PDF Document</strong>
              <small>{{ isDownloadingPdf ? 'Generating PDF...' : 'Print-ready Appendix 58' }}</small>
            </span>
            <span class="ex-dl-arrow">→</span>
          </button>

          <!-- Word Button -->
          <button
            type="button"
            class="ex-dl-btn ex-dl-word"
            :disabled="isDownloadingPdf || isDownloadingWord"
            @click="handleDownload('word')"
          >
            <span class="ex-dl-icon">
              <span v-if="isDownloadingWord" class="spinner"></span>
              <span v-else>📝</span>
            </span>
            <span class="ex-dl-info">
              <strong>Word Document</strong>
              <small>{{ isDownloadingWord ? 'Generating Word...' : 'Editable .doc with tables' }}</small>
            </span>
            <span class="ex-dl-arrow">→</span>
          </button>
        </div>

        <!-- Document Info Note -->
        <div class="ex-info-note">
          <p>
            ℹ️ <strong>Official Perpetual Record</strong>: The generated Stock Card adheres to government audit standards with automatic pagination, opening balances, verified references, and office transaction logs.
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

/* ── Layout ─────────────────────────────────────────────── */
.ex-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}

@media (max-width: 960px) {
  .ex-layout {
    grid-template-columns: 1fr;
  }
  .ex-sidebar {
    order: -1;
  }
}

/* ── Panel Header & Body ────────────────────────────────── */
.ex-panel {
  margin-bottom: 24px;
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
  font-size: 1.4rem;
  line-height: 1;
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
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* ── Field Row Layout ──────────────────────────────────── */
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
  font-size: 14px;
}

.ex-two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

@media (max-width: 580px) {
  .ex-two-col {
    grid-template-columns: 1fr;
  }
}

.ex-select,
.ex-input {
  height: 46px;
  font-size: 14px;
  border-radius: 12px;
}

/* ── Presets ────────────────────────────────────────────── */
.ex-presets {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: -6px;
}

.ex-presets-lbl {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-muted);
}

/* ── Radio Card Buttons (Paper Size & Date Order) ───────── */
.ex-card-radio-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.ex-card-radio {
  display: block;
  cursor: pointer;
}

.ex-card-radio input {
  display: none;
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

.ex-card-radio:hover .ex-card-radio-body {
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
  font-size: 1.25rem;
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

/* ── Sidebar ────────────────────────────────────────────── */
.ex-sidebar {
  display: flex;
  flex-direction: column;
  gap: 20px;
  position: sticky;
  top: 92px;
}

/* Preview Card */
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

/* Download Card */
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
  font-size: 1.6rem;
  line-height: 1;
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
  font-size: 18px;
  font-weight: 700;
  opacity: 0.8;
  margin-left: auto;
}

/* Spinner */
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

/* Info Note */
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
</style>
