<script setup>
import { ref, watch, onMounted } from 'vue'
import { ShieldCheck, Search, ChevronDown, ChevronRight, Lock } from 'lucide-vue-next'
import { auditApi } from '../api/audit'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload } from '../composables/useAutoReload'
import { toast, errorMessage } from '../composables/useToast'
import AppPagination from '../components/AppPagination.vue'

const authStore = useAuthStore()

// Filter groups match the first part of the action name (stock.*, auth.*…)
const ACTION_GROUPS = [
  { key: '', label: 'All activity' },
  { key: 'auth', label: 'Logins & passwords' },
  { key: 'stock', label: 'Stock movements' },
  { key: 'count', label: 'Physical counts' },
  { key: 'stockout', label: 'Stock-out requests' },
  { key: 'product', label: 'Products' },
  { key: 'import', label: 'Excel imports' },
  { key: 'settings', label: 'Settings' },
  { key: 'user', label: 'User accounts' },
  { key: 'backup', label: 'Backups' },
  { key: 'security', label: 'Blocked requests' },
]

// Short, readable names for each action
const ACTION_LABELS = {
  'auth.login': 'Logged in',
  'auth.logout': 'Logged out',
  'auth.login_failed': 'Failed login',
  'auth.login_blocked': 'Login refused',
  'auth.login_locked': 'Login locked',
  'auth.password_changed': 'Password changed',
  'auth.password_reset': 'Password reset',
  'stock.receipt': 'Stock in',
  'stock.issue': 'Stock out',
  'stock.borrow': 'Borrow',
  'stock.return': 'Return',
  'stock.adjust_out': 'Adjust out',
  'stock.adjust_in': 'Adjust in',
  'stock.transaction_edited': 'Ledger edited',
  'stock.transaction_deleted': 'Ledger deleted',
  'stock.cost_override': 'Cost override',
  'count.reconciled': 'Count reconciled',
  'stockout.submitted': 'Request submitted',
  'stockout.approved': 'Request accepted',
  'stockout.approved_all': 'Accepted all',
  'stockout.rejected': 'Request rejected',
  'stockout.quantity_changed': 'Request qty changed',
  'product.created': 'Product added',
  'product.updated': 'Product edited',
  'product.reset': 'Product reset',
  'product.deleted': 'Product deleted',
  'product.archived': 'Product archived',
  'product.restored': 'Product restored',
  'import.append': 'Excel import',
  'import.replace': 'Delete & import',
  'import.password_failed': 'Import password failed',
  'settings.created': 'Record added',
  'settings.updated': 'Record edited',
  'settings.deleted': 'Record deleted',
  'settings.expiry_thresholds': 'Expiry settings',
  'user.activated': 'User activated',
  'user.deactivated': 'User deactivated',
  'backup.created': 'Backup created',
  'backup.restored': 'Backup restored',
  'security.rate_limited': 'Too many requests',
  'security.payload_too_large': 'Data too large',
  'security.too_complex': 'Data too complex',
  'security.malformed': 'Malformed data',
}

// Colour per kind of action
function tone(action) {
  if (/failed|locked|blocked|deleted|replace|reset|rejected|override|restored|security\./.test(action)) return 'danger'
  if (/edited|updated|changed|adjust|reconciled|deactivated/.test(action)) return 'warning'
  if (action.startsWith('auth.')) return 'info'
  return 'success'
}

const logs = ref([])
const total = ref(0)
const totalPages = ref(1)
const page = ref(1)
const limit = ref(25)
const loading = ref(true)
const search = ref('')
const group = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const expanded = ref(new Set())

async function load() {
  try {
    const res = await auditApi.getLogs({
      page: page.value,
      limit: limit.value,
      search: search.value.trim(),
      action: group.value,
      date_from: dateFrom.value,
      date_to: dateTo.value,
    })
    logs.value = res.data?.logs || []
    total.value = res.data?.total || 0
    totalPages.value = res.data?.totalPages || 1
  } catch (err) {
    toast(errorMessage(err, 'Failed to load the audit log.'), 'error')
  } finally {
    loading.value = false
  }
}

useAutoReload(load)
onMounted(load)

function refilter() {
  page.value = 1
  load()
}

watch([group, dateFrom, dateTo], refilter)

let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(refilter, 350)
})

function toggle(id) {
  const next = new Set(expanded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expanded.value = next
}

function when(value) {
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' })
}

function pretty(details) {
  return JSON.stringify(details, null, 2)
}
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Security</p>
        <h1 class="hero-title">Audit Log</h1>
        <p class="hero-subtitle">
          Every login, stock movement, correction and override, with who did it and when.
          {{ authStore.levelId >= 4 ? 'Showing all offices.' : 'Showing your office.' }}
        </p>
      </div>
      <span class="audit-lock" title="Entries can't be edited or deleted, by anyone">
        <Lock :size="15" /> Read-only &amp; tamper-proof
      </span>
    </div>

    <section class="panel">
      <div class="panel-header audit-toolbar">
        <div class="audit-search">
          <Search :size="15" />
          <input v-model="search" type="search" class="form-input" placeholder="Search user, action, item or IP…" aria-label="Search the audit log" />
        </div>
        <select v-model="group" class="form-select audit-select" aria-label="Kind of activity">
          <option v-for="g in ACTION_GROUPS" :key="g.key" :value="g.key">{{ g.label }}</option>
        </select>
        <label class="audit-date">
          <span>From</span>
          <input v-model="dateFrom" type="date" class="form-input" />
        </label>
        <label class="audit-date">
          <span>To</span>
          <input v-model="dateTo" type="date" class="form-input" :min="dateFrom || undefined" />
        </label>
      </div>

      <div v-if="loading" class="audit-empty">Loading audit log…</div>
      <div v-else-if="!logs.length" class="audit-empty">
        <ShieldCheck :size="30" />
        <strong>No activity matches these filters.</strong>
      </div>

      <div v-else class="table-responsive">
        <table class="data-table stack-mobile audit-table">
          <thead>
            <tr>
              <th style="width: 28px;"></th>
              <th>When</th>
              <th>User</th>
              <th>Action</th>
              <th>What happened</th>
              <th>IP address</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="log in logs" :key="log.audit_id">
              <tr :class="{ 'is-open': expanded.has(log.audit_id) }" @click="log.details && toggle(log.audit_id)">
                <td class="audit-caret">
                  <component :is="expanded.has(log.audit_id) ? ChevronDown : ChevronRight" v-if="log.details" :size="15" />
                </td>
                <td data-label="When" class="audit-when">{{ when(log.created_at) }}</td>
                <td data-label="User">
                  <strong>{{ log.username || '—' }}</strong>
                  <span v-if="log.office_name" class="audit-muted">{{ log.office_name }}</span>
                </td>
                <td data-label="Action">
                  <span class="audit-tag" :class="`is-${tone(log.action)}`">{{ ACTION_LABELS[log.action] || log.action }}</span>
                </td>
                <td data-label="What happened" class="audit-summary">{{ log.summary }}</td>
                <td data-label="IP address" class="audit-ip">{{ log.ip_address || '—' }}</td>
              </tr>
              <tr v-if="expanded.has(log.audit_id)" class="audit-details-row">
                <td colspan="6">
                  <pre class="audit-details">{{ pretty(log.details) }}</pre>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <AppPagination
        v-if="total > 0"
        :current-page="page"
        :total-pages="totalPages"
        :total-items="total"
        :page-size="limit"
        :page-size-options="[10, 25, 50, 100]"
        item-name="entries"
        @update:current-page="page = $event; load()"
        @update:page-size="limit = $event; page = 1; load()"
      />
    </section>
  </div>
</template>

<style scoped>
.audit-lock {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.9rem;
  border-radius: var(--radius-full);
  background: var(--color-success-bg);
  color: var(--color-success);
  font-size: 0.8rem;
  font-weight: 700;
  white-space: nowrap;
}

.audit-toolbar {
  background: var(--bg-subtle);
  justify-content: flex-start;
  gap: 0.75rem;
}

.audit-search {
  position: relative;
  flex: 1;
  min-width: 220px;
  max-width: 360px;
}

.audit-search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  pointer-events: none;
}

.audit-search .form-input {
  width: 100%;
  padding-left: 34px;
}

.audit-select {
  width: auto;
  min-width: 180px;
}

.audit-date {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted);
}

.audit-date .form-input {
  width: auto;
}

.audit-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 3rem 1rem;
  color: var(--text-muted);
  text-align: center;
}

.audit-empty strong {
  color: var(--text-main);
}

.audit-table tbody tr:not(.audit-details-row) {
  cursor: default;
}

.audit-table tbody tr:not(.audit-details-row):has(.audit-caret svg) {
  cursor: pointer;
}

.audit-caret {
  color: var(--text-muted);
  width: 28px;
}

.audit-when {
  font-size: 0.8rem;
  white-space: nowrap;
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
}

.audit-muted {
  display: block;
  font-size: 0.72rem;
  color: var(--text-muted);
}

.audit-tag {
  display: inline-block;
  padding: 0.12rem 0.55rem;
  border-radius: var(--radius-full);
  font-size: 0.72rem;
  font-weight: 800;
  white-space: nowrap;
}

.audit-tag.is-success { background: var(--color-success-bg); color: var(--color-success); }
.audit-tag.is-info { background: var(--color-info-bg); color: var(--color-info); }
.audit-tag.is-warning { background: var(--color-warning-bg); color: var(--color-warning); }
.audit-tag.is-danger { background: var(--color-danger-bg); color: var(--color-danger); }

.audit-summary {
  font-size: 0.85rem;
  line-height: 1.45;
  min-width: 260px;
}

.audit-ip {
  font-family: var(--font-mono);
  font-size: 0.75rem;
  color: var(--text-muted);
  white-space: nowrap;
}

.audit-details-row td {
  background: var(--bg-subtle);
  padding-top: 0 !important;
}

.audit-details {
  margin: 0;
  padding: 0.75rem 1rem;
  max-height: 320px;
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  font-family: var(--font-mono);
  font-size: 0.75rem;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 640px) {
  .audit-search {
    max-width: none;
    flex-basis: 100%;
  }
}
</style>
