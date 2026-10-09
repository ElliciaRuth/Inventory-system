<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { History, Search, CheckCircle2, XCircle, Clock, MessageSquareText } from 'lucide-vue-next'
import { stockoutApi } from '../api/stockout'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, triggerAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import AppPagination from '../components/AppPagination.vue'

const authStore = useAuthStore()
// Staff see their own requests; custodians and managers see the whole office
const isStaff = computed(() => authStore.levelId <= 1)
// Custodians and managers can accept or reject pending items right from the history
const canDecide = computed(() => authStore.levelId >= 2 && authStore.levelId <= 3)

const STATUS_TABS = [
  { key: '', label: 'All' },
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Accepted' },
  { key: 'rejected', label: 'Rejected' },
]

const items = ref([])
const counts = ref({ all: 0, pending: 0, approved: 0, rejected: 0 })
const total = ref(0)
const totalPages = ref(1)
const loading = ref(true)
const status = ref('')
const search = ref('')
const page = ref(1)
const limit = ref(15)

async function load() {
  try {
    const res = await stockoutApi.getHistory({ status: status.value, search: search.value.trim(), page: page.value, limit: limit.value })
    items.value = res.data?.items || []
    counts.value = res.data?.counts || counts.value
    total.value = res.data?.total || 0
    totalPages.value = res.data?.totalPages || 1
  } catch (err) {
    toast(errorMessage(err, 'Failed to load the request history.'), 'error')
  } finally {
    loading.value = false
  }
}

useAutoReload(load)
onMounted(load)

watch(status, () => {
  page.value = 1
  load()
})

let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 350)
})

function countFor(key) {
  return key ? counts.value[key] || 0 : counts.value.all || 0
}

function qty(item) {
  const n = Number(item.quantity || 0)
  return `${Number.isInteger(n) ? n : n.toFixed(2)} ${item.unit || ''}`.trim()
}

function when(datetime) {
  if (!datetime) return ''
  const d = new Date(String(datetime).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return datetime
  return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

// ── Accept / reject a pending item ──
const busy = ref('')

async function accept(item) {
  const id = Number(item.temp_stockout_item_id)
  busy.value = `accept-${id}`
  try {
    const res = await stockoutApi.approveItem(id)
    toast(res.message || 'Item approved.')
    triggerAutoReload('stockout-approval')
    await load()
  } catch (err) {
    toast(errorMessage(err, 'The item could not be accepted.'), 'error')
  } finally {
    busy.value = ''
  }
}

const REASON_SUGGESTIONS = [
  'Not enough stock right now',
  'Quantity is more than needed',
  'Already issued to your office',
  'Please request a different item',
]
const rejecting = ref(null)
const rejectReason = ref('')
const rejectError = ref('')

function reject(item) {
  rejecting.value = item
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
  const id = Number(rejecting.value.temp_stockout_item_id)
  busy.value = `reject-${id}`
  try {
    const res = await stockoutApi.rejectItem(id, reason)
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

const STATUS_META = {
  pending: { label: 'Pending', cls: 'is-pending', icon: Clock },
  approved: { label: 'Accepted', cls: 'is-approved', icon: CheckCircle2 },
  rejected: { label: 'Rejected', cls: 'is-rejected', icon: XCircle },
}
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Stock Out</p>
        <h1 class="hero-title">{{ isStaff ? 'My Request History' : 'Request History' }}</h1>
        <p class="hero-subtitle">
          {{ isStaff
            ? 'Every item you have requested, and whether it was accepted or rejected (with the reason).'
            : "All stock-out requests of your office: what was accepted, what was rejected and why." }}
        </p>
      </div>
      <router-link :to="isStaff ? '/stockout' : '/stockout/pending'" class="btn btn-secondary">
        {{ isStaff ? '+ New Request' : 'Pending Requests' }}
      </router-link>
    </div>

    <section class="panel">
      <div class="panel-header hist-toolbar">
        <div class="hist-tabs" role="tablist">
          <button
            v-for="t in STATUS_TABS"
            :key="t.key"
            type="button"
            role="tab"
            class="hist-tab"
            :class="[{ 'is-active': status === t.key }, t.key && `tab-${t.key}`]"
            :aria-selected="status === t.key"
            @click="status = t.key"
          >
            {{ t.label }} <span class="hist-tab-count">{{ countFor(t.key) }}</span>
          </button>
        </div>
        <div class="hist-search">
          <Search :size="15" />
          <input v-model="search" type="search" class="form-input" :placeholder="isStaff ? 'Search item, request # or reason…' : 'Search item, requester, request # or reason…'" />
        </div>
      </div>

      <div v-if="loading" class="hist-empty">Loading history…</div>

      <div v-else-if="!items.length" class="hist-empty">
        <History :size="30" />
        <strong>{{ search || status ? 'Nothing matches these filters.' : 'No requests yet.' }}</strong>
        <span v-if="isStaff && !search && !status">Submitted stock-out requests will appear here with their result.</span>
      </div>

      <div v-else class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th>Requested</th>
              <th>Item</th>
              <th style="text-align: right;">Qty</th>
              <th v-if="!isStaff">Requested By</th>
              <th>Status</th>
              <th>Decision</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.temp_stockout_item_id">
              <td data-label="Requested">
                <span class="hist-date">{{ when(item.submitted_at) }}</span>
                <span class="hist-req">Request #{{ item.temp_stockout_id }}</span>
              </td>
              <td class="cell-title">
                <strong>{{ item.item_name || 'Item' }}</strong>
                <div v-if="item.copy_label" class="hist-muted">{{ item.copy_label }}</div>
                <div v-if="item.description" class="hist-muted">{{ item.description }}</div>
              </td>
              <td data-label="Qty" style="text-align: right; font-weight: 700;">{{ qty(item) }}</td>
              <td v-if="!isStaff" data-label="Requested By">{{ item.requester_name }}</td>
              <td data-label="Status">
                <span class="hist-status" :class="STATUS_META[item.status]?.cls">
                  <component :is="STATUS_META[item.status]?.icon || Clock" :size="13" />
                  {{ STATUS_META[item.status]?.label || item.status }}
                </span>
              </td>
              <td data-label="Decision" class="hist-decision">
                <template v-if="item.status === 'pending'">
                  <div v-if="canDecide" class="hist-actions">
                    <button
                      type="button"
                      class="btn btn-sm btn-primary"
                      :disabled="!!busy"
                      @click="accept(item)"
                    >{{ busy === `accept-${item.temp_stockout_item_id}` ? 'Accepting…' : 'Accept' }}</button>
                    <button
                      type="button"
                      class="btn btn-sm btn-secondary hist-reject-btn"
                      :disabled="!!busy"
                      @click="reject(item)"
                    >Reject</button>
                  </div>
                  <span v-else class="hist-muted">Waiting for a custodian or manager</span>
                </template>
                <template v-else>
                  <span class="hist-muted">
                    {{ item.status === 'approved' ? 'Accepted' : 'Rejected' }}{{ item.decided_by_name ? ` by ${item.decided_by_name}` : '' }}
                    <template v-if="item.decided_at"> · {{ when(item.decided_at) }}</template>
                  </span>
                  <div v-if="item.status === 'rejected'" class="hist-reason">
                    <MessageSquareText :size="13" />
                    <span>{{ item.decision_reason || 'No reason was recorded.' }}</span>
                  </div>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="total > 0"
        :current-page="page"
        :total-pages="totalPages"
        :total-items="total"
        :page-size="limit"
        :page-size-options="[10, 15, 25, 50, 100]"
        item-name="items"
        @update:current-page="page = $event; load()"
        @update:page-size="limit = $event; page = 1; load()"
      />
    </section>

    <!-- Reject with a reason -->
    <div v-if="rejecting" class="modal-backdrop" @click.self="closeReject">
      <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
          <div>
            <h2 style="font-size: 1.15rem;">Reject this item?</h2>
            <p class="panel-subtitle">
              <strong>{{ rejecting.item_name || 'Item' }}</strong> · {{ qty(rejecting) }}
              · requested by {{ rejecting.requester_name || 'staff' }}
            </p>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="closeReject">✕</button>
        </div>

        <form id="histRejectForm" class="modal-body" @submit.prevent="confirmReject">
          <label class="form-label" for="histRejectReason">Reason for rejecting *</label>
          <textarea
            id="histRejectReason"
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
          <button type="submit" form="histRejectForm" class="btn btn-primary reject-confirm" :disabled="!!busy || rejectReason.trim().length < 3">
            {{ busy ? 'Rejecting…' : 'Reject item' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.hist-toolbar {
  background: var(--bg-subtle);
  gap: 0.75rem;
}

.hist-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.hist-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-full);
  background: var(--bg-surface);
  color: var(--text-muted);
  font: inherit;
  font-size: 0.83rem;
  font-weight: 600;
  cursor: pointer;
}

.hist-tab:hover {
  color: var(--text-main);
  border-color: var(--border-hover);
}

.hist-tab.is-active {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: #fff;
}

.hist-tab.is-active.tab-approved {
  border-color: var(--color-success);
  background: var(--color-success);
}

.hist-tab.is-active.tab-rejected {
  border-color: var(--color-danger);
  background: var(--color-danger);
}

.hist-tab.is-active.tab-pending {
  border-color: var(--color-warning);
  background: var(--color-warning);
}

.hist-tab-count {
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: var(--radius-full);
  background: rgba(127, 127, 127, 0.16);
  font-size: 0.72rem;
  text-align: center;
}

.hist-tab.is-active .hist-tab-count {
  background: rgba(255, 255, 255, 0.25);
}

.hist-search {
  position: relative;
  flex: 1;
  max-width: 340px;
  min-width: 200px;
}

.hist-search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
}

.hist-search .form-input {
  width: 100%;
  padding-left: 34px;
}

.hist-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 3rem 1rem;
  text-align: center;
  color: var(--text-muted);
}

.hist-empty strong {
  color: var(--text-main);
}

.hist-date {
  display: block;
  font-size: 0.83rem;
  white-space: nowrap;
}

.hist-req,
.hist-muted {
  display: block;
  font-size: 0.75rem;
  color: var(--text-muted);
}

.hist-status {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0.6rem;
  border-radius: var(--radius-full);
  font-size: 0.75rem;
  font-weight: 700;
  white-space: nowrap;
}

.hist-status.is-pending { background: var(--color-warning-bg); color: var(--color-warning); }
.hist-status.is-approved { background: var(--color-success-bg); color: var(--color-success); }
.hist-status.is-rejected { background: var(--color-danger-bg); color: var(--color-danger); }

.hist-decision {
  max-width: 340px;
}

.hist-reason {
  display: flex;
  gap: 0.35rem;
  align-items: flex-start;
  margin-top: 0.3rem;
  padding: 0.35rem 0.55rem;
  border-left: 3px solid var(--color-danger);
  border-radius: var(--radius-sm);
  background: var(--color-danger-bg);
  font-size: 0.8rem;
  color: var(--text-main);
  line-height: 1.45;
}

.hist-reason svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
  color: var(--color-danger);
}

.hist-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.hist-reject-btn {
  color: var(--color-danger);
}

/* Reject dialog */
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

@media (max-width: 640px) {
  .hist-search {
    max-width: none;
  }

  .hist-decision {
    max-width: none;
    display: block !important;
    text-align: left !important;
  }
}
</style>
