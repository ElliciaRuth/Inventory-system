<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { productsApi } from '../api/products'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById, triggerAutoReload } from '../composables/useAutoReload'
import ProductModal from '../components/ProductModal.vue'
import AppPagination from '../components/AppPagination.vue'
import ImportStockcardsModal from '../components/ImportStockcardsModal.vue'
import { Pencil, Trash2, FileSpreadsheet, Archive, ArchiveRestore, History } from 'lucide-vue-next'
import { confirmDialog } from '../composables/useConfirm'
import { toast, errorMessage } from '../composables/useToast'

const authStore = useAuthStore()
const route = useRoute()
const products = ref([])
const productTypes = ref([])
const loading = ref(true)
// ?search= lets a notification open the catalog filtered to one product
const searchQuery = ref(String(route.query.search || ''))
const selectedType = ref(0)
const deletingId = ref(null)

// Active products, or the archived ones (custodians and managers only)
const view = ref('active')
const archivedCount = ref(0)
const showingArchived = computed(() => view.value === 'archived')

// Modal states
const isModalOpen = ref(false)
const isImportOpen = ref(false)
const selectedProduct = ref(null)

// Pagination
const currentPage = ref(1)
const pageSize = ref(15)

async function loadData() {
  loading.value = true
  try {
    const filters = { search: searchQuery.value, type_id: selectedType.value }
    const [prodRes, metaRes, archivedRes] = await Promise.all([
      productsApi.getProducts({ ...filters, archived: showingArchived.value ? 1 : undefined }),
      productsApi.getMeta(),
      // Count for the Archived tab
      authStore.canManageStock && !showingArchived.value ? productsApi.getProducts({ archived: 1 }) : null,
    ])

    if (prodRes.status && prodRes.data) {
      products.value = deduplicateById(prodRes.data || [], 'product_id')
    }
    if (metaRes.status && metaRes.data) {
      productTypes.value = metaRes.data.types || []
    }
    if (showingArchived.value) {
      archivedCount.value = products.value.length
    } else if (archivedRes?.status) {
      archivedCount.value = (archivedRes.data || []).length
    }
  } catch (err) {
    console.error('Failed to load products', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(loadData)

watch(() => route.query.search, (value) => {
  if (value !== undefined) searchQuery.value = String(value)
})

// Debounced / on-change search
let searchTimer = null
watch(searchQuery, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    currentPage.value = 1
    loadData()
  }, 350)
})

watch([selectedType, view], () => {
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
    message: `"${prod.product}" will be permanently deleted. This cannot be undone. Only products with no stock history can be deleted; archive the others.`,
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
      // Has history: offer to archive instead
      if (err.response?.data?.errors?.can_archive) {
        deletingId.value = null
        const archiveInstead = await confirmDialog({
          title: 'Archive instead?',
          message: errorMessage(err),
          confirmText: 'Archive',
        })
        if (archiveInstead) openArchive(prod)
      } else {
        toast(errorMessage(err, 'Failed to delete product.'), 'error')
      }
    } finally {
      deletingId.value = null
    }
  }
}

// ── Archive (with an optional reason) / restore ──
const ARCHIVE_REASONS = ['No longer used', 'Discontinued by supplier', 'Replaced by another product', 'Seasonal item']
const archiving = ref(null)
const archiveReason = ref('')
const archiveError = ref('')
const archiveBusy = ref(false)

function openArchive(prod) {
  archiving.value = prod
  archiveReason.value = ''
  archiveError.value = ''
}

function closeArchive() {
  if (!archiveBusy.value) archiving.value = null
}

async function confirmArchive() {
  archiveBusy.value = true
  archiveError.value = ''
  try {
    const res = await productsApi.archiveProduct(archiving.value.product_id, archiveReason.value.trim())
    toast(res.message || 'Product archived.')
    archiving.value = null
    triggerAutoReload('archive-product')
    await loadData()
  } catch (err) {
    archiveError.value = errorMessage(err, 'The product could not be archived.')
  } finally {
    archiveBusy.value = false
  }
}

async function handleRestore(prod) {
  const ok = await confirmDialog({
    title: 'Restore product?',
    message: `"${prod.product}" goes back to the product lists, the stock forms and stock alerts.`,
    confirmText: 'Restore',
  })
  if (!ok) return
  try {
    const res = await productsApi.restoreProduct(prod.product_id)
    toast(res.message || 'Product restored.')
    triggerAutoReload('restore-product')
    await loadData()
  } catch (err) {
    toast(errorMessage(err, 'The product could not be restored.'), 'error')
  }
}

function shortDate(value) {
  if (!value) return ''
  const d = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
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

      <div v-if="authStore.canManageStock" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <button
          v-if="authStore.levelId === 2 || authStore.levelId === 3"
          type="button"
          class="btn btn-secondary"
          title="Import Appendix 58 stock cards from an Excel file"
          @click="isImportOpen = true"
        >
          <FileSpreadsheet :size="15" /> Import from Excel
        </button>
        <button type="button" class="btn btn-primary" @click="openAddModal">
          <span style="font-size: 1.1rem; line-height: 1;">+</span> Add New Product
        </button>
      </div>
    </div>

    <!-- Filter & Search Toolbar Panel -->
    <div class="panel">
      <!-- Active / Archived -->
      <div v-if="authStore.canManageStock" class="pv-tabs" role="tablist">
        <button type="button" role="tab" class="pv-tab" :class="{ 'is-active': view === 'active' }" :aria-selected="view === 'active'" @click="view = 'active'">
          Active products
        </button>
        <button type="button" role="tab" class="pv-tab" :class="{ 'is-active': view === 'archived' }" :aria-selected="view === 'archived'" @click="view = 'archived'">
          <Archive :size="14" /> Archived <span class="pv-tab-count">{{ archivedCount }}</span>
        </button>
      </div>

      <div v-if="showingArchived" class="pv-archived-note">
        <Archive :size="15" />
        Archived products are hidden from the stock forms, stock-out requests, physical counts and stock alerts.
        Their stockcards, batches and reports are kept. Restore one to use it again.
      </div>

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
              <th v-if="!showingArchived" style="text-align: right;">Total Stock</th>
              <th v-else>Archived</th>
              <th v-if="authStore.canManageStock" style="width: 170px; text-align: center;">Actions</th>
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
              <td v-if="showingArchived" data-label="Archived">
                <span class="pv-archived-when">{{ shortDate(p.archived_at) }}<template v-if="p.archived_by_name"> · {{ p.archived_by_name }}</template></span>
                <span v-if="p.archive_reason" class="pv-archived-reason">{{ p.archive_reason }}</span>
              </td>
              <td v-else data-label="Total Stock" style="text-align: right;">
                <span
                  class="badge"
                  :class="Number(p.total_stock) <= 0 ? 'badge-danger' : 'badge-success'"
                  style="font-family: var(--font-mono); font-size: 0.85rem; font-weight: 700;"
                >
                  {{ p.total_stock }} {{ p.unit_name }}
                </span>
              </td>
              <td v-if="authStore.canManageStock && showingArchived" class="cell-actions" style="text-align: center;">
                <div style="display: inline-flex; gap: 0.4rem;">
                  <router-link
                    :to="{ path: '/stockcard', query: { item_id: p.product_id } }"
                    class="btn btn-sm btn-secondary"
                    title="View its stockcard and history"
                  >
                    <History :size="13" />
                  </router-link>
                  <button type="button" class="btn btn-sm btn-primary pv-restore" title="Restore to the active lists" @click="handleRestore(p)">
                    <ArchiveRestore :size="14" /> Restore
                  </button>
                </div>
              </td>
              <td v-else-if="authStore.canManageStock" class="cell-actions" style="text-align: center;">
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
                    title="Archive: hide it from lists but keep its records"
                    @click="openArchive(p)"
                  >
                    <Archive :size="14" />
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
                {{ showingArchived ? (searchQuery || selectedType ? 'No archived products match.' : 'No archived products.') : 'No matching products found.' }}
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

    <!-- Archive a product -->
    <div v-if="archiving" class="modal-backdrop" @click.self="closeArchive">
      <form class="modal-card" style="max-width: 500px;" @submit.prevent="confirmArchive">
        <div class="modal-header">
          <div>
            <h2 style="font-size: 1.15rem; display: flex; align-items: center; gap: 0.5rem;"><Archive :size="18" /> Archive product?</h2>
            <p class="panel-subtitle"><strong>{{ archiving.product }}</strong> · #{{ archiving.product_no }}</p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="archiveBusy" @click="closeArchive">✕</button>
        </div>
        <div class="modal-body">
          <p class="pv-archive-text">
            It disappears from the product lists, stock forms, stock-out requests, physical counts and stock alerts.
            Its stockcard, batches, reports and audit history are kept, and you can restore it from the Archived tab.
          </p>
          <label class="form-label" for="archiveReason">Reason (optional)</label>
          <input id="archiveReason" v-model="archiveReason" type="text" class="form-input" maxlength="255" placeholder="e.g. No longer used" autofocus />
          <div class="pv-reasons">
            <button v-for="r in ARCHIVE_REASONS" :key="r" type="button" class="pv-reason" @click="archiveReason = r">{{ r }}</button>
          </div>
          <p v-if="archiveError" class="pv-archive-error">{{ archiveError }}</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="archiveBusy" @click="closeArchive">Cancel</button>
          <button type="submit" class="btn btn-primary" :disabled="archiveBusy">
            <Archive :size="14" /> {{ archiveBusy ? 'Archiving…' : 'Archive' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Excel stock card import (managers) -->
    <ImportStockcardsModal
      :is-open="isImportOpen"
      @close="isImportOpen = false"
      @imported="loadData"
    />
  </div>
</template>

<style scoped>
.pv-tabs {
  display: flex;
  gap: 0.25rem;
  padding: 0.6rem 1.25rem 0;
  border-bottom: 1px solid var(--border-subtle);
}

.pv-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.55rem 0.9rem;
  margin-bottom: -1px;
  border: 1px solid transparent;
  border-radius: var(--radius-md) var(--radius-md) 0 0;
  background: none;
  color: var(--text-muted);
  font: inherit;
  font-size: 0.875rem;
  font-weight: 700;
  cursor: pointer;
}

.pv-tab:hover {
  color: var(--text-main);
}

.pv-tab.is-active {
  border-color: var(--border-subtle);
  border-bottom-color: var(--bg-surface);
  background: var(--bg-surface);
  color: var(--color-primary);
}

.pv-tab-count {
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: var(--radius-full);
  background: var(--bg-muted);
  color: var(--text-muted);
  font-size: 0.72rem;
  text-align: center;
}

.pv-archived-note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem 1.25rem;
  background: var(--color-info-bg);
  color: var(--color-info);
  font-size: 0.83rem;
  line-height: 1.5;
}

.pv-archived-note svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
}

.pv-archived-when {
  display: block;
  font-size: 0.83rem;
  white-space: nowrap;
}

.pv-archived-reason {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
  font-style: italic;
}

.pv-restore {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  white-space: nowrap;
}

.pv-archive-text {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  line-height: 1.55;
  color: var(--text-muted);
}

.pv-reasons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.75rem;
}

.pv-reason {
  padding: 0.25rem 0.7rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-full);
  background: var(--bg-subtle);
  color: var(--text-main);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.pv-reason:hover {
  border-color: var(--color-primary);
  color: var(--color-primary);
}

.pv-archive-error {
  margin: 0.85rem 0 0;
  padding: 0.6rem 0.8rem;
  border-radius: var(--radius-sm);
  background: var(--color-danger-bg);
  color: var(--color-danger);
  font-size: 0.83rem;
  line-height: 1.5;
}
</style>
