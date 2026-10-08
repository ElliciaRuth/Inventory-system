<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { reportsApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'
import AppPagination from '../components/AppPagination.vue'
import { Download } from 'lucide-vue-next'

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
    const res = await reportsApi.getBatches({ search: search.value, show_empty: showEmpty.value ? 1 : 0 })
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
      <form class="panel-header" style="gap: 0.75rem; flex-wrap: wrap;" @submit.prevent="load">
        <input v-model="search" type="text" class="form-input" placeholder="Search product, batch no or barcode…" style="max-width: 340px;" />
        <button type="submit" class="btn btn-primary">Search</button>
        <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem;">
          <input v-model="showEmpty" type="checkbox" /> Show empty batches
        </label>
      </form>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Batch No</th>
              <th>Product</th>
              <th>Unit</th>
              <th style="text-align: right;">Qty</th>
              <th>Expiry</th>
              <th>Received</th>
              <th>Barcode</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading batches…</td></tr>
            <tr v-else-if="!batches.length"><td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">No batches found{{ search ? ` for "${search}"` : '' }}.</td></tr>
            <tr v-for="b in paginatedBatches" :key="b.batch_id">
              <td style="font-family: var(--font-mono);">{{ b.batch_no }}</td>
              <td><router-link :to="{ path: '/stockcard', query: { item_id: b.product_id } }">{{ b.product }}</router-link></td>
              <td>{{ b.unit_name }}</td>
              <td style="text-align: right; font-weight: 700;">{{ Number(b.current_qty) }}</td>
              <td>{{ b.expiration_date || '—' }}</td>
              <td>{{ b.date_received || '—' }}</td>
              <td>
                <template v-if="b.barcode_url">
                  <img :src="b.barcode_url" :alt="`Barcode ${b.barcode_value}`" loading="lazy" class="barcode-img" />
                  <div style="font-family: var(--font-mono); font-size: 0.75rem;">{{ b.barcode_value }}</div>
                </template>
                <span v-else style="color: var(--text-muted);">—</span>
              </td>
              <td style="text-align: right;">
                <a v-if="b.barcode_url" :href="b.barcode_url" :download="fileName(b)" class="btn btn-sm btn-secondary"><Download :size="14" /> Download</a>
              </td>
            </tr>
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
.barcode-img {
  height: 44px;
  max-width: 220px;
  background: #fff;
  padding: 2px;
  border-radius: 4px;
}
</style>
