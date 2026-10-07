<script setup>
import { ref, reactive, watch, onMounted, computed } from 'vue'
import { stockApi } from '../api/stock'
import { triggerAutoReload } from '../composables/useAutoReload'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  selectedProductId: { type: Number, default: 0 },
})

const emit = defineEmits(['close', 'saved'])

const TYPE_LABELS = {
  receipt: 'Stock In (Receipt)',
  issue: 'Stock Out (Issue)',
  adjust_out: 'Spoiled / Adjust Out',
  borrow: 'Borrow',
  return: 'Return (Borrowed)',
}

const loading = ref(false)
const errorMessage = ref('')
const options = ref({
  items: [],
  stockMap: {},
  copiesMap: {},
  offices: [],
  references: [],
  transactionTypes: [],
  adjustmentReasons: [],
})

const today = () => new Date().toISOString().split('T')[0]

const form = reactive({
  product_id: 0,
  transaction_type_id: 0,
  copy_id: '',
  quantity: 1,
  unit_cost: '',
  usage_pct: 100,
  office: '',
  reference: '',
  expiration_date: '',
  date: today(),
  adjustment_reason_id: '',
})

const selectedItem = computed(() =>
  options.value.items.find((i) => Number(i.product_id) === Number(form.product_id)) || null
)

const currentStock = computed(() => Number(options.value.stockMap[form.product_id] || 0))

const typeName = computed(() => {
  const t = options.value.transactionTypes.find(
    (t) => Number(t.transaction_type_id) === Number(form.transaction_type_id)
  )
  return (t?.transaction_type || '').toLowerCase()
})

const isReceipt = computed(() => typeName.value === 'receipt')
const isIssue = computed(() => typeName.value === 'issue')
const isAdjustOut = computed(() => typeName.value === 'adjust_out')
// Issue, borrow and return work on a specific sub-product (price variant)
const needsCopy = computed(() => ['issue', 'borrow', 'return'].includes(typeName.value))

const copies = computed(() => options.value.copiesMap?.[form.product_id] || [])
const selectedCopy = computed(
  () => copies.value.find((c) => Number(c.copy_id) === Number(form.copy_id)) || null
)

// Issue and borrow take stock out of the selected sub-product
const availableForOut = computed(() =>
  selectedCopy.value ? Number(selectedCopy.value.current_stock) : currentStock.value
)

const effectiveQtyHint = computed(() => {
  if (!isIssue.value) return ''
  const pct = Number(form.usage_pct) || 100
  const qty = Number(form.quantity) || 0
  if (pct >= 100 || qty <= 0) return ''
  const eff = (qty * pct) / 100
  return `${Number.isInteger(eff) ? eff : eff.toFixed(2)} unit(s) will be consumed from stock`
})

function peso(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function typeLabel(t) {
  return TYPE_LABELS[(t.transaction_type || '').toLowerCase()] || t.transaction_type
}

async function fetchOptions() {
  try {
    const res = await stockApi.getOptions()
    if (res.status && res.data) {
      options.value = { ...options.value, ...res.data }
      if (!form.transaction_type_id && options.value.transactionTypes.length) {
        form.transaction_type_id = Number(options.value.transactionTypes[0].transaction_type_id)
      }
      if (props.selectedProductId) {
        form.product_id = props.selectedProductId
      } else if (!form.product_id && options.value.items.length > 0) {
        form.product_id = Number(options.value.items[0].product_id)
      }
    }
  } catch (err) {
    console.error('Failed to load stock options', err)
    errorMessage.value = err.response?.data?.message || 'Failed to load products and options.'
  }
}

onMounted(fetchOptions)

// Refresh stock figures every time the modal opens
watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      errorMessage.value = ''
      fetchOptions()
    }
  }
)

watch(
  () => props.selectedProductId,
  (val) => {
    if (val) form.product_id = val
  }
)

// A sub-product belongs to one product; reset it when product or type changes
watch(() => [form.product_id, form.transaction_type_id], () => {
  form.copy_id = ''
  if (!isIssue.value) form.usage_pct = 100
  if (!isAdjustOut.value) form.adjustment_reason_id = ''
})

function validate() {
  if (!form.product_id) return 'Please select a product.'
  if (!form.transaction_type_id) return 'Please select a transaction type.'
  if (!(Number(form.quantity) > 0)) return 'Quantity must be greater than 0.'
  if (isReceipt.value && !(Number(form.unit_cost) > 0)) return 'Unit cost is required for stock-in.'
  if (needsCopy.value && !form.copy_id) return 'Please select a sub-product (price variant).'
  if (['issue', 'borrow'].includes(typeName.value) && Number(form.quantity) > availableForOut.value) {
    return `Cannot take out ${form.quantity} — only ${availableForOut.value} available in this sub-product.`
  }
  if (isIssue.value && !(Number(form.usage_pct) >= 1 && Number(form.usage_pct) <= 100)) {
    return 'Usage % must be between 1 and 100.'
  }
  return ''
}

async function handleSubmit() {
  if (loading.value) return
  errorMessage.value = validate()
  if (errorMessage.value) return

  const payload = {
    product_id: Number(form.product_id),
    transaction_type_id: Number(form.transaction_type_id),
    quantity: Number(form.quantity),
    date: form.date,
    office: form.office.trim(),
    reference: form.reference.trim(),
  }
  if (isReceipt.value) {
    payload.unit_cost = Number(form.unit_cost)
    payload.expiration_date = form.expiration_date || ''
  }
  if (needsCopy.value) payload.copy_id = Number(form.copy_id)
  if (isIssue.value) payload.usage_pct = Number(form.usage_pct)
  if (isAdjustOut.value && form.adjustment_reason_id) {
    payload.adjustment_reason_id = Number(form.adjustment_reason_id)
  }

  loading.value = true
  try {
    await stockApi.addStock(payload)
    triggerAutoReload('stock-movement')
    emit('saved')
    emit('close')
    // Reset transient fields to avoid accidental duplicates if reopened
    form.quantity = 1
    form.unit_cost = ''
    form.reference = ''
    form.expiration_date = ''
    form.copy_id = ''
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
    <div class="modal-card" style="max-width: 640px;">
      <div class="modal-header">
        <div>
          <h2 style="font-size: 1.25rem">Stock Movement / Transaction</h2>
          <p class="panel-subtitle">Record stock-in deliveries, issues, borrows, returns, or spoilage.</p>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" @click="$emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <div v-if="errorMessage" class="badge badge-danger" style="display: flex; margin-bottom: 1.25rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;">
          <span>⚠️ {{ errorMessage }}</span>
        </div>

        <form @submit.prevent="handleSubmit" id="stockForm">
          <div class="form-group">
            <label class="form-label">Product *</label>
            <select v-model.number="form.product_id" class="form-select" required>
              <option v-for="item in options.items" :key="item.product_id" :value="Number(item.product_id)">
                [{{ item.product_no }}] {{ item.product }} (Stock: {{ options.stockMap[item.product_id] || 0 }} {{ item.unit || '' }})
              </option>
            </select>
          </div>

          <div v-if="selectedItem" style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
            <div>
              <strong style="color: var(--text-main);">Current On-Hand: </strong>
              <span class="badge" :class="currentStock > 0 ? 'badge-success' : 'badge-danger'" style="font-size: 0.85rem;">
                {{ currentStock }} {{ selectedItem.unit || 'units' }}
              </span>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">
              {{ selectedItem.measurement || selectedItem.product_description || '' }}
            </div>
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Transaction Type *</label>
              <select v-model.number="form.transaction_type_id" class="form-select" required>
                <option v-for="t in options.transactionTypes" :key="t.transaction_type_id" :value="Number(t.transaction_type_id)">
                  {{ typeLabel(t) }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Quantity *</label>
              <input v-model.number="form.quantity" type="number" step="any" min="0.01" class="form-input" required />
            </div>
          </div>

          <!-- Sub-product (price variant): issue / borrow / return -->
          <div v-if="needsCopy" class="form-group">
            <label class="form-label">Sub-Product (Price Variant) *</label>
            <select v-model="form.copy_id" class="form-select" required>
              <option value="">— Select sub-product —</option>
              <option
                v-for="c in copies"
                :key="c.copy_id"
                :value="String(c.copy_id)"
                :disabled="typeName !== 'return' && Number(c.current_stock) <= 0"
              >
                ₱{{ peso(c.unit_cost) }}{{ c.label ? ` (${c.label})` : '' }} — Stock: {{ Number(c.current_stock) }}{{ typeName !== 'return' && Number(c.current_stock) <= 0 ? ' (Out of stock)' : '' }}
              </option>
            </select>
            <small v-if="!copies.length" style="color: var(--text-muted);">
              This product has no stock-in yet, so there are no sub-products to choose from.
            </small>
          </div>

          <div class="form-grid-2">
            <div class="form-group" v-if="isReceipt">
              <label class="form-label">Unit Cost (₱) *</label>
              <input v-model.number="form.unit_cost" type="number" step="any" min="0.01" class="form-input" placeholder="0.00" required />
            </div>

            <div class="form-group" v-if="isIssue">
              <label class="form-label">Usage %</label>
              <input v-model.number="form.usage_pct" type="number" min="1" max="100" step="1" class="form-input" />
              <small v-if="effectiveQtyHint" style="color: var(--text-muted);">{{ effectiveQtyHint }}</small>
            </div>

            <div class="form-group" v-if="isAdjustOut">
              <label class="form-label">Adjustment Reason</label>
              <select v-model="form.adjustment_reason_id" class="form-select">
                <option value="">No reason</option>
                <option v-for="r in options.adjustmentReasons" :key="r.adjustment_reason_id" :value="String(r.adjustment_reason_id)">
                  {{ r.adjustment_reason }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Transaction Date *</label>
              <input v-model="form.date" type="date" class="form-input" required />
            </div>
          </div>

          <div class="form-group" v-if="isReceipt">
            <label class="form-label">Expiration Date (Optional)</label>
            <input v-model="form.expiration_date" type="date" class="form-input" />
          </div>

          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Office / Destination</label>
              <input v-model="form.office" list="stockOfficeList" class="form-input" placeholder="Select or type an office" />
              <datalist id="stockOfficeList">
                <option v-for="o in options.offices" :key="o.office_id" :value="o.office_name" />
              </datalist>
            </div>

            <div class="form-group">
              <label class="form-label">Reference / PO #</label>
              <input v-model="form.reference" list="stockRefList" class="form-input" placeholder="e.g. RIS2026-06-024" />
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
