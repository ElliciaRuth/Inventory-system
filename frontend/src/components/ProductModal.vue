<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { productsApi } from '../api/products'
import { triggerAutoReload } from '../composables/useAutoReload'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  product: { type: Object, default: null }, // If null, creating new; if object, editing
})

const emit = defineEmits(['close', 'saved'])

const loading = ref(false)
const errorMessage = ref('')
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
    errorMessage.value = ''
  },
  { immediate: true }
)

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

  loading.value = true
  try {
    if (props.product?.product_id) {
      await productsApi.updateProduct(props.product.product_id, form)
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
        <button type="button" class="btn btn-sm btn-secondary" @click="$emit('close')">✕</button>
      </div>

      <div class="modal-body product-modal-body">
        <div v-if="errorMessage" class="badge badge-danger" style="display: flex; margin-bottom: 1.25rem; padding: 0.65rem 1rem; width: 100%;">
          <span>⚠️ {{ errorMessage }}</span>
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
              <input
                v-model="form.type_name"
                list="typeOptions"
                class="form-input"
                placeholder="Select or enter"
              />
              <datalist id="typeOptions">
                <option v-for="t in meta.types" :key="t.type_id" :value="t.type" />
              </datalist>
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
