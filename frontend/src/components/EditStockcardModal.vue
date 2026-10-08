<script setup>
import { ref, computed, watch } from 'vue'
import { ArrowDownToLine, ArrowUpFromLine, Info, Trash2, AlertTriangle, AlertCircle, Check, X } from 'lucide-vue-next'
import { stockApi } from '../api/stock'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  transaction: {
    type: Object,
    default: null,
  },
  productName: {
    type: String,
    default: '',
  },
  currentStock: {
    type: Number,
    default: 0,
  },
})

const emit = defineEmits(['close', 'saved', 'deleted'])

// Form state
const quantity = ref(1)
const typeId = ref(1) // 1 = receipt, 2 = issue
const originalTypeId = ref(null) // null for borrow / return / adjust_out: their type can't be switched here
const unitCost = ref(0)
const originalUnitCost = ref(0)
const reference = ref('')
const office = ref('')

const isSubmitting = ref(false)
const isDeleting = ref(false)
const showDeleteConfirm = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// Initialize form whenever a transaction is opened
watch(
  () => props.transaction,
  (txn) => {
    errorMessage.value = ''
    successMessage.value = ''
    showDeleteConfirm.value = false

    if (txn) {
      const isReceipt = Number(txn.receipt_qty) > 0 || txn.transaction_type === 'receipt'
      quantity.value = isReceipt ? Number(txn.receipt_qty) : Number(txn.issue_qty) || 1
      originalTypeId.value = txn.transaction_type === 'receipt' ? 1 : txn.transaction_type === 'issue' ? 2 : null
      typeId.value = originalTypeId.value ?? (isReceipt ? 1 : 2)
      originalUnitCost.value = Number(txn.transaction_unit_cost) || 0
      unitCost.value = originalUnitCost.value
      reference.value = txn.reference && txn.reference !== 'N/A' ? txn.reference : ''
      office.value = txn.office && txn.office !== 'Direct' ? txn.office : ''
    }
  },
  { immediate: true }
)

// Computed original quantity
const originalQty = computed(() => {
  if (!props.transaction) return 0
  const isReceipt = Number(props.transaction.receipt_qty) > 0 || props.transaction.transaction_type === 'receipt'
  return isReceipt ? Number(props.transaction.receipt_qty) : Number(props.transaction.issue_qty) || 0
})

const qtyDifference = computed(() => {
  return Number(quantity.value) - originalQty.value
})

async function handleSave() {
  if (!props.transaction) return
  errorMessage.value = ''
  successMessage.value = ''

  if (Number(quantity.value) <= 0) {
    errorMessage.value = 'Quantity must be greater than 0.'
    return
  }
  if (Number(unitCost.value) < 0) {
    errorMessage.value = 'Unit cost cannot be negative.'
    return
  }

  isSubmitting.value = true

  const payload = {
    transaction_id: props.transaction.transaction_id,
    new_qty: Number(quantity.value),
    new_reference: reference.value.trim(),
    new_office: office.value.trim(),
  }

  // Only switch the type when the user changed it (receipt ↔ issue)
  if (originalTypeId.value !== null && Number(typeId.value) !== originalTypeId.value) {
    payload.new_type = Number(typeId.value)
  }

  try {
    const res = await stockApi.editTransaction(payload)
    if ((res.status || res.ok) && Math.abs(Number(unitCost.value) - originalUnitCost.value) >= 0.005) {
      await stockApi.editReportCost(props.transaction.transaction_id, Number(unitCost.value))
    }
    if (res.status || res.ok) {
      successMessage.value = 'Transaction updated successfully.'
      setTimeout(() => {
        emit('saved', res.data || res)
        emit('close')
      }, 700)
    } else {
      errorMessage.value = res.message || res.error || 'Failed to update transaction.'
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || err.response?.data?.error || err.message || 'Error updating transaction.'
  } finally {
    isSubmitting.value = false
  }
}

async function handleDelete() {
  if (!props.transaction) return
  errorMessage.value = ''
  isDeleting.value = true

  try {
    const res = await stockApi.deleteTransaction({
      transaction_id: props.transaction.transaction_id,
    })
    if (res.status || res.ok) {
      successMessage.value = 'Transaction deleted and inventory restored.'
      setTimeout(() => {
        emit('deleted', res.data || res)
        emit('close')
      }, 700)
    } else {
      errorMessage.value = res.message || res.error || 'Failed to delete transaction.'
    }
  } catch (err) {
    errorMessage.value = err.response?.data?.message || err.response?.data?.error || err.message || 'Error deleting transaction.'
  } finally {
    isDeleting.value = false
  }
}
</script>

<template>
  <div v-if="isOpen && transaction" class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal-card edit-stockcard-modal" @click.stop>
      <!-- Modal Header -->
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Edit Stockcard Entry</h3>
          <p class="modal-subtitle">
            Correction for Transaction #{{ transaction.transaction_id }} ({{ transaction.date ? transaction.date.slice(0, 10) : 'Date N/A' }})
          </p>
        </div>
        <button type="button" class="btn btn-icon btn-secondary" @click="$emit('close')">
          <X :size="16" />
        </button>
      </div>

      <!-- Modal Body -->
      <div class="modal-body">
        <!-- Error & Success Banners -->
        <div v-if="errorMessage" class="login-error-banner" style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
          <AlertCircle :size="16" style="flex-shrink: 0;" />
          <span>{{ errorMessage }}</span>
        </div>
        <div v-if="successMessage" class="login-success-banner" style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
          <Check :size="16" style="flex-shrink: 0;" />
          <span>{{ successMessage }}</span>
        </div>

        <!-- Product Summary Bar -->
        <div class="edit-item-summary">
          <div>
            <span class="summary-label">Target Product</span>
            <strong class="summary-item-name">{{ productName || transaction.item_name || 'Selected Item' }}</strong>
          </div>
          <div style="text-align: right;">
            <span class="summary-label">Current Ledger Balance</span>
            <strong class="summary-balance">{{ transaction.balance }} units</strong>
          </div>
        </div>

        <form @submit.prevent="handleSave" class="edit-form-grid">
          <!-- Transaction Type (receipt / issue entries only) -->
          <div v-if="originalTypeId !== null" class="form-group">
            <label class="form-label">Transaction Direction / Action Type</label>
            <div class="type-radio-toggle">
              <label class="type-radio-card" :class="{ 'is-active': typeId === 1 }">
                <input v-model.number="typeId" type="radio" :value="1" />
                <span class="type-icon"><ArrowDownToLine :size="18" /></span>
                <div>
                  <strong>Receipt (Stock In)</strong>
                  <small>Increases inventory balance</small>
                </div>
              </label>

              <label class="type-radio-card" :class="{ 'is-active': typeId === 2 }">
                <input v-model.number="typeId" type="radio" :value="2" />
                <span class="type-icon"><ArrowUpFromLine :size="18" /></span>
                <div>
                  <strong>Issue (Stock Out)</strong>
                  <small>Deducts from inventory balance</small>
                </div>
              </label>
            </div>
          </div>

          <!-- Quantity Field -->
          <div class="form-group">
            <label class="form-label" for="edit_qty">
              Corrected Quantity <span style="color: var(--color-danger)">*</span>
            </label>
            <div style="display: flex; align-items: center; gap: 10px;">
              <input
                id="edit_qty"
                v-model.number="quantity"
                type="number"
                step="any"
                min="0.01"
                class="form-input"
                required
                style="font-size: 1.1rem; font-weight: 700;"
              />
              <span v-if="qtyDifference !== 0" class="qty-diff-pill" :class="qtyDifference > 0 ? 'diff-plus' : 'diff-minus'">
                {{ qtyDifference > 0 ? '+' : '' }}{{ qtyDifference }} diff
              </span>
            </div>
            <small style="color: var(--text-subtle); margin-top: 4px;">
              Original recorded quantity: <strong>{{ originalQty }}</strong>
            </small>
          </div>

          <!-- Unit Cost / Price -->
          <div class="form-group">
            <label class="form-label" for="edit_cost">Unit Cost / Price (₱)</label>
            <input
              id="edit_cost"
              v-model.number="unitCost"
              type="number"
              step="any"
              min="0"
              class="form-input"
            />
          </div>

          <!-- Reference / Document Number -->
          <div class="form-group">
            <label class="form-label" for="edit_ref">Reference / PO / DR / Invoice No.</label>
            <input
              id="edit_ref"
              v-model="reference"
              type="text"
              class="form-input"
              placeholder="e.g. PO-2026-0819, DR #4012"
            />
          </div>

          <!-- Office / Department / Source -->
          <div class="form-group">
            <label class="form-label" for="edit_office">Office / Requisitioner / Destination</label>
            <input
              id="edit_office"
              v-model="office"
              type="text"
              class="form-input"
              placeholder="e.g. Food Processing Center, Department of Agriculture"
            />
          </div>

          <!-- Audit Warning Note -->
          <div class="edit-audit-notice">
            <Info :size="16" style="flex-shrink: 0; color: var(--color-primary);" />
            <p>
              Saving changes will automatically update the linked batch inventory and recompute the running ledger balance in real time.
            </p>
          </div>

          <!-- Danger Zone: Deletion Prompt -->
          <div class="edit-danger-zone">
            <div v-if="!showDeleteConfirm" style="display: flex; justify-content: space-between; align-items: center;">
              <div>
                <strong style="color: var(--color-danger); font-size: 13.5px;">Delete this transaction?</strong>
                <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                  Reverses this entry and restores the batch quantity.
                </p>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-secondary"
                style="color: var(--color-danger); border-color: rgba(239, 68, 68, 0.3); display: inline-flex; align-items: center; gap: 5px;"
                @click="showDeleteConfirm = true"
              >
                <Trash2 :size="14" />
                <span>Delete Entry</span>
              </button>
            </div>

            <div v-else class="delete-confirmation-box">
              <p style="color: var(--color-danger); font-weight: 700; font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                <AlertTriangle :size="16" style="flex-shrink: 0;" />
                <span>Are you sure you want to permanently delete Transaction #{{ transaction.transaction_id }}?</span>
              </p>
              <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button
                  type="button"
                  class="btn btn-sm btn-secondary"
                  @click="showDeleteConfirm = false"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-primary"
                  style="background: #dc2626;"
                  :disabled="isDeleting"
                  @click="handleDelete"
                >
                  <span v-if="isDeleting">Deleting...</span>
                  <span v-else>Yes, Delete Entry</span>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <!-- Modal Footer -->
      <div class="modal-footer">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="isSubmitting || isDeleting"
          @click="$emit('close')"
        >
          Cancel
        </button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="isSubmitting || isDeleting"
          @click="handleSave"
        >
          <span v-if="isSubmitting">Saving Changes...</span>
          <span v-else>Save Changes</span>
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edit-stockcard-modal {
  max-width: 580px;
}

.modal-title {
  font-size: 1.25rem;
  font-weight: 800;
  margin: 0;
}

.modal-subtitle {
  font-size: 12.5px;
  color: var(--text-muted);
  margin: 2px 0 0;
}

.edit-item-summary {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  border-radius: 14px;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  margin-bottom: 20px;
}

.summary-label {
  font-size: 11px;
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  display: block;
}

.summary-item-name {
  font-size: 14px;
  font-weight: 800;
  color: var(--text-main);
}

.summary-balance {
  font-size: 15px;
  font-weight: 800;
  font-family: var(--font-mono);
  color: var(--color-primary);
}

.type-radio-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.type-radio-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 12px;
  border: 1.5px solid var(--border-subtle);
  background: var(--bg-subtle);
  cursor: pointer;
  transition: all var(--transition-fast);
}

.type-radio-card input {
  display: none;
}

.type-radio-card:hover {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.type-radio-card.is-active {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
  box-shadow: 0 0 0 3px var(--color-primary-glow);
}

.type-icon {
  font-size: 1.3rem;
}

.type-radio-card strong {
  display: block;
  font-size: 13px;
  color: var(--text-main);
}

.type-radio-card small {
  display: block;
  font-size: 11px;
  color: var(--text-muted);
}

.qty-diff-pill {
  padding: 4px 10px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
}

.diff-plus {
  background: rgba(21, 128, 61, 0.12);
  color: #15803d;
}

.diff-minus {
  background: rgba(220, 38, 38, 0.12);
  color: #dc2626;
}

.edit-audit-notice {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 12px;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  font-size: 12px;
  color: var(--text-muted);
  line-height: 1.45;
}

.edit-danger-zone {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed var(--border-subtle);
}

.delete-confirmation-box {
  background: rgba(239, 68, 68, 0.08);
  border: 1px solid rgba(239, 68, 68, 0.25);
  border-radius: 12px;
  padding: 12px 16px;
}
</style>
