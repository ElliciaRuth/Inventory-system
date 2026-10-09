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

async function reject(item) {
  const ok = await confirmDialog({
    title: 'Reject item?',
    message: `"${item.item_name}" will be rejected and will not be deducted from stock.`,
    confirmText: 'Reject',
    variant: 'danger',
  })
  if (!ok) return
  run(`reject-${itemId(item)}`, () => stockoutApi.rejectItem(itemId(item)), 'Item rejected.')
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
            By <strong>{{ request.requester_name || 'Unknown' }}</strong> · {{ request.office_name }} · {{ request.created_at }}
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
              <td data-label="Status"><span class="badge badge-neutral" style="text-transform: capitalize;">{{ item.status }}</span></td>
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
                  <button type="button" class="btn btn-sm btn-secondary" style="color: var(--color-danger); margin-left: 0.4rem;" :disabled="!!busy" @click="reject(item)">Reject</button>
                </template>
                <span v-else-if="item.status !== 'pending'" style="color: var(--text-muted);">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
