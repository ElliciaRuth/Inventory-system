<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { stockoutApi } from '../api/stockout'
import { stockApi } from '../api/stock'
import { barcodesApi } from '../api/reports'
import { toast, errorMessage } from '../composables/useToast'

const items = ref([])
const loading = ref(true)
const tableSearch = ref('')

// Request form
const pickerQuery = ref('')
const selectedId = ref(0)
const copies = ref([])
const copyId = ref('')
const quantity = ref('')
const formError = ref('')
const saving = ref(false)
const scanning = ref(false)

const selected = computed(() => items.value.find((i) => Number(i.product_id) === Number(selectedId.value)) || null)
const selectedCopy = computed(() => copies.value.find((c) => Number(c.copy_id) === Number(copyId.value)) || null)
const maxQty = computed(() => (selectedCopy.value ? Number(selectedCopy.value.current_stock) : Number(selected.value?.current_stock || 0)))

const pickerMatches = computed(() => {
  const q = pickerQuery.value.trim().toLowerCase()
  if (!q) return []
  return items.value.filter((i) => i.product.toLowerCase().includes(q)).slice(0, 8)
})

const filteredItems = computed(() => {
  const q = tableSearch.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((i) =>
    [i.product, i.unit_name, i.description].some((v) => String(v || '').toLowerCase().includes(q))
  )
})

function peso(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function stockBadge(stock) {
  const n = Number(stock)
  if (n <= 0) return 'badge-danger'
  if (n <= 5) return 'badge-warning'
  return 'badge-success'
}

async function loadItems() {
  loading.value = true
  try {
    const res = await stockoutApi.getAvailableItems()
    items.value = res.data?.items || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load products.'), 'error')
  } finally {
    loading.value = false
  }
}

function selectProduct(item) {
  selectedId.value = Number(item.product_id)
  pickerQuery.value = ''
  formError.value = ''
}

function clearSelection() {
  selectedId.value = 0
  copies.value = []
  copyId.value = ''
  quantity.value = ''
}

// Load sub-products (price variants) whenever the product changes
watch(selectedId, async (id) => {
  copies.value = []
  copyId.value = ''
  if (!id) return
  try {
    const res = await stockApi.getCopies(id)
    copies.value = res.data?.copies || []
    const inStock = copies.value.filter((c) => Number(c.current_stock) > 0)
    if (inStock.length === 1) copyId.value = String(inStock[0].copy_id)
  } catch (err) {
    toast(errorMessage(err, 'Failed to load sub-products.'), 'error')
  }
})

// Enter in the search box: pick the single match, or treat the text as a scanned barcode
async function handlePickerEnter() {
  const value = pickerQuery.value.trim()
  if (!value) return
  if (pickerMatches.value.length === 1) {
    selectProduct(pickerMatches.value[0])
    return
  }
  scanning.value = true
  formError.value = ''
  try {
    const res = await barcodesApi.lookup(value)
    const item = items.value.find((i) => Number(i.product_id) === Number(res.data?.product_id))
    if (item) {
      selectProduct(item)
    } else {
      formError.value = 'Product not found in available stock.'
    }
  } catch (err) {
    formError.value = errorMessage(err, 'Barcode not recognised.')
  } finally {
    scanning.value = false
  }
}

async function addToList() {
  formError.value = ''
  if (!selected.value) {
    formError.value = 'Please select a product.'
    return
  }
  if (copies.value.length && !copyId.value) {
    formError.value = 'Please select a sub-product (price variant).'
    return
  }
  const qty = Number(quantity.value)
  if (!(qty >= 1) || !Number.isInteger(qty)) {
    formError.value = 'Enter a whole quantity of at least 1.'
    return
  }
  if (qty > maxQty.value) {
    formError.value = `Cannot request ${qty} — only ${maxQty.value} available.`
    return
  }

  saving.value = true
  try {
    await stockoutApi.addToDraft({
      product_id: selected.value.product_id,
      copy_id: copyId.value ? Number(copyId.value) : 0,
      quantity: qty,
      unit: selected.value.unit_name || '',
      description: selected.value.description || '',
    })
    toast(`${selected.value.product} added to your list.`)
    clearSelection()
  } catch (err) {
    formError.value = errorMessage(err, 'Failed to add the item.')
  } finally {
    saving.value = false
  }
}

onMounted(loadItems)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Stock Out</p>
        <h1 class="hero-title">Request Stock Out</h1>
        <p class="hero-subtitle">Search for a product or scan a barcode to add it to your stock-out list.</p>
      </div>
      <router-link to="/stockout/list" class="btn btn-primary">📋 View My List</router-link>
    </div>

    <section class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header">
        <div class="panel-title-group">
          <h2 class="panel-title">Add Product to Stock-Out</h2>
        </div>
      </div>
      <form style="padding: 1.25rem;" @submit.prevent="addToList">
        <div v-if="formError" class="badge badge-danger" style="display: flex; margin-bottom: 1rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;">
          ⚠️ {{ formError }}
        </div>

        <div class="form-group" style="position: relative;">
          <label class="form-label">Product <small style="font-weight: 400;">— type to search, or scan a barcode and press Enter</small></label>
          <div v-if="selected" style="display: flex; gap: 0.75rem; align-items: center;">
            <div class="form-input" style="flex: 1; font-weight: 700;">📦 {{ selected.product }}</div>
            <button type="button" class="btn btn-sm btn-secondary" @click="clearSelection">Change</button>
          </div>
          <template v-else>
            <input
              v-model="pickerQuery"
              type="text"
              class="form-input"
              placeholder="Search product or scan barcode…"
              autocomplete="off"
              @keydown.enter.prevent="handlePickerEnter"
            />
            <small v-if="scanning" style="color: var(--text-muted);">Looking up barcode…</small>
            <ul v-if="pickerMatches.length" class="picker-list">
              <li v-for="item in pickerMatches" :key="item.product_id" @mousedown.prevent="selectProduct(item)">
                <span>
                  <strong>{{ item.product }}</strong>
                  <small v-if="item.unit_name || item.description"> · {{ [item.unit_name, item.description].filter(Boolean).join(' · ') }}</small>
                </span>
                <span class="badge" :class="stockBadge(item.current_stock)">{{ Number(item.current_stock) }} left</span>
              </li>
            </ul>
          </template>
        </div>

        <div v-if="copies.length" class="form-group">
          <label class="form-label">Sub-Product (Price Variant) *</label>
          <select v-model="copyId" class="form-select">
            <option value="">— Select sub-product —</option>
            <option v-for="c in copies" :key="c.copy_id" :value="String(c.copy_id)" :disabled="Number(c.current_stock) <= 0">
              ₱{{ peso(c.unit_cost) }}{{ c.label ? ` (${c.label})` : '' }} — Stock: {{ Number(c.current_stock) }}{{ Number(c.current_stock) <= 0 ? ' (Out of stock)' : '' }}
            </option>
          </select>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
          <div class="form-group">
            <label class="form-label">Unit</label>
            <input :value="selected?.unit_name || ''" class="form-input" placeholder="—" readonly tabindex="-1" />
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <input :value="selected?.description || ''" class="form-input" placeholder="—" readonly tabindex="-1" />
          </div>
          <div class="form-group">
            <label class="form-label">Quantity *</label>
            <input v-model="quantity" type="number" min="1" step="1" :max="maxQty || undefined" class="form-input" placeholder="Enter quantity" />
          </div>
        </div>

        <button type="submit" class="btn btn-primary" :disabled="!selected || saving">
          {{ saving ? 'Adding…' : '+ Add to My List' }}
        </button>
      </form>
    </section>

    <section class="panel">
      <div class="panel-header">
        <div class="panel-title-group">
          <h2 class="panel-title">Available Products</h2>
        </div>
        <input v-model="tableSearch" type="text" class="form-input" placeholder="Search products…" style="max-width: 280px;" />
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Unit</th>
              <th>Description</th>
              <th style="text-align: right;">Current Stock</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading products…</td></tr>
            <tr v-else-if="!filteredItems.length"><td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-muted);">No products found.</td></tr>
            <tr v-for="item in filteredItems" :key="item.product_id">
              <td style="font-weight: 600;">{{ item.product }}</td>
              <td>{{ item.unit_name || '' }}</td>
              <td>{{ item.description || '' }}</td>
              <td style="text-align: right;"><span class="badge" :class="stockBadge(item.current_stock)">{{ Number(item.current_stock) }}</span></td>
              <td style="text-align: right;">
                <button type="button" class="btn btn-sm btn-secondary" :disabled="Number(item.current_stock) <= 0" @click="selectProduct(item)">Select</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.picker-list {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  z-index: 20;
  margin: 4px 0 0;
  padding: 4px;
  list-style: none;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
  max-height: 320px;
  overflow-y: auto;
}

.picker-list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.picker-list li:hover {
  background: var(--bg-subtle);
}

.picker-list small {
  color: var(--text-muted);
}
</style>
