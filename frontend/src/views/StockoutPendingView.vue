<script setup>
import { ref, onMounted } from 'vue'
import { stockoutApi } from '../api/stockout'
import { useAutoReload, triggerAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'

const requests = ref([])
const loading = ref(true)
const editingId = ref(0)
const editQty = ref(0)
const busy = ref('')

function peso(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Rows are summed per product/sub-product; actions apply to the first underlying item
function itemId(item) {
  return Number(String(item.item_ids || '').split(',')[0]) || 0
}

function canApprove(item) {
  return Number(item.current_stock || 0) >= Number(item.quantity)
}

function stockBadge(item) {
  const stock = Number(item.current_stock || 0)
  if (stock <= 0) return { cls: 'badge-danger', text: '0 — No stock' }
  if (Number(item.quantity) > stock) return { cls: 'badge-danger', text: `${stock} — Over!` }
  if (stock <= 5) return { cls: 'badge-warning', text: String(stock) }
  return { cls: 'badge-success', text: String(stock) }
}

async function load() {
  try {
    const res = await stockoutApi.getPending()
    requests.value = res.data?.requests || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load pending requests.'), 'error')
  } finally {
    loading.value = false
  }
}

useAutoReload(load)

async function run(key, action, successFallback) {
  busy.value = key
  try {
    const res = await action()
    toast(res.message || successFallback)
    triggerAutoReload('stockout-approval')
    await load()
  } catch (err) {
    toast(errorMessage(err), 'error')
  } finally {
    busy.value = ''
  }
}

function approve(item) {
  run(`approve-${itemId(item)}`, () => stockoutApi.approveItem(itemId(item)), 'Item approved.')
}

// ── Reject with a reason (shown to the staff member in their history and notifications) ──
const REASON_SUGGESTIONS = [
  'Not enough stock right now',
  'Quantity is more than needed',
  'Already issued to your office',
  'Please request a different item',
]
const rejecting = ref(null) // { item, request }
const rejectReason = ref('')
const rejectError = ref('')

function reject(item, request) {
  rejecting.value = { item, request }
  rejectReason.value = ''
  rejectError.value = ''
}

function closeReject() {
  if (busy.value) return
  rejecting.value = null
}

async function confirmReject() {
  const reason = rejectReason.value.trim()
  if (reason.length < 3) {
    rejectError.value = 'Please give a short reason; the requester will see it.'
    return
  }
  const item = rejecting.value.item
  busy.value = `reject-${itemId(item)}`
  try {
    const res = await stockoutApi.rejectItem(itemId(item), reason)
    toast(res.message || 'Item rejected.')
    rejecting.value = null
    triggerAutoReload('stockout-approval')
    await load()
  } catch (err) {
    rejectError.value = errorMessage(err, 'The item could not be rejected.')
  } finally {
    busy.value = ''
  }
}

async function approveAll(request) {
  const ok = await confirmDialog({
    title: 'Approve all items?',
    message: `Every item in request #${request.temp_stockout_id} will be approved and deducted from stock.`,
    confirmText: 'Approve all',
  })
  if (!ok) return
  run(`all-${request.temp_stockout_id}`, () => stockoutApi.approveAll(request.temp_stockout_id), 'Request approved.')
}

function startEdit(item) {
  editingId.value = itemId(item)
  editQty.value = Number(item.quantity)
}

function saveEdit(item) {
  const qty = Number(editQty.value)
  const stock = Number(item.current_stock || 0)
  if (!(qty >= 1) || !Number.isInteger(qty)) {
    toast('Quantity must be a whole number of at least 1.', 'error')
    return
  }
  if (qty > stock) {
    toast(`Cannot exceed available stock (${stock}).`, 'error')
    return
  }
  run(`edit-${itemId(item)}`, async () => {
    const res = await stockoutApi.editPendingItem(itemId(item), qty)
    editingId.value = 0
    return res
  }, 'Quantity updated.')
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Approval</p>
        <h1 class="hero-title">Pending Stock-Out Requests</h1>
        <p class="hero-subtitle">Review staff requests. Adjust quantities if needed, then accept or reject each item.</p>
      </div>
      <router-link to="/stockout/history" class="btn btn-secondary">Request History</router-link>
    </div>

    <section v-if="loading" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">
      Loading requests…
    </section>

    <section v-else-if="!requests.length" class="panel" style="padding: 3rem; text-align: center;">
      <h3 style="margin-bottom: 0.5rem;">No pending requests</h3>
      <p style="color: var(--text-muted);">All stock-out requests have been processed.</p>
    </section>

    <section v-for="request in requests" :key="request.temp_stockout_id" class="panel" style="margin-bottom: 1.5rem;">
      <div class="panel-header">
        <div class="panel-title-group">
          <h2 class="panel-title">Request #{{ request.temp_stockout_id }}</h2>
          <p class="panel-subtitle">
            By <strong>{{ request.requester_name || 'Unknown' }}</strong> · {{ request.office_name }} · {{ request.submitted_at || request.created_at }}
          </p>
        </div>
        <button type="button" class="btn btn-primary" :disabled="!!busy" @click="approveAll(request)">Accept All</button>
      </div>
      <div class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th>Item</th>
              <th>Unit</th>
              <th>Description</th>
              <th>Qty Requested</th>
              <th>Stock Available</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in request.items" :key="item.item_ids">
              <td class="cell-title">
                <strong>{{ item.item_name }}</strong>
                <div v-if="item.copy_label" style="font-size: 0.8rem; color: var(--text-muted);">{{ item.copy_label }}</div>
                <div v-if="Number(item.copy_unit_cost) > 0" style="font-size: 0.8rem; color: var(--color-success);">₱{{ peso(item.copy_unit_cost) }}</div>
              </td>
              <td data-label="Unit">{{ item.unit || '—' }}</td>
              <td data-label="Description">{{ item.description || '—' }}</td>
              <td data-label="Qty Requested">
                <div v-if="editingId === itemId(item)" style="display: flex; gap: 0.4rem;">
                  <input v-model="editQty" type="number" min="1" step="1" :max="Number(item.current_stock)" class="form-input" style="width: 90px;" @keydown.enter.prevent="saveEdit(item)" />
                  <button type="button" class="btn btn-sm btn-primary" :disabled="!!busy" @click="saveEdit(item)">✓</button>
                  <button type="button" class="btn btn-sm btn-secondary" @click="editingId = 0">✕</button>
                </div>
                <strong v-else>{{ Number(item.quantity) }}</strong>
              </td>
              <td data-label="Stock Available"><span class="badge" :class="stockBadge(item).cls">{{ stockBadge(item).text }}</span></td>
              <td data-label="Status"><span class="badge" :class="item.status === 'pending' ? 'badge-warning' : 'badge-neutral'" style="text-transform: capitalize;">{{ item.status }}</span></td>
              <td class="cell-actions" style="text-align: right; white-space: nowrap;">
                <template v-if="item.status === 'pending' && editingId !== itemId(item)">
                  <button type="button" class="btn btn-sm btn-secondary" @click="startEdit(item)">Edit</button>
                  <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    style="margin-left: 0.4rem;"
                    :disabled="!!busy || !canApprove(item)"
                    :title="canApprove(item) ? '' : `Only ${Number(item.current_stock)} in stock`"
                    @click="approve(item)"
                  >Accept</button>
                  <button type="button" class="btn btn-sm btn-secondary" style="color: var(--color-danger); margin-left: 0.4rem;" :disabled="!!busy" @click="reject(item, request)">Reject</button>
                </template>
                <span v-else-if="item.status !== 'pending'" style="color: var(--text-muted);">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Reject with a reason -->
    <div v-if="rejecting" class="modal-backdrop" @click.self="closeReject">
      <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
          <div>
            <h2 style="font-size: 1.15rem;">Reject this item?</h2>
            <p class="panel-subtitle">
              <strong>{{ rejecting.item.item_name }}</strong> · {{ Number(rejecting.item.quantity) }} {{ rejecting.item.unit }}
              · requested by {{ rejecting.request.requester_name || 'staff' }}
            </p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="closeReject">✕</button>
        </div>

        <form id="rejectForm" class="modal-body" @submit.prevent="confirmReject">
          <label class="form-label" for="rejectReason">Reason for rejecting *</label>
          <textarea
            id="rejectReason"
            v-model="rejectReason"
            class="form-input reject-reason"
            rows="3"
            maxlength="500"
            placeholder="e.g. Not enough stock right now; please request again next week."
            autofocus
          />
          <div class="reject-hint">
            <span>{{ rejectReason.trim().length }}/500</span>
            <span>Shown to the requester in their notifications and request history.</span>
          </div>

          <div class="reject-suggestions">
            <button v-for="s in REASON_SUGGESTIONS" :key="s" type="button" class="reject-chip" @click="rejectReason = s">{{ s }}</button>
          </div>

          <p v-if="rejectError" class="reject-error">{{ rejectError }}</p>
          <p class="reject-note">The item will not be deducted from stock.</p>
        </form>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="!!busy" @click="closeReject">Cancel</button>
          <button type="submit" form="rejectForm" class="btn btn-primary reject-confirm" :disabled="!!busy || rejectReason.trim().length < 3">
            {{ busy ? 'Rejecting…' : 'Reject item' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.reject-reason {
  width: 100%;
  resize: vertical;
  min-height: 84px;
  font: inherit;
}

.reject-hint {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 0.35rem;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.reject-suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.85rem;
}

.reject-chip {
  padding: 0.25rem 0.7rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-full);
  background: var(--bg-subtle);
  color: var(--text-main);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.reject-chip:hover {
  border-color: var(--color-danger);
  color: var(--color-danger);
}

.reject-error {
  margin: 0.75rem 0 0;
  font-size: 0.83rem;
  color: var(--color-danger);
}

.reject-note {
  margin: 0.75rem 0 0;
  font-size: 0.78rem;
  color: var(--text-muted);
}

.reject-confirm,
.reject-confirm:hover:not(:disabled) {
  background: var(--color-danger);
  border-color: var(--color-danger);
}
</style>
