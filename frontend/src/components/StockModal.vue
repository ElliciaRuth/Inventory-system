<script setup>
import { ref, reactive, watch, onMounted, computed } from 'vue'
import { stockApi } from '../api/stock'
import { triggerAutoReload } from '../composables/useAutoReload'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  selectedProductId: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'saved'])

const loading = ref(false)
const errorMessage = ref('')
const options = ref({
  items: [],
  stockMap: {},
  offices: [],
  references: [],
  transactionTypes: [],
  adjustmentReasons: [],
})

const form = reactive({
  product_id: 0,
  transaction_type_id: 1, // 1: receipt, 2: issue
  quantity: 1,
  unit_cost: 0,
  office_id: 0,
  office_name: '',
  reference_id: 0,
  reference_name: '',
  expiration_date: '',
  date: new Date().toISOString().split('T')[0],
  adjustment_reason_id: 0,
})

const selectedItem = computed(() => {
  return options.value.items.find((i) => Number(i.product_id) === Number(form.product_id)) || null
})

const currentStock = computed(() => {
  if (!form.product_id) return 0
  return options.value.stockMap[form.product_id] || 0
})

const isStockIn = computed(() => {
  return Number(form.transaction_type_id) === 1 || Number(form.transaction_type_id) === 5
})

async function fetchOptions() {
  try {
    const res = await stockApi.getOptions()
    if (res.status && res.data) {
      options.value = res.data
      if (props.selectedProductId) {
        form.product_id = props.selectedProductId
      } else if (options.value.items.length > 0) {
        form.product_id = Number(options.value.items[0].product_id)
      }
    }
  } catch (err) {
    console.error('Failed to load stock options', err)
  }
}

onMounted(() => {
  fetchOptions()
})

watch(
  () => props.selectedProductId,
  (val) => {
    if (val) form.product_id = val
  }
)

async function handleSubmit() {
  if (loading.value) return
  errorMessage.value = ''
  if (!form.product_id || form.quantity <= 0) {
    errorMessage.value = 'Please select a valid item and quantity.'
    return
  }

  // If stock out, check if quantity exceeds available stock
  if (!isStockIn.value && form.quantity > currentStock.value) {
    errorMessage.value = `Cannot issue ${form.quantity} units. Available stock is only ${currentStock.value}.`
    return
  }

  loading.value = true
  try {
    await stockApi.addStock(form)
    triggerAutoReload('stock-movement')
    emit('saved')
    emit('close')
    // Reset transient fields to avoid accidental duplicates if reopened
    form.quantity = 1
    form.reference_id = 0
    form.reference_name = ''
  } catch (err) {
    errorMessage.value =
      err.response?.data?.message || err.message || 'Error processing stock transaction.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-if="isOpen" class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal-card" style="max-width: 620px;">
      <div class="modal-header">
        <div>
          <h2 style="font-size: 1.25rem">Stock Movement / Transaction</h2>
          <p class="panel-subtitle">Record stock-in deliveries, issues to departments, or adjustments.</p>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" @click="$emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <div v-if="errorMessage" class="badge badge-danger" style="display: flex; margin-bottom: 1.25rem; padding: 0.65rem 1rem; width: 100%;">
          <span>⚠️ {{ errorMessage }}</span>
        </div>

        <form @submit.prevent="handleSubmit" id="stockForm">
          <!-- Item Selection -->
          <div class="form-group">
            <label class="form-label">Select Product *</label>
            <select v-model.number="form.product_id" class="form-select" required>
              <option v-for="item in options.items" :key="item.product_id" :value="Number(item.product_id)">
                [{{ item.product_no }}] {{ item.product }} (Stock: {{ options.stockMap[item.product_id] || 0 }} {{ item.unit || '' }})
              </option>
            </select>
          </div>

          <!-- Stock Status Banner -->
          <div v-if="selectedItem" style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
              <strong style="color: var(--text-main);">Current On-Hand: </strong>
              <span class="badge" :class="currentStock > 0 ? 'badge-success' : 'badge-danger'" style="font-size: 0.85rem;">
                {{ currentStock }} {{ selectedItem.unit || 'units' }}
              </span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">
              {{ selectedItem.measurement || selectedItem.product_description || 'Standard' }}
            </div>
          </div>

          <!-- Transaction Type & Quantity -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
              <label class="form-label">Transaction Type *</label>
              <select v-model.number="form.transaction_type_id" class="form-select" required>
                <option v-for="t in options.transactionTypes" :key="t.transaction_type_id" :value="Number(t.transaction_type_id)">
                  {{ t.transaction_type === 'receipt' ? 'Stock In (Receipt)' : t.transaction_type === 'issue' ? 'Stock Out (Issue)' : t.transaction_type }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Quantity *</label>
              <input
                v-model.number="form.quantity"
                type="number"
                step="any"
                min="0.01"
                class="form-input"
                required
              />
            </div>
          </div>

          <!-- Unit Cost & Date -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group" v-if="isStockIn">
              <label class="form-label">Unit Cost (₱)</label>
              <input
                v-model.number="form.unit_cost"
                type="number"
                step="any"
                min="0"
                class="form-input"
                placeholder="0.00"
              />
            </div>

            <div class="form-group">
              <label class="form-label">Transaction Date</label>
              <input
                v-model="form.date"
                type="date"
                class="form-input"
                required
              />
            </div>
          </div>

          <!-- Expiration Date for Stock In -->
          <div class="form-group" v-if="isStockIn">
            <label class="form-label">Expiration Date (Optional)</label>
            <input
              v-model="form.expiration_date"
              type="date"
              class="form-input"
            />
          </div>

          <!-- Office / Destination -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
              <label class="form-label">Office / Destination</label>
              <input
                v-model="form.office_name"
                list="stockOfficeList"
                class="form-input"
                placeholder="e.g. FPC / Processing Unit"
              />
              <datalist id="stockOfficeList">
                <option v-for="o in options.offices" :key="o.user_office_id || o.office_id" :value="o.user_office_name || o.office" />
              </datalist>
            </div>

            <div class="form-group">
              <label class="form-label">Reference / PO #</label>
              <input
                v-model="form.reference_name"
                list="stockRefList"
                class="form-input"
                placeholder="e.g. RIS2026-06-024"
              />
              <datalist id="stockRefList">
                <option v-for="r in options.references" :key="r.reference_id" :value="r.reference" />
              </datalist>
            </div>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" @click="$emit('close')" :disabled="loading">Cancel</button>
        <button type="submit" form="stockForm" class="btn btn-primary" :disabled="loading">
          {{ loading ? 'Saving...' : 'Record Transaction' }}
        </button>
      </div>
    </div>
  </div>
</template>
