<script setup>
import { ref, onMounted } from 'vue'
import { barcodesApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'

const products = ref([])
const loading = ref(true)
const search = ref('')
const generatingId = ref(0)

async function load() {
  loading.value = true
  try {
    const res = await barcodesApi.getFinishedProducts({ search: search.value })
    products.value = res.data?.products || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load finished products.'), 'error')
  } finally {
    loading.value = false
  }
}

async function generate(product) {
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

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Product No</th>
              <th>Stock No</th>
              <th>Product</th>
              <th>Unit</th>
              <th>Barcode</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading…</td></tr>
            <tr v-else-if="!products.length">
              <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                No finished products found{{ search ? ` for "${search}"` : '' }}. Products need the "Finished Product" type.
              </td>
            </tr>
            <tr v-for="p in products" :key="p.product_id">
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
                <a v-if="p.barcode_url" :href="p.barcode_url" :download="fileName(p)" class="btn btn-sm btn-secondary">⬇ Download</a>
                <button v-else type="button" class="btn btn-sm btn-primary" :disabled="generatingId === Number(p.product_id)" @click="generate(p)">
                  {{ generatingId === Number(p.product_id) ? 'Generating…' : 'Generate' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
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
