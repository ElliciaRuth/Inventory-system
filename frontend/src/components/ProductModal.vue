<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { X, AlertTriangle, Zap, Package, PlusCircle } from 'lucide-vue-next'
import { productsApi } from '../api/products'
import { triggerAutoReload } from '../composables/useAutoReload'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  product: { type: Object, default: null }, // If null, creating new; if object, editing
})

const emit = defineEmits(['close', 'saved'])

const loading = ref(false)
const errorMessage = ref('')
// Editing the name/description asks whether this is the same product or a new one
const askProductAction = ref(false)
const meta = ref({ types: [], units: [], entities: [] })

const form = reactive({
  product_no: '',
  product: '',
  product_description: '',
  measurement: '',
  product_reorder_point: 10,
  expiry_warning_days: 30,
  expiry_danger_days: 7,
  type_name: '',
  unit_name: '',
  entity_name: '',
})

// Standard categories always offered first; the office's own types follow
const STANDARD_CATEGORIES = [
  'Perishable Raw Materials',
  'Non-Perishable Raw Materials',
  'Packaging',
  'Operational Supplies',
]
const OTHER_TYPE = '__other__'

const categoryOptions = computed(() => {
  const seen = new Set()
  const list = []
  const add = (name) => {
    const clean = String(name || '').trim()
    if (!clean || seen.has(clean.toLowerCase())) return
    seen.add(clean.toLowerCase())
    list.push(clean)
  }
  STANDARD_CATEGORIES.forEach(add)
  meta.value.types
    .map((t) => t.type)
    .sort((a, b) => String(a).localeCompare(String(b)))
    .forEach(add)
  add(form.type_name) // an edited product's own type stays selectable
  return list
})

// "Other…" switches the field to free text for a brand-new category
const customType = ref(false)
const categorySelect = computed({
  get: () => (customType.value ? OTHER_TYPE : form.type_name),
  set: (value) => {
    if (value === OTHER_TYPE) {
      customType.value = true
      form.type_name = ''
    } else {
      customType.value = false
      form.type_name = value
    }
  },
})

async function fetchMeta() {
  try {
    const res = await productsApi.getMeta()
    if (res.status && res.data) {
      meta.value = res.data
    }
  } catch (err) {
    console.error('Failed to load product meta', err)
  }
}

onMounted(() => {
  fetchMeta()
})

watch(
  () => props.product,
  (newVal) => {
    if (newVal) {
      form.product_no = newVal.product_no || ''
      form.product = newVal.product || ''
      form.product_description = newVal.product_description || ''
      form.measurement = newVal.measurement || ''
      form.product_reorder_point = newVal.product_reorder_point ?? 10
      form.expiry_warning_days = newVal.expiry_warning_days ?? 30
      form.expiry_danger_days = newVal.expiry_danger_days ?? 7
      form.type_name = newVal.type_name || ''
      form.unit_name = newVal.unit_name || ''
      form.entity_name = newVal.entity_name || ''
    } else {
      form.product_no = ''
      form.product = ''
      form.product_description = ''
      form.measurement = ''
      form.product_reorder_point = 10
      form.expiry_warning_days = 30
      form.expiry_danger_days = 7
      form.type_name = meta.value.types[0]?.type || 'General Inventory'
      form.unit_name = meta.value.units[0]?.unit || 'pcs'
      form.entity_name = meta.value.entities[0]?.entity || 'BSU Food Processing Center'
    }
    customType.value = false
    errorMessage.value = ''
    askProductAction.value = false
  },
  { immediate: true }
)

function identityChanged() {
  const original = props.product
  if (!original?.product_id) return false
  return (
    String(form.product).trim() !== String(original.product || '').trim() ||
    String(form.product_description).trim() !== String(original.product_description || '').trim()
  )
}

async function handleSubmit() {
  if (loading.value) return
  errorMessage.value = ''
  if (!form.product_no || !form.product) {
    errorMessage.value = 'Product No and Product Name are required.'
    return
  }

  if (Number(form.expiry_danger_days) >= Number(form.expiry_warning_days)) {
    errorMessage.value = 'Danger days must be less than warning days.'
    return
  }

  if (identityChanged()) {
    askProductAction.value = true
    return
  }

  await save('existing')
}

async function save(productAction) {
  askProductAction.value = false
  loading.value = true
  try {
    if (props.product?.product_id) {
      await productsApi.updateProduct(props.product.product_id, { ...form, product_action: productAction })
    } else {
      await productsApi.createProduct(form)
    }
    triggerAutoReload('save-product')
    emit('saved')
    emit('close')
  } catch (err) {
    errorMessage.value =
      err.response?.data?.message || err.response?.data?.errors?.product_no || err.message || 'Error saving product.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal-card product-modal-card">
      <div class="modal-header">
        <div>
          <h2 style="font-size: 1.25rem">{{ product ? 'Edit Product' : 'Add New Product' }}</h2>
          <p class="panel-subtitle">Configure inventory catalog details, stock thresholds, and units.</p>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" @click="$emit('close')">
          <X :size="16" />
        </button>
      </div>

      <div class="modal-body product-modal-body">
        <div v-if="errorMessage" class="badge badge-danger" style="display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem; padding: 0.65rem 1rem; width: 100%;">
          <AlertTriangle :size="16" style="flex-shrink: 0;" />
          <span>{{ errorMessage }}</span>
        </div>

        <!-- Same product or brand-new product? -->
        <div v-if="askProductAction" class="panel" style="padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid var(--color-warning);">
          <h3 style="font-size: 1.05rem; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 6px;">
            <Zap :size="16" style="color: var(--color-warning);" />
            <span>Name or Description Changed</span>
          </h3>
          <p style="color: var(--text-muted); margin-bottom: 1rem; line-height: 1.5;">
            Is this the <strong>same product</strong> (keep all existing transactions), or a
            <strong>brand-new product</strong> under the same product no.? A new product clears all
            previous stock and transactions of this item.
          </p>
          <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;" :disabled="loading" @click="save('existing')">
              <Package :size="14" />
              <span>Same Product</span>
            </button>
            <button type="button" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;" :disabled="loading" @click="save('new')">
              <PlusCircle :size="14" />
              <span>New Product</span>
            </button>
            <button type="button" class="btn btn-sm btn-secondary" @click="askProductAction = false">Cancel</button>
          </div>
        </div>

        <form @submit.prevent="handleSubmit" id="productForm" class="product-form">
          <!-- Row 1: Product No & Name -->
          <div class="form-grid-row grid-cols-code-name">
            <div class="form-group">
              <label class="form-label">Product No *</label>
              <input
                v-model.number="form.product_no"
                type="number"
                class="form-input"
                placeholder="e.g. 101"
                required
              />
            </div>
            <div class="form-group">
              <label class="form-label">Product Name *</label>
              <input
                v-model="form.product"
                type="text"
                class="form-input"
                placeholder="e.g. Strawberry Jam (300g)"
                required
              />
            </div>
          </div>

          <!-- Row 2: Description -->
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea
              v-model="form.product_description"
              class="form-textarea"
              rows="2"
              placeholder="Packaging details, specs, or product description..."
            ></textarea>
          </div>

          <!-- Row 3: Category, Unit, Measurement -->
          <div class="form-grid-row grid-cols-3">
            <div class="form-group">
              <label class="form-label">Category / Type</label>
              <div v-if="customType" class="category-custom">
                <input
                  v-model="form.type_name"
                  class="form-input"
                  placeholder="New category name"
                  autofocus
                />
                <button type="button" class="category-back" @click="categorySelect = categoryOptions[0]">Choose from list</button>
              </div>
              <select v-else v-model="categorySelect" class="form-select">
                <option v-for="t in categoryOptions" :key="t" :value="t">{{ t }}</option>
                <option :value="OTHER_TYPE">Other (new category)…</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Unit of Measure</label>
              <input
                v-model="form.unit_name"
                list="unitOptions"
                class="form-input"
                placeholder="e.g. pcs, jars"
              />
              <datalist id="unitOptions">
                <option v-for="u in meta.units" :key="u.unit_id" :value="u.unit" />
              </datalist>
            </div>

            <div class="form-group">
              <label class="form-label">Measurement / Spec</label>
              <input
                v-model="form.measurement"
                type="text"
                class="form-input"
                placeholder="e.g. 300g, 500ml"
              />
            </div>
          </div>

          <!-- Row 4: Entity / Division -->
          <div class="form-group">
            <label class="form-label">Entity / Division</label>
            <input
              v-model="form.entity_name"
              list="entityOptions"
              class="form-input"
              placeholder="e.g. BSU Food Processing Center"
            />
            <datalist id="entityOptions">
              <option v-for="e in meta.entities" :key="e.entity_id" :value="e.entity" />
            </datalist>
          </div>

          <!-- Row 5: Thresholds (Reorder, Warning, Danger) -->
          <div class="form-grid-row grid-cols-3">
            <div class="form-group">
              <label class="form-label">Reorder Point</label>
              <input
                v-model.number="form.product_reorder_point"
                type="number"
                class="form-input"
                min="0"
              />
              <span class="field-hint">Low-stock trigger qty</span>
            </div>

            <div class="form-group">
              <label class="form-label">Expiry Warning (Days)</label>
              <input
                v-model.number="form.expiry_warning_days"
                type="number"
                class="form-input"
                min="1"
              />
              <span class="field-hint">Days prior to alert</span>
            </div>

            <div class="form-group">
              <label class="form-label">Expiry Danger (Days)</label>
              <input
                v-model.number="form.expiry_danger_days"
                type="number"
                class="form-input"
                min="1"
              />
              <span class="field-hint">Critical urgent alert</span>
            </div>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" @click="$emit('close')" :disabled="loading">Cancel</button>
        <button type="submit" form="productForm" class="btn btn-primary" :disabled="loading">
          {{ loading ? 'Saving...' : (product ? 'Update Product' : 'Create Product') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.category-custom {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.category-back {
  align-self: flex-start;
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-primary);
  font: inherit;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
}

.category-back:hover {
  text-decoration: underline;
}

.product-modal-card {
  width: min(720px, calc(100vw - 32px)) !important;
  max-width: 720px !important;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-sizing: border-box;
}

.product-modal-body {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 1.5rem;
  box-sizing: border-box;
}

.product-form {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  width: 100%;
}

.form-grid-row {
  display: grid;
  gap: 1rem;
  width: 100%;
  box-sizing: border-box;
}

.grid-cols-code-name {
  grid-template-columns: minmax(110px, 140px) minmax(0, 1fr);
}

.grid-cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  min-width: 0;
  margin-bottom: 1rem;
}

.form-label {
  font-size: 0.825rem;
  font-weight: 600;
  color: var(--text-muted);
  white-space: nowrap;
}

.form-input,
.form-select,
.form-textarea {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

.field-hint {
  font-size: 0.72rem;
  color: var(--text-subtle);
  margin-top: 0.15rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 640px) {
  .grid-cols-code-name,
  .grid-cols-3 {
    grid-template-columns: 1fr;
    gap: 0.65rem;
  }
}
</style>
