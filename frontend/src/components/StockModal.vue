<script setup>
import { ref, reactive, watch, onMounted, computed } from 'vue'
import { X, AlertTriangle, Layers, ScanLine } from 'lucide-vue-next'
import { stockApi } from '../api/stock'
import { triggerAutoReload } from '../composables/useAutoReload'
import ProductSearch from './ProductSearch.vue'

const props = defineProps({
  isOpen: { type: Boolean, default: false },
  selectedProductId: { type: Number, default: 0 },
  // Opens straight on a return: { product_id, borrow_id, quantity }
  presetReturn: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const TYPE_LABELS = {
  receipt: 'Stock In (Receipt)',
  issue: 'Stock Out (Issue)',
  adjust_out: 'Adjust Out (Spoiled, Spilled, Expired…)',
  adjust_in: 'Adjust In (Count Correction)',
  borrow: 'Lend to Another Unit (Borrow)',
  return: 'Return of Borrowed Stock',
}

const OTHER_UNIT = '__other__'

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
  borrowerUnits: [],
})

const form = reactive({
  product_id: 0,
  transaction_type_id: 0,
  copy_id: '',
  quantity: 1,
  unit_cost: '',
  usage_pct: 100,
  office: '',
  reference: '',
  manufacturing_date: '',
  expiration_date: '',
  adjustment_reason_id: '',
  // Borrow (lend to another unit)
  borrower_name: '',
  borrower_user_office_id: '',
  borrower_unit: '',
  due_date: '',
  borrow_notes: '',
  // Return: which borrow it settles
  borrow_id: '',
})

// Local today (not UTC), so the picker allows today's date; never after the expiry date
const todayIso = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const maxManufacturingDate = computed(() =>
  form.expiration_date && form.expiration_date < todayIso() ? form.expiration_date : todayIso()
)

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
const isAdjustIn = computed(() => typeName.value === 'adjust_in')
const isBorrow = computed(() => typeName.value === 'borrow')
const isReturn = computed(() => typeName.value === 'return')
const needsReason = computed(() => isAdjustOut.value || isAdjustIn.value)

// ── Returns settle a borrow record ──
const openBorrows = ref([])
const selectedBorrow = computed(() => openBorrows.value.find((b) => String(b.borrow_id) === String(form.borrow_id)) || null)

async function loadOpenBorrows() {
  openBorrows.value = []
  form.borrow_id = ''
  if (!props.isOpen || !isReturn.value || !form.product_id) return
  try {
    const res = await stockApi.getBorrows({ product_id: form.product_id, status: 'open' })
    openBorrows.value = res.data?.borrows || []
    const preset = props.presetReturn
    const wanted = preset && Number(preset.product_id) === Number(form.product_id)
      ? openBorrows.value.find((b) => Number(b.borrow_id) === Number(preset.borrow_id))
      : null
    if (wanted) {
      form.borrow_id = String(wanted.borrow_id)
      form.quantity = Number(wanted.outstanding_qty)
    } else if (openBorrows.value.length === 1) {
      form.borrow_id = String(openBorrows.value[0].borrow_id)
    }
  } catch {
    openBorrows.value = []
  }
}

// Issue, borrow, adjust in and return work on a specific sub-product (price variant);
// a return linked to a borrow goes back to the borrow's own sub-product
const needsCopy = computed(() =>
  ['issue', 'borrow', 'adjust_in'].includes(typeName.value) || (isReturn.value && !selectedBorrow.value)
)

const copies = computed(() => options.value.copiesMap?.[form.product_id] || [])
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

function qtyText(value) {
  const n = Number(value || 0)
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '')
}

function shortDate(value) {
  if (!value) return ''
  const d = new Date(`${String(value).slice(0, 10)}T00:00:00`)
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function typeLabel(t) {
  return TYPE_LABELS[(t.transaction_type || '').toLowerCase()] || t.transaction_type
}

// ── Which batches a stock-out will use (oldest received unexpired first, FIFO) ──
const plan = ref(null)
const planLoading = ref(false)
const previewType = computed(() => (['issue', 'borrow', 'adjust_out'].includes(typeName.value) ? typeName.value : ''))
const outQty = computed(() => {
  const qty = Number(form.quantity) || 0
  return isIssue.value ? (qty * (Number(form.usage_pct) || 100)) / 100 : qty
})

let planTimer = null
let planRequest = 0
function schedulePlan() {
  clearTimeout(planTimer)
  planTimer = setTimeout(loadPlan, 250)
}

async function loadPlan() {
  const ticket = ++planRequest
  if (!props.isOpen || !previewType.value || !form.product_id || outQty.value <= 0 || (needsCopy.value && !form.copy_id)) {
    plan.value = null
    return
  }
  planLoading.value = true
  try {
    const res = await stockApi.getBatchPlan({
      product_id: form.product_id,
      copy_id: needsCopy.value ? form.copy_id : 0,
      quantity: outQty.value,
      type: previewType.value,
      reason_id: form.adjustment_reason_id || 0,
    })
    if (ticket === planRequest) plan.value = res.data || null
  } catch {
    if (ticket === planRequest) plan.value = null
  } finally {
    if (ticket === planRequest) planLoading.value = false
  }
}

const planBatches = computed(() => (plan.value?.batches || []).filter((b) => Number(b.take) > 0))

function batchTone(batch) {
  if (batch.expired) return 'is-expired'
  if (batch.days_left !== null && batch.days_left !== undefined && Number(batch.days_left) <= 7) return 'is-soon'
  return ''
}

watch(
  () => [form.product_id, form.copy_id, outQty.value, typeName.value, form.adjustment_reason_id, props.isOpen],
  schedulePlan
)

async function fetchOptions() {
  try {
    const res = await stockApi.getOptions()
    if (res.status && res.data) {
      options.value = { ...options.value, ...res.data }
      if (!form.transaction_type_id && options.value.transactionTypes.length) {
        form.transaction_type_id = Number(options.value.transactionTypes[0].transaction_type_id)
      }
      if (props.presetReturn) {
        const returnType = options.value.transactionTypes.find((t) => t.transaction_type === 'return')
        form.product_id = Number(props.presetReturn.product_id)
        if (returnType) form.transaction_type_id = Number(returnType.transaction_type_id)
      } else if (props.selectedProductId) {
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

watch(() => props.isOpen, (open) => open && loadOpenBorrows())

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
  if (!needsReason.value) form.adjustment_reason_id = ''
  loadOpenBorrows()
})

// A scanned batch barcode picks the product and, where one is needed, its sub-product
function onScanned(batch) {
  errorMessage.value = ''
  if (batch?.copy_id && needsCopy.value) {
    // Wait for the product watcher above to clear the old sub-product first
    setTimeout(() => {
      if (copies.value.some((c) => Number(c.copy_id) === Number(batch.copy_id))) form.copy_id = String(batch.copy_id)
    })
  }
}

function validate() {
  if (!form.product_id) return 'Please select a product.'
  if (!form.transaction_type_id) return 'Please select a transaction type.'
  if (!(Number(form.quantity) > 0)) return 'Quantity must be greater than 0.'
  if (isReceipt.value && !(Number(form.unit_cost) > 0)) return 'Unit cost is required for stock-in.'
  if (needsCopy.value && !form.copy_id) return 'Please select a sub-product (price variant).'
  if (isIssue.value && !(Number(form.usage_pct) >= 1 && Number(form.usage_pct) <= 100)) {
    return 'Usage % must be between 1 and 100.'
  }
  if (needsReason.value && !form.adjustment_reason_id) return 'Choose the reason for this adjustment.'
  if (isBorrow.value) {
    if (form.borrower_name.trim().length < 2) return 'Enter the name of the person borrowing.'
    if (!form.borrower_user_office_id) return 'Choose the unit that is borrowing.'
    if (form.borrower_user_office_id === OTHER_UNIT && !form.borrower_unit.trim()) return 'Type the name of the borrowing unit.'
  }
  if (isReturn.value) {
    if (openBorrows.value.length && !form.borrow_id) return 'Choose which borrow this return is for.'
    if (selectedBorrow.value && Number(form.quantity) > Number(selectedBorrow.value.outstanding_qty) + 0.0001) {
      return `Only ${qtyText(selectedBorrow.value.outstanding_qty)} is still to be returned for this borrow.`
    }
  }
  if (plan.value && Number(plan.value.shortfall) > 0) {
    if (isAdjustOut.value && plan.value.mode === 'expired') return `Only ${qtyText(plan.value.usable)} of this stock is expired.`
    const expired = Number(plan.value.expired_qty)
    return `Only ${qtyText(plan.value.usable)} can be taken out${expired > 0 && !isAdjustOut.value ? `; ${qtyText(expired)} more is expired and must be removed with Adjust Out, reason "Expired"` : ''}.`
  }
  if (isReceipt.value && form.manufacturing_date) {
    if (form.manufacturing_date > todayIso()) return 'Manufacturing date cannot be in the future.'
    if (form.expiration_date && form.manufacturing_date > form.expiration_date) {
      return 'Manufacturing date must be on or before the expiration date.'
    }
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
    office: form.office.trim(),
    reference: form.reference.trim(),
  }
  if (isReceipt.value) {
    payload.unit_cost = Number(form.unit_cost)
    payload.expiration_date = form.expiration_date || ''
    payload.manufacturing_date = form.manufacturing_date || ''
  }
  if (needsCopy.value) payload.copy_id = Number(form.copy_id)
  if (isIssue.value) payload.usage_pct = Number(form.usage_pct)
  if (needsReason.value) payload.adjustment_reason_id = Number(form.adjustment_reason_id)
  if (isBorrow.value) {
    payload.borrower_name = form.borrower_name.trim()
    if (form.borrower_user_office_id === OTHER_UNIT) payload.borrower_unit = form.borrower_unit.trim()
    else payload.borrower_user_office_id = Number(form.borrower_user_office_id)
    payload.due_date = form.due_date || ''
    payload.borrow_notes = form.borrow_notes.trim()
  }
  if (isReturn.value && form.borrow_id) payload.borrow_id = Number(form.borrow_id)

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
    form.manufacturing_date = ''
    form.copy_id = ''
    form.borrower_name = ''
    form.borrower_user_office_id = ''
    form.borrower_unit = ''
    form.due_date = ''
    form.borrow_notes = ''
    form.borrow_id = ''
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
          <p class="panel-subtitle">Record deliveries, issues, lending, returns and adjustments.</p>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" @click="$emit('close')">
          <X :size="16" />
        </button>
      </div>

      <div class="modal-body">
        <div v-if="errorMessage" class="badge badge-danger" style="display: flex; align-items: center; gap: 8px; margin-bottom: 1.25rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;">
          <AlertTriangle :size="16" style="flex-shrink: 0;" />
          <span>{{ errorMessage }}</span>
        </div>

        <form @submit.prevent="handleSubmit" id="stockForm">
          <div class="form-group">
            <label class="form-label">
              Product *
              <small class="sm-scan-hint"><ScanLine :size="12" /> type, pick, or scan a batch barcode</small>
            </label>
            <ProductSearch
              v-model="form.product_id"
              :items="options.items"
              :scan-barcodes="true"
              @scanned="onScanned"
              @scan-error="errorMessage = $event"
            />
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

          <!-- Return: which borrow it settles -->
          <div v-if="isReturn" class="form-group">
            <label class="form-label">Returned From (Borrow) {{ openBorrows.length ? '*' : '' }}</label>
            <select v-if="openBorrows.length" v-model="form.borrow_id" class="form-select">
              <option value="">— Choose the borrow being returned —</option>
              <option v-for="b in openBorrows" :key="b.borrow_id" :value="String(b.borrow_id)">
                {{ b.borrower_name }} ({{ b.borrower_unit }}) — {{ qtyText(b.outstanding_qty) }} of {{ qtyText(b.quantity) }} {{ b.unit_name }} still out, lent {{ shortDate(b.borrowed_at) }}
              </option>
            </select>
            <small v-else style="color: var(--text-muted);">
              No open borrow is recorded for this product; this return will be recorded on its own.
            </small>
          </div>

          <!-- Sub-product (price variant) -->
          <div v-if="needsCopy" class="form-group">
            <label class="form-label">Sub-Product (Price Variant) *</label>
            <select v-model="form.copy_id" class="form-select" required>
              <option value="">— Select sub-product —</option>
              <option
                v-for="c in copies"
                :key="c.copy_id"
                :value="String(c.copy_id)"
                :disabled="!['return', 'adjust_in'].includes(typeName) && Number(c.current_stock) <= 0"
              >
                ₱{{ peso(c.unit_cost) }}{{ c.label ? ` (${c.label})` : '' }} — Stock: {{ Number(c.current_stock) }}{{ !['return', 'adjust_in'].includes(typeName) && Number(c.current_stock) <= 0 ? ' (Out of stock)' : '' }}
              </option>
            </select>
            <small v-if="!copies.length" style="color: var(--text-muted);">
              This product has no stock-in yet, so there are no sub-products to choose from.
            </small>
          </div>

          <!-- Borrow: who is borrowing -->
          <div v-if="isBorrow" class="sm-borrow">
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Borrower (Person) *</label>
                <input v-model="form.borrower_name" type="text" class="form-input" maxlength="150" placeholder="e.g. Juan Dela Cruz" />
              </div>
              <div class="form-group">
                <label class="form-label">Borrowing Unit *</label>
                <select v-model="form.borrower_user_office_id" class="form-select">
                  <option value="">— Choose unit —</option>
                  <option v-for="u in options.borrowerUnits" :key="u.user_office_id" :value="String(u.user_office_id)">{{ u.user_office_name }}</option>
                  <option :value="OTHER_UNIT">Other unit…</option>
                </select>
                <input
                  v-if="form.borrower_user_office_id === OTHER_UNIT"
                  v-model="form.borrower_unit"
                  type="text"
                  class="form-input"
                  style="margin-top: 0.4rem;"
                  maxlength="150"
                  placeholder="Name of the unit"
                />
              </div>
            </div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Return By (Optional)</label>
                <input v-model="form.due_date" type="date" class="form-input" :min="todayIso()" />
              </div>
              <div class="form-group">
                <label class="form-label">Notes (Optional)</label>
                <input v-model="form.borrow_notes" type="text" class="form-input" maxlength="500" placeholder="e.g. for the Saturday baking class" />
              </div>
            </div>
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

            <div class="form-group" v-if="needsReason">
              <label class="form-label">Reason *</label>
              <select v-model="form.adjustment_reason_id" class="form-select">
                <option value="">— Choose a reason —</option>
                <option v-for="r in options.adjustmentReasons" :key="r.adjustment_reason_id" :value="String(r.adjustment_reason_id)">
                  {{ r.adjustment_reason }}
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Transaction Date</label>
              <!-- For now the server stamps each movement with the moment it is saved -->
              <input type="text" class="form-input" value="Now (recorded when you save)" disabled />
            </div>
          </div>

          <!-- Batches the stock-out will use -->
          <div v-if="previewType && (plan || planLoading)" class="sm-plan" :class="{ 'is-short': plan && Number(plan.shortfall) > 0 }">
            <div class="sm-plan-head">
              <Layers :size="15" />
              <strong>{{ isAdjustOut ? 'Will be taken from' : 'Will be issued from' }}</strong>
              <span class="sm-plan-rule">
                {{ plan?.mode === 'expired' ? 'expired batches only' : plan?.mode === 'adjust' ? 'expired batches first, then oldest received' : 'oldest received batch first (FIFO)' }}
              </span>
            </div>
            <p v-if="planLoading && !plan" class="sm-plan-empty">Checking batches…</p>
            <ul v-else-if="planBatches.length" class="sm-plan-list">
              <li v-for="b in planBatches" :key="b.batch_id" :class="batchTone(b)">
                <span class="sm-plan-batch">{{ b.batch_no }}</span>
                <span class="sm-plan-exp">
                  {{ b.expiration_date ? (b.expired ? 'expired ' : 'exp. ') + shortDate(b.expiration_date) : 'no expiry date' }}
                </span>
                <span class="sm-plan-take"><strong>{{ qtyText(b.take) }}</strong> of {{ qtyText(b.current_qty) }}</span>
              </li>
            </ul>
            <p v-else-if="plan" class="sm-plan-empty">No batch has stock for this.</p>
            <p v-if="plan && Number(plan.shortfall) > 0" class="sm-plan-warn">
              <AlertTriangle :size="13" />
              {{ qtyText(plan.shortfall) }} short — only {{ qtyText(plan.usable) }} available{{ plan.mode === 'issue' && Number(plan.expired_qty) > 0 ? `; ${qtyText(plan.expired_qty)} more is expired (remove it with Adjust Out, reason "Expired")` : '' }}.
            </p>
          </div>

          <div v-if="isReceipt" class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Manufacturing Date (Optional)</label>
              <input v-model="form.manufacturing_date" type="date" class="form-input" :max="maxManufacturingDate" />
            </div>
            <div class="form-group">
              <label class="form-label">Expiration Date (Optional)</label>
              <input v-model="form.expiration_date" type="date" class="form-input" :min="form.manufacturing_date || undefined" />
            </div>
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

<style scoped>
.sm-scan-hint {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-left: 0.4rem;
  font-weight: 500;
  color: var(--text-muted);
}

.sm-borrow {
  padding: 0.85rem 1rem 0.1rem;
  margin-bottom: 1rem;
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
}

.sm-plan {
  margin-bottom: 1.25rem;
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--border-subtle);
  border-left: 4px solid var(--color-primary);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  font-size: 0.83rem;
}

.sm-plan.is-short {
  border-left-color: var(--color-danger);
}

.sm-plan-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
  color: var(--color-primary);
}

.sm-plan-head strong {
  color: var(--text-main);
}

.sm-plan-rule {
  font-size: 0.75rem;
  color: var(--text-muted);
}

.sm-plan-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  display: grid;
  gap: 0.3rem;
}

.sm-plan-list li {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  padding: 0.35rem 0.6rem;
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
}

.sm-plan-list li.is-expired {
  border-color: var(--color-danger);
  background: var(--color-danger-bg);
}

.sm-plan-list li.is-soon {
  border-color: var(--color-warning);
}

.sm-plan-batch {
  font-family: var(--font-mono);
  font-size: 0.76rem;
  font-weight: 700;
}

.sm-plan-exp {
  color: var(--text-muted);
  font-size: 0.78rem;
}

.sm-plan-list li.is-expired .sm-plan-exp {
  color: var(--color-danger);
  font-weight: 700;
}

.sm-plan-take {
  margin-left: auto;
  white-space: nowrap;
}

.sm-plan-empty {
  margin: 0.4rem 0 0;
  color: var(--text-muted);
}

.sm-plan-warn {
  display: flex;
  align-items: flex-start;
  gap: 0.3rem;
  margin: 0.5rem 0 0;
  color: var(--color-danger);
  font-weight: 600;
}

.sm-plan-warn svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
}
</style>
