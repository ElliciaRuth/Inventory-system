<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { barcodesApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'
import AppPagination from '../components/AppPagination.vue'
import { Download, X } from 'lucide-vue-next'

const products = ref([])
const loading = ref(true)
const search = ref('')
const generatingId = ref(0)

// Filters (applied to the loaded list)
const barcodeFilter = ref('') // '' | 'generated' | 'missing'
const unitFilter = ref('')

const unitOptions = computed(() =>
  [...new Set(products.value.map((p) => p.unit_name).filter(Boolean))].sort()
)

const hasFilters = computed(() => !!(barcodeFilter.value || unitFilter.value))

function clearFilters() {
  barcodeFilter.value = ''
  unitFilter.value = ''
}

const filteredProducts = computed(() =>
  products.value.filter((p) =>
    (!barcodeFilter.value || (barcodeFilter.value === 'generated') === !!p.barcode_url)
    && (!unitFilter.value || p.unit_name === unitFilter.value)
  )
)

// Pagination
const currentPage = ref(1)
const pageSize = ref(15)

const totalPages = computed(() => Math.max(1, Math.ceil(filteredProducts.value.length / pageSize.value)))

const paginatedProducts = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredProducts.value.slice(start, start + pageSize.value)
})

watch([barcodeFilter, unitFilter], () => {
  currentPage.value = 1
})

// Generating a barcode can drop a row out of the "Not yet generated" filter; stay on a real page
watch(totalPages, (total) => {
  if (currentPage.value > total) currentPage.value = total
})

async function load() {
  loading.value = true
  try {
    const res = await barcodesApi.getFinishedProducts({ search: search.value })
    products.value = res.data?.products || []
    currentPage.value = 1
  } catch (err) {
    toast(errorMessage(err, 'Failed to load finished products.'), 'error')
  } finally {
    loading.value = false
  }
}

async function generate(product) {
  const ok = await confirmDialog({
    title: 'Generate a barcode?',
    message: `A barcode will be made and saved for "${product.product}".`,
    details: [
      ['Product', product.product],
      ['Stock Code', product.product_no],
      ['Stock no.', product.stock_no],
    ],
    confirmText: 'Generate',
  })
  if (!ok) return
  generatingId.value = Number(product.product_id)
  try {
    const res = await barcodesApi.generateFinishedProductBarcode(product.product_id)
    product.barcode_url = res.data?.barcode_url || null
    toast(res.message || 'Barcode generated.')
  } catch (err) {
    toast(errorMessage(err, 'Failed to generate the barcode.'), 'error')
  } finally {
    generatingId.value = 0
  }
}

function fileName(product) {
  return `${String(product.barcode_value).replace(/[^A-Za-z0-9\-_]/g, '_')}.svg`
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Products</p>
        <h1 class="hero-title">Finished Product Barcodes</h1>
        <p class="hero-subtitle">Generate barcodes for finished products, or download the ones already saved.</p>
      </div>
    </div>

    <section class="panel">
      <form class="panel-header" style="gap: 0.75rem;" @submit.prevent="load">
        <input v-model="search" type="text" class="form-input" placeholder="Search product name, stock no…" style="max-width: 340px;" />
        <button type="submit" class="btn btn-primary">Search</button>
      </form>

      <div class="filter-bar">
        <label class="filter-field">
          <span>Barcode</span>
          <select v-model="barcodeFilter" class="form-select">
            <option value="">All</option>
            <option value="generated">Generated</option>
            <option value="missing">Not yet generated</option>
          </select>
        </label>
        <label class="filter-field">
          <span>Unit</span>
          <select v-model="unitFilter" class="form-select">
            <option value="">All units</option>
            <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
          </select>
        </label>
        <button v-if="hasFilters" type="button" class="btn btn-sm btn-secondary filter-clear" @click="clearFilters">
          <X :size="14" /> Clear filters
        </button>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Stock Code</th>
              <th>Stock No</th>
              <th>Product</th>
              <th>Unit</th>
              <th>Barcode</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading…</td></tr>
            <tr v-else-if="!filteredProducts.length">
              <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                <template v-if="products.length">No finished products match the selected filters.</template>
                <template v-else>No finished products found{{ search ? ` for "${search}"` : '' }}. Products need the "Finished Product" type.</template>
              </td>
            </tr>
            <tr v-for="p in paginatedProducts" :key="p.product_id">
              <td>{{ p.product_no }}</td>
              <td style="font-family: var(--font-mono);">{{ p.stock_no }}</td>
              <td style="font-weight: 600;">{{ p.product }}</td>
              <td>{{ p.unit_name }}</td>
              <td>
                <template v-if="p.barcode_url">
                  <img :src="p.barcode_url" :alt="`Barcode ${p.barcode_value}`" loading="lazy" class="barcode-img" />
                  <div style="font-family: var(--font-mono); font-size: 0.75rem;">{{ p.barcode_value }}</div>
                </template>
                <span v-else style="color: var(--text-muted);">Not yet generated</span>
              </td>
              <td style="text-align: right;">
                <a v-if="p.barcode_url" :href="p.barcode_url" :download="fileName(p)" class="btn btn-sm btn-secondary"><Download :size="14" /> Download</a>
                <button v-else type="button" class="btn btn-sm btn-primary" :disabled="generatingId === Number(p.product_id)" @click="generate(p)">
                  {{ generatingId === Number(p.product_id) ? 'Generating…' : 'Generate' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="!loading && filteredProducts.length > 0"
        :current-page="currentPage"
        :total-pages="totalPages"
        :total-items="filteredProducts.length"
        :page-size="pageSize"
        :page-size-options="[5, 10, 15, 25, 50, 100]"
        item-name="products"
        @update:current-page="currentPage = $event"
        @update:page-size="pageSize = $event; currentPage = 1"
      />
    </section>
  </div>
</template>

<style scoped>
.barcode-img {
  height: 44px;
  max-width: 220px;
  background: #fff;
  padding: 2px;
  border-radius: 4px;
}
</style>
