<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { reportsApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'
import AppPagination from '../components/AppPagination.vue'
import { Download, Search, X, Barcode, Boxes } from 'lucide-vue-next'

const batches = ref([])
const loading = ref(true)
const search = ref('')
const showEmpty = ref(false)

// Pagination
const currentPage = ref(1)
const pageSize = ref(15)

const totalPages = computed(() => Math.max(1, Math.ceil(batches.value.length / pageSize.value)))

const paginatedBatches = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return batches.value.slice(start, start + pageSize.value)
})

async function load() {
  currentPage.value = 1
  loading.value = true
  try {
    const res = await reportsApi.getBatches({ search: search.value.trim(), show_empty: showEmpty.value ? 1 : 0 })
    batches.value = res.data?.batches || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load batches.'), 'error')
  } finally {
    loading.value = false
  }
}

function fileName(batch) {
  return `${String(batch.barcode_value).replace(/[^A-Za-z0-9\-_]/g, '_')}.svg`
}

// Search as you type
let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 350)
})

function clearSearch() {
  search.value = ''
}

// Expiry: expired (red), within 30 days (amber), otherwise plain
function expiryTone(date) {
  if (!date) return ''
  const t = new Date(`${String(date).slice(0, 10)}T00:00:00`).getTime()
  if (Number.isNaN(t)) return ''
  const days = (t - new Date().setHours(0, 0, 0, 0)) / 86400000
  if (days < 0) return 'is-expired'
  if (days <= 30) return 'is-soon'
  return ''
}

watch(showEmpty, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Reports</p>
        <h1 class="hero-title">Batch Inventory</h1>
        <p class="hero-subtitle">All stock batches with their unique barcodes. Download a barcode to print a label.</p>
      </div>
    </div>

    <section class="panel">
      <form class="panel-header batch-toolbar" @submit.prevent="load">
        <div class="batch-search">
          <Search :size="16" class="batch-search-icon" />
          <input
            v-model="search"
            type="text"
            class="form-input"
            placeholder="Search product, batch no or barcode…"
            aria-label="Search batches"
          />
          <button v-if="search" type="button" class="batch-search-clear" aria-label="Clear search" @click="clearSearch">
            <X :size="14" />
          </button>
        </div>

        <div class="batch-toolbar-right">
          <span v-if="!loading" class="batch-count">
            <Boxes :size="15" />
            {{ batches.length }} batch{{ batches.length === 1 ? '' : 'es' }}
          </span>

          <label class="batch-toggle">
            <input v-model="showEmpty" type="checkbox" />
            <span class="batch-toggle-track" aria-hidden="true"><span class="batch-toggle-thumb"></span></span>
            <span>Show empty batches</span>
          </label>
        </div>
      </form>

      <div class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th>Batch No</th>
              <th>Product</th>
              <th>Unit</th>
              <th style="text-align: right;">Qty</th>
              <th>Manufactured</th>
              <th>Expiry</th>
              <th>Received</th>
              <th>Barcode</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="9" class="batch-empty">Loading batches…</td></tr>
            <tr v-else-if="!batches.length">
              <td colspan="9" class="batch-empty">
                <Barcode :size="30" />
                <strong>No batches found{{ search ? ` for "${search.trim()}"` : '' }}.</strong>
                <span v-if="!showEmpty">Batches with no stock left are hidden; turn on "Show empty batches" to see them.</span>
              </td>
            </tr>
            <template v-else>
            <tr v-for="b in paginatedBatches" :key="b.batch_id">
              <td data-label="Batch No"><span class="batch-no">{{ b.batch_no }}</span></td>
              <td class="cell-title">
                <router-link :to="{ path: '/stockcard', query: { item_id: b.product_id } }" class="batch-product">{{ b.product }}</router-link>
              </td>
              <td data-label="Unit">{{ b.unit_name || '—' }}</td>
              <td data-label="Qty" class="batch-qty" :class="{ 'is-zero': Number(b.current_qty) <= 0 }">{{ Number(b.current_qty) }}</td>
              <td data-label="Manufactured">
                <span v-if="b.manufacturing_date">{{ b.manufacturing_date }}</span>
                <span v-else class="batch-muted">—</span>
              </td>
              <td data-label="Expiry">
                <span v-if="b.expiration_date" class="batch-expiry" :class="expiryTone(b.expiration_date)">{{ b.expiration_date }}</span>
                <span v-else class="batch-muted">—</span>
              </td>
              <td data-label="Received">
                <span v-if="b.date_received">{{ b.date_received }}</span>
                <span v-else class="batch-muted">—</span>
              </td>
              <td data-label="Barcode">
                <div v-if="b.barcode_url" class="batch-barcode">
                  <img :src="b.barcode_url" :alt="`Barcode ${b.barcode_value}`" loading="lazy" class="barcode-img" />
                  <span class="batch-barcode-value">{{ b.barcode_value }}</span>
                </div>
                <span v-else class="batch-muted">—</span>
              </td>
              <td class="cell-actions" style="text-align: right;">
                <a v-if="b.barcode_url" :href="b.barcode_url" :download="fileName(b)" class="btn btn-sm btn-secondary batch-download">
                  <Download :size="14" /> Download
                </a>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="batches.length > 0"
        :current-page="currentPage"
        :total-pages="totalPages"
        :total-items="batches.length"
        :page-size="pageSize"
        :page-size-options="[5, 10, 15, 25, 50, 100]"
        item-name="batches"
        @update:current-page="currentPage = $event"
        @update:page-size="pageSize = $event; currentPage = 1"
      />
    </section>
  </div>
</template>

<style scoped>
/* ── Toolbar ── */
.batch-toolbar {
  background: var(--bg-subtle);
  gap: 0.75rem 1rem;
}

.batch-search {
  position: relative;
  flex: 1;
  min-width: 220px;
  max-width: 420px;
}

.batch-search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  pointer-events: none;
}

.batch-search .form-input {
  width: 100%;
  padding-left: 36px;
  padding-right: 34px;
}

.batch-search-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border: 0;
  border-radius: 50%;
  background: var(--bg-muted);
  color: var(--text-muted);
  cursor: pointer;
}

.batch-search-clear:hover {
  color: var(--text-main);
}

.batch-toolbar-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
}

.batch-count {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.83rem;
  font-weight: 600;
  color: var(--text-muted);
}

/* Switch-style toggle for "Show empty batches" */
.batch-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.86rem;
  font-weight: 600;
  color: var(--text-main);
  cursor: pointer;
  user-select: none;
}

.batch-toggle input {
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;
  pointer-events: none;
}

.batch-toggle-track {
  position: relative;
  width: 36px;
  height: 20px;
  flex-shrink: 0;
  border-radius: 9999px;
  background: var(--bg-muted);
  border: 1px solid var(--border-subtle);
  transition: background var(--transition-fast), border-color var(--transition-fast);
}

.batch-toggle-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
  transition: transform var(--transition-fast);
}

.batch-toggle input:checked + .batch-toggle-track {
  background: var(--color-primary);
  border-color: var(--color-primary);
}

.batch-toggle input:checked + .batch-toggle-track .batch-toggle-thumb {
  transform: translateX(16px);
}

.batch-toggle input:focus-visible + .batch-toggle-track {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

/* ── Table cells ── */
.batch-empty {
  padding: 2.5rem 1rem !important;
  text-align: center !important;
  color: var(--text-muted);
}

.batch-empty svg {
  display: block;
  margin: 0 auto 0.5rem;
  opacity: 0.6;
}

.batch-empty strong {
  display: block;
  color: var(--text-main);
  margin-bottom: 0.25rem;
}

.batch-no {
  display: inline-block;
  padding: 0.15rem 0.55rem;
  border-radius: var(--radius-sm);
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  font-family: var(--font-mono);
  font-size: 0.8rem;
  white-space: nowrap;
}

.batch-product {
  font-weight: 700;
  color: var(--text-main);
  text-decoration: none;
}

.batch-product:hover {
  color: var(--color-primary);
  text-decoration: underline;
}

.batch-qty {
  text-align: right;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.batch-qty.is-zero {
  color: var(--text-muted);
}

.batch-muted {
  color: var(--text-muted);
}

.batch-expiry {
  white-space: nowrap;
}

.batch-expiry.is-expired,
.batch-expiry.is-soon {
  padding: 0.12rem 0.5rem;
  border-radius: var(--radius-full);
  font-size: 0.8rem;
  font-weight: 700;
}

.batch-expiry.is-expired {
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

.batch-expiry.is-soon {
  background: var(--color-warning-bg);
  color: var(--color-warning);
}

.batch-barcode {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
}

/* Barcodes stay on white so they scan and read in dark mode too */
.barcode-img {
  height: 44px;
  max-width: 220px;
  background: #fff;
  padding: 3px 6px;
  border-radius: 6px;
  border: 1px solid var(--border-subtle);
}

.batch-barcode-value {
  font-family: var(--font-mono);
  font-size: 0.72rem;
  color: var(--text-muted);
}

.batch-download {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
}

@media (max-width: 640px) {
  .batch-search {
    max-width: none;
    flex-basis: 100%;
  }

  .batch-toolbar-right {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
