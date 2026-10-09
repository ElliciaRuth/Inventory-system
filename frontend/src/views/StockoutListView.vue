<script setup>
import { ref, onMounted } from 'vue'
import { stockoutApi } from '../api/stockout'
import { toast, errorMessage } from '../composables/useToast'
import { confirmDialog } from '../composables/useConfirm'

const items = ref([])
const loading = ref(true)
const editingId = ref(0)
const editQty = ref(0)
const busyId = ref(0)
const submitting = ref(false)

function peso(value) {
  return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function stockBadge(item) {
  const stock = Number(item.current_stock || 0)
  if (stock <= 0) return { cls: 'badge-danger', text: '0 — No stock' }
  if (Number(item.quantity) > stock) return { cls: 'badge-danger', text: `${stock} — Over!` }
  if (stock <= 5) return { cls: 'badge-warning', text: String(stock) }
  return { cls: 'badge-success', text: String(stock) }
}

async function load() {
  loading.value = true
  try {
    const res = await stockoutApi.getDraft()
    items.value = res.data?.items || []
  } catch (err) {
    toast(errorMessage(err, 'Failed to load your list.'), 'error')
  } finally {
    loading.value = false
  }
}

function startEdit(item) {
  editingId.value = Number(item.temp_stockout_item_id)
  editQty.value = Number(item.quantity)
}

async function saveEdit(item) {
  const qty = Number(editQty.value)
  const stock = Number(item.current_stock || 0)
  if (!(qty >= 1) || !Number.isInteger(qty)) {
    toast('Quantity must be a whole number of at least 1.', 'error')
    return
  }
  if (stock > 0 && qty > stock) {
    toast(`Cannot exceed available stock (${stock}).`, 'error')
    return
  }
  busyId.value = editingId.value
  try {
    await stockoutApi.editDraftItem(item.temp_stockout_item_id, {
      quantity: qty,
      unit: item.unit || '',
      description: item.description || '',
    })
    item.quantity = qty
    editingId.value = 0
    toast('Quantity updated.')
  } catch (err) {
    toast(errorMessage(err, 'Failed to update.'), 'error')
  } finally {
    busyId.value = 0
  }
}

async function remove(item) {
  const ok = await confirmDialog({
    title: 'Remove item?',
    message: `"${item.item_name || 'This item'}" will be removed from your stock-out list.`,
    confirmText: 'Remove',
    variant: 'danger',
  })
  if (!ok) return
  busyId.value = Number(item.temp_stockout_item_id)
  try {
    await stockoutApi.removeDraftItem(item.temp_stockout_item_id)
    items.value = items.value.filter((i) => i !== item)
    toast('Item removed.')
  } catch (err) {
    toast(errorMessage(err, 'Failed to remove.'), 'error')
  } finally {
    busyId.value = 0
  }
}

async function submit() {
  const ok = await confirmDialog({
    title: 'Submit for approval?',
    message: "You won't be able to edit this list after it is submitted.",
    confirmText: 'Submit',
  })
  if (!ok) return
  submitting.value = true
  try {
    const res = await stockoutApi.submitDraft()
    toast(res.message || 'Stock-out request submitted for approval.')
    await load()
  } catch (err) {
    toast(errorMessage(err, 'Failed to submit.'), 'error')
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Stock Out</p>
        <h1 class="hero-title">My Stock-Out List</h1>
        <p class="hero-subtitle">Review and edit items before submitting them for approval.</p>
      </div>
      <router-link to="/stockout" class="btn btn-secondary">+ Add More Items</router-link>
    </div>

    <section class="panel">
      <div v-if="loading" style="padding: 3rem; text-align: center; color: var(--text-muted);">Loading your list…</div>

      <div v-else-if="!items.length" style="padding: 3rem; text-align: center;">
        <h3 style="margin-bottom: 0.5rem;">No items in your list yet</h3>
        <p style="color: var(--text-muted);">
          Go to the <router-link to="/stockout">Stock Out page</router-link> to add items.
        </p>
      </div>

      <template v-else>
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
              <tr v-for="item in items" :key="item.temp_stockout_item_id">
                <td class="cell-title">
                  <strong>{{ item.item_name }}</strong>
                  <div v-if="item.copy_label" style="font-size: 0.8rem; color: var(--text-muted);">{{ item.copy_label }}</div>
                  <div v-if="Number(item.copy_unit_cost) > 0" style="font-size: 0.8rem; color: var(--color-success);">₱{{ peso(item.copy_unit_cost) }}</div>
                </td>
                <td data-label="Unit">{{ item.unit || '—' }}</td>
                <td data-label="Description">{{ item.description || '—' }}</td>
                <td data-label="Qty Requested">
                  <div v-if="editingId === Number(item.temp_stockout_item_id)" style="display: flex; gap: 0.4rem;">
                    <input v-model="editQty" type="number" min="1" step="1" class="form-input" style="width: 90px;" @keydown.enter.prevent="saveEdit(item)" />
                    <button type="button" class="btn btn-sm btn-primary" :disabled="busyId > 0" @click="saveEdit(item)">✓</button>
                    <button type="button" class="btn btn-sm btn-secondary" @click="editingId = 0">✕</button>
                  </div>
                  <strong v-else>{{ Number(item.quantity) }}</strong>
                </td>
                <td data-label="Stock Available"><span class="badge" :class="stockBadge(item).cls">{{ stockBadge(item).text }}</span></td>
                <td data-label="Status"><span class="badge" :class="(item.status || 'pending') === 'pending' ? 'badge-warning' : 'badge-neutral'" style="text-transform: capitalize;">{{ item.status || 'pending' }}</span></td>
                <td class="cell-actions" style="text-align: right; white-space: nowrap;">
                  <template v-if="editingId !== Number(item.temp_stockout_item_id)">
                    <button type="button" class="btn btn-sm btn-secondary" @click="startEdit(item)">Edit</button>
                    <button type="button" class="btn btn-sm btn-secondary" style="color: var(--color-danger); margin-left: 0.4rem;" :disabled="busyId === Number(item.temp_stockout_item_id)" @click="remove(item)">Remove</button>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div style="padding: 1.25rem; display: flex; justify-content: flex-end;">
          <button type="button" class="btn btn-primary" :disabled="submitting" @click="submit">
            {{ submitting ? 'Submitting…' : 'Submit for Approval' }}
          </button>
        </div>
      </template>
    </section>
  </div>
</template>
