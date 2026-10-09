<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { productsApi } from '../api/products'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById, triggerAutoReload } from '../composables/useAutoReload'
import ProductModal from '../components/ProductModal.vue'
import AppPagination from '../components/AppPagination.vue'
import { Pencil, Trash2 } from 'lucide-vue-next'
import { confirmDialog } from '../composables/useConfirm'
import { toast, errorMessage } from '../composables/useToast'

const authStore = useAuthStore()
const products = ref([])
const productTypes = ref([])
const loading = ref(true)
const searchQuery = ref('')
const selectedType = ref(0)
const deletingId = ref(null)

// Modal states
const isModalOpen = ref(false)
const selectedProduct = ref(null)

// Pagination
const currentPage = ref(1)
const pageSize = ref(15)

async function loadData() {
  loading.value = true
  try {
    const [prodRes, metaRes] = await Promise.all([
      productsApi.getProducts({ search: searchQuery.value, type_id: selectedType.value }),
      productsApi.getMeta(),
    ])

    if (prodRes.status && prodRes.data) {
      products.value = deduplicateById(prodRes.data || [], 'product_id')
    }
    if (metaRes.status && metaRes.data) {
      productTypes.value = metaRes.data.types || []
    }
  } catch (err) {
    console.error('Failed to load products', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(loadData)

// Debounced / on-change search
let searchTimer = null
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    currentPage.value = 1
    loadData()
  }, 350)
})

watch(selectedType, () => {
  currentPage.value = 1
  loadData()
})

const totalPages = computed(() => {
  return Math.max(1, Math.ceil(products.value.length / pageSize.value))
})

const paginatedProducts = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return products.value.slice(start, start + pageSize.value)
})

function openAddModal() {
  selectedProduct.value = null
  isModalOpen.value = true
}

function openEditModal(prod) {
  selectedProduct.value = prod
  isModalOpen.value = true
}

async function handleDelete(prod) {
  if (deletingId.value) return
  const ok = await confirmDialog({
    title: 'Delete product?',
    message: `"${prod.product}" will be permanently deleted. This cannot be undone.`,
    confirmText: 'Delete',
    variant: 'danger',
  })
  if (ok) {
    deletingId.value = prod.product_id
    try {
      await productsApi.deleteProduct(prod.product_id)
      triggerAutoReload('delete-product')
      await loadData()
    } catch (err) {
      toast(errorMessage(err, 'Failed to delete product.'), 'error')
    } finally {
      deletingId.value = null
    }
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="products-page">
    <!-- Header Hero -->
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Inventory Catalog</p>
        <h1 class="hero-title">Product Management</h1>
        <p class="hero-subtitle">
          Manage SKU catalog, view on-hand counts, monitor reorder thresholds, and register new university items.
        </p>
      </div>

      <div v-if="authStore.canManageStock">
        <button type="button" class="btn btn-primary" @click="openAddModal">
          <span style="font-size: 1.1rem; line-height: 1;">+</span> Add New Product
        </button>
      </div>
    </div>

    <!-- Filter & Search Toolbar Panel -->
    <div class="panel">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <div style="display: flex; gap: 1rem; align-items: center; flex: 1; flex-wrap: wrap;">
          <!-- Search input -->
          <div style="flex: 1; min-width: 250px;">
            <input
              v-model="searchQuery"
              type="text"
              class="form-input"
              style="width: 100%;"
              placeholder="Search by product name, code, or description..."
            />
          </div>

          <!-- Type filter dropdown -->
          <div style="min-width: 200px;">
            <select v-model="selectedType" class="form-select" style="width: 100%;">
              <option :value="0">All Product Types ({{ products.length }})</option>
              <option v-for="t in productTypes" :key="t.type_id" :value="Number(t.type_id)">
                {{ t.type }}
              </option>
            </select>
          </div>
        </div>
      </div>

      <!-- Products Data Table -->
      <div class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th style="width: 80px;">No.</th>
              <th>Stock Code</th>
              <th>Product Name</th>
              <th>Category</th>
              <th>Unit / Spec</th>
              <th style="text-align: right;">Total Stock</th>
              <th v-if="authStore.canManageStock" style="width: 140px; text-align: center;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in paginatedProducts" :key="p.product_id">
              <td data-label="No.">
                <span class="badge badge-neutral" style="font-family: var(--font-mono);">
                  #{{ p.product_no }}
                </span>
              </td>
              <td data-label="Stock Code" style="font-family: var(--font-mono); font-size: 0.825rem; font-weight: 600; color: var(--text-muted);">
                {{ p.stock_no || '—' }}
              </td>
              <td class="cell-title">
                <div style="font-weight: 700; color: var(--text-main);">{{ p.product }}</div>
                <div v-if="p.product_description" style="font-size: 0.775rem; color: var(--text-muted);">
                  {{ p.product_description }}
                </div>
              </td>
              <td data-label="Category">
                <span class="badge badge-info">{{ p.type_name || 'General' }}</span>
              </td>
              <td data-label="Unit / Spec">
                <div>
                  <span style="font-size: 0.85rem; color: var(--text-main);">
                    {{ p.unit_name || 'pcs' }}
                  </span>
                  <span v-if="p.measurement" style="font-size: 0.75rem; color: var(--text-subtle); display: block;">
                    {{ p.measurement }}
                  </span>
                </div>
              </td>
              <td data-label="Total Stock" style="text-align: right;">
                <span
                  class="badge"
                  :class="Number(p.total_stock) <= 0 ? 'badge-danger' : 'badge-success'"
                  style="font-family: var(--font-mono); font-size: 0.85rem; font-weight: 700;"
                >
                  {{ p.total_stock }} {{ p.unit_name }}
                </span>
              </td>
              <td v-if="authStore.canManageStock" class="cell-actions" style="text-align: center;">
                <div style="display: inline-flex; gap: 0.4rem;">
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    title="Edit Product"
                    @click="openEditModal(p)"
                  >
                    <Pencil :size="13" /> Edit
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    title="Delete Product"
                    @click="handleDelete(p)"
                    style="color: var(--color-danger);"
                  >
                    <Trash2 :size="14" />
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="loading">
              <td :colspan="authStore.canManageStock ? 7 : 6" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                Loading inventory catalog...
              </td>
            </tr>
            <tr v-else-if="!paginatedProducts.length">
              <td :colspan="authStore.canManageStock ? 7 : 6" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                No matching products found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="products.length > 0"
        :current-page="currentPage"
        :total-pages="totalPages"
        :total-items="products.length"
        :page-size="pageSize"
        :page-size-options="[5, 10, 15, 25, 50, 100]"
        item-name="products"
        @update:current-page="currentPage = $event"
        @update:page-size="pageSize = $event; currentPage = 1"
      />
    </div>

    <!-- Product Modal for Create/Edit -->
    <ProductModal
      :is-open="isModalOpen"
      :product="selectedProduct"
      @close="isModalOpen = false"
      @saved="loadData"
    />
  </div>
</template>
