<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { Users, Clock, Building2, Trash2, Pencil, Check, X, ShieldCheck, UserPlus } from 'lucide-vue-next'
import { adminApi } from '../api/admin'
import { maskEmail } from '../utils/maskEmail'
import { useAuthStore } from '../stores/authStore'
import { useAutoReload, deduplicateById, triggerAutoReload } from '../composables/useAutoReload'
import StatCard from '../components/StatCard.vue'
import AppPagination from '../components/AppPagination.vue'
import { confirmDialog } from '../composables/useConfirm'
import { toast, errorMessage } from '../composables/useToast'

const authStore = useAuthStore()

const loading = ref(true)
const actionLoading = ref(false)
const adminData = ref({
  records: {
    users: [],
    user_office_table: [],
    entity_table: [],
    unit_table: [],
    type_of_product: [],
    office_table: [],
  },
  pendingUsers: [],
  userOffices: [],
  levels: [],
  definitions: {},
})

// Search queries per section
const searchUsers = ref('')
const searchOffices = ref('')
const activeSection = ref('users') // 'users' | 'offices'

// Modal state
const isModalOpen = ref(false)
const modalType = ref('user_office_table')
const modalTitle = ref('')
const editId = ref(0)
const officeFormName = ref('')
const modalLoading = ref(false)
const actionMessage = ref('')

async function fetchAdminData() {
  loading.value = true
  try {
    const res = await adminApi.getAdminData()
    if (res.status && res.data) {
      adminData.value = {
        records: {
          users: deduplicateById(res.data.records?.users || [], 'user_id'),
          user_office_table: deduplicateById(res.data.records?.user_office_table || res.data.userOffices || [], 'user_office_id'),
          entity_table: deduplicateById(res.data.records?.entity_table || [], 'entity_id'),
          unit_table: deduplicateById(res.data.records?.unit_table || [], 'unit_id'),
          type_of_product: deduplicateById(res.data.records?.type_of_product || [], 'type_id'),
          office_table: deduplicateById(res.data.records?.office_table || [], 'office_id'),
        },
        pendingUsers: deduplicateById(res.data.pendingUsers || [], 'user_id'),
        userOffices: deduplicateById(res.data.userOffices || [], 'user_office_id'),
        levels: deduplicateById(res.data.levels || [], 'lvl_of_access_id'),
        definitions: res.data.definitions || {},
      }
    }
  } catch (err) {
    console.error('Failed to load admin dashboard data', err)
  } finally {
    loading.value = false
  }
}

// Auto-reload on background interval, window focus, and when mutations occur
useAutoReload(fetchAdminData)

// Actions
// The account an action is about
function userDetails(user = {}) {
  return [
    ['Name', user.name],
    ['Username', user.username],
    ['Role', user.role],
    ['Office', user.user_office_name],
  ]
}

async function handleActivateUser(userId, username, user = {}) {
  if (actionLoading.value) return
  const ok = await confirmDialog({
    title: 'Activate user?',
    message: `${username ? `"${username}"` : 'This user'} will be able to log in.`,
    details: userDetails(user),
    confirmText: 'Activate',
  })
  if (!ok) return
  actionLoading.value = true
  try {
    await adminApi.activateUser(userId)
    flashMessage('User account activated successfully!')
    triggerAutoReload('activate-user')
    await fetchAdminData()
  } catch (err) {
    toast(errorMessage(err, 'Failed to activate user.'), 'error')
  } finally {
    actionLoading.value = false
  }
}

async function handleDeclineUser(userId, user = {}) {
  if (actionLoading.value) return
  const ok = await confirmDialog({
    title: 'Decline this sign-up?',
    message: 'The account will not be accepted and the registration is removed. The person can sign up again later.',
    details: userDetails(user),
    confirmText: 'Decline',
    variant: 'danger',
  })
  if (!ok) return
  actionLoading.value = true
  try {
    await adminApi.declineUser(userId)
    flashMessage('Sign-up declined.')
    triggerAutoReload('decline-user')
    await fetchAdminData()
  } catch (err) {
    toast(errorMessage(err, 'Failed to decline the sign-up.'), 'error')
  } finally {
    actionLoading.value = false
  }
}

async function handleDeactivateUser(userId, username, user = {}) {
  if (actionLoading.value) return
  const ok = await confirmDialog({
    title: 'Deactivate user?',
    message: `${username ? `"${username}"` : 'This user'} will no longer be able to log in. You can reactivate the account later.`,
    details: userDetails(user),
    confirmText: 'Deactivate',
    variant: 'danger',
  })
  if (ok) {
    actionLoading.value = true
    try {
      await adminApi.deactivateUser(userId)
      flashMessage('User account deactivated successfully.')
      triggerAutoReload('deactivate-user')
      await fetchAdminData()
    } catch (err) {
      toast(errorMessage(err, 'Failed to deactivate user.'), 'error')
    } finally {
      actionLoading.value = false
    }
  }
}

async function handleDeleteRecord(type, id, label, record = {}) {
  if (actionLoading.value) return
  const ok = await confirmDialog({
    title: type === 'users' ? 'Delete user?' : 'Delete office?',
    message: `${label ? `"${label}"` : 'This record'} will be permanently deleted. This cannot be undone.`,
    details: type === 'users' ? userDetails(record) : [['Office', label]],
    confirmText: 'Delete',
    variant: 'danger',
  })
  if (ok) {
    actionLoading.value = true
    try {
      await adminApi.deleteRecord(type, id)
      flashMessage('Record deleted successfully.')
      triggerAutoReload('delete-record')
      await fetchAdminData()
    } catch (err) {
      toast(errorMessage(err, 'Cannot delete record.'), 'error')
    } finally {
      actionLoading.value = false
    }
  }
}

function openAddOfficeModal() {
  editId.value = 0
  officeFormName.value = ''
  modalType.value = 'user_office_table'
  modalTitle.value = 'Add User Office'
  isModalOpen.value = true
}

function openEditOfficeModal(office) {
  editId.value = Number(office.user_office_id)
  officeFormName.value = office.user_office_name
  modalType.value = 'user_office_table'
  modalTitle.value = 'Edit User Office'
  isModalOpen.value = true
}

async function handleSaveOffice() {
  if (modalLoading.value || !officeFormName.value.trim()) return
  const ok = await confirmDialog({
    title: editId.value ? 'Save changes to this office?' : 'Add this office?',
    message: `"${officeFormName.value.trim()}" will be ${editId.value ? 'updated' : 'added'}.`,
    details: [['Office name', officeFormName.value.trim()]],
    confirmText: editId.value ? 'Save changes' : 'Add office',
  })
  if (!ok) return
  modalLoading.value = true
  try {
    await adminApi.saveRecord('user_office_table', {
      id: editId.value,
      user_office_name: officeFormName.value.trim(),
    })
    isModalOpen.value = false
    flashMessage('Office saved successfully.')
    triggerAutoReload('save-office')
    await fetchAdminData()
  } catch (err) {
    toast(errorMessage(err, 'Failed to save office.'), 'error')
  } finally {
    modalLoading.value = false
  }
}

function flashMessage(msg) {
  actionMessage.value = msg
  setTimeout(() => {
    actionMessage.value = ''
  }, 4000)
}

// Filtered Lists
const filteredUsers = computed(() => {
  const q = searchUsers.value.toLowerCase().trim()
  if (!q) return adminData.value.records.users
  return adminData.value.records.users.filter(
    (u) =>
      (u.username && u.username.toLowerCase().includes(q)) ||
      (u.name && u.name.toLowerCase().includes(q)) ||
      (u.email && u.email.toLowerCase().includes(q)) ||
      (u.role && u.role.toLowerCase().includes(q)) ||
      (u.user_office_name && u.user_office_name.toLowerCase().includes(q))
  )
})

const filteredOffices = computed(() => {
  const q = searchOffices.value.toLowerCase().trim()
  const list = adminData.value.records.user_office_table
  if (!q) return list
  return list.filter((o) => o.user_office_name && o.user_office_name.toLowerCase().includes(q))
})

// Pagination for Users & Offices
const usersPage = ref(1)
const usersPageSize = ref(10)
const paginatedUsers = computed(() => {
  const start = (usersPage.value - 1) * usersPageSize.value
  return filteredUsers.value.slice(start, start + usersPageSize.value)
})
const usersTotalPages = computed(() => Math.max(1, Math.ceil(filteredUsers.value.length / usersPageSize.value)))
watch(searchUsers, () => { usersPage.value = 1 })

const officesPage = ref(1)
const officesPageSize = ref(10)
const paginatedOffices = computed(() => {
  const start = (officesPage.value - 1) * officesPageSize.value
  return filteredOffices.value.slice(start, start + officesPageSize.value)
})
const officesTotalPages = computed(() => Math.max(1, Math.ceil(filteredOffices.value.length / officesPageSize.value)))
watch(searchOffices, () => { officesPage.value = 1 })

onMounted(() => {
  fetchAdminData()
})
</script>

<template>
  <div class="admin-dashboard-page">
    <!-- Success Banner -->
    <div v-if="actionMessage" class="badge badge-success" style="display: flex; margin-bottom: 1.25rem; padding: 0.75rem 1.25rem; font-size: 0.9rem;">
      <span>✓ {{ actionMessage }}</span>
    </div>

    <!-- Hero Section (Matches admin.php) -->
    <section class="page-hero">
      <div>
        <p class="hero-eyebrow">Administration</p>
        <h1 class="hero-title">User Management</h1>
        <p class="hero-subtitle">
          Manage system users, offices, and account activations from this central administration hub.
        </p>
        <router-link to="/audit-log" class="btn btn-secondary" style="margin-top: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem;">
          <ShieldCheck :size="15" /> View Audit Log
        </router-link>
      </div>

      <div class="hero-badge-widget">
        <span>Pending Activation</span>
        <strong :style="{ color: adminData.pendingUsers.length > 0 ? 'var(--color-danger)' : 'var(--color-success)' }">
          {{ adminData.pendingUsers.length }}
        </strong>
        <small>accounts awaiting approval</small>
      </div>
    </section>

    <!-- Summary Cards (Matches admin.php) -->
    <section class="stats-grid">
      <StatCard
        title="Total Users"
        :value="adminData.records.users.length"
        hint="Registered accounts across all offices"
        variant="primary"
        :icon="Users"
        :active="activeSection === 'users'"
        @click="activeSection = 'users'"
      />

      <StatCard
        title="Pending Activation"
        :value="adminData.pendingUsers.length"
        :hint="adminData.pendingUsers.length > 0 ? 'Urgent approvals required' : 'No pending applicants'"
        variant="warning"
        :icon="Clock"
        :active="activeSection === 'pending'"
        @click="activeSection = 'pending'"
      />

      <StatCard
        title="User Offices"
        :value="adminData.records.user_office_table.length"
        hint="Designated campus divisions & centers"
        variant="info"
        :icon="Building2"
        :active="activeSection === 'offices'"
        @click="activeSection = 'offices'"
      />
    </section>

    <!-- Pending Applicants: sign-ups waiting to be activated or declined -->
    <section v-if="adminData.pendingUsers.length > 0 || activeSection === 'pending'" class="panel pending-panel">
      <div class="panel-header pending-head">
        <div class="pending-title-wrap">
          <span class="pending-icon"><UserPlus :size="18" /></span>
          <div class="panel-title-group">
            <h2 class="panel-title">
              Pending Applicants
              <span class="pending-count">{{ adminData.pendingUsers.length }}</span>
            </h2>
            <p class="panel-subtitle">New sign-ups waiting to be activated or declined before they can log in.</p>
          </div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="data-table stack-mobile">
          <thead>
            <tr>
              <th>Full Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Requested Role</th>
              <th>Assigned Office</th>
              <th class="pending-actions-head">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pUser in adminData.pendingUsers" :key="pUser.user_id">
              <td data-label="Full Name" class="cell-title"><strong>{{ pUser.name || '—' }}</strong></td>
              <td data-label="Username">{{ pUser.username }}</td>
              <td data-label="Email">{{ maskEmail(pUser.email) || '—' }}</td>
              <td data-label="Requested Role"><span class="badge badge-info">{{ pUser.role }}</span></td>
              <td data-label="Assigned Office">{{ pUser.user_office_name || 'N/A' }}</td>
              <td class="cell-actions">
                <div class="pending-actions">
                  <button
                    type="button"
                    class="btn btn-sm btn-primary pending-btn"
                    :disabled="actionLoading"
                    @click="handleActivateUser(pUser.user_id, pUser.username, pUser)"
                  >
                    <Check :size="14" />
                    <span>Activate</span>
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary pending-btn pending-btn-decline"
                    :disabled="actionLoading"
                    @click="handleDeclineUser(pUser.user_id, pUser)"
                  >
                    <X :size="14" />
                    <span>Decline</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Navigation Tabs for Sections -->
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.25rem;">
      <button
        type="button"
        class="btn btn-sm"
        :class="activeSection === 'users' ? 'btn-primary' : 'btn-secondary'"
        style="display: inline-flex; align-items: center; gap: 6px;"
        @click="activeSection = 'users'"
      >
        <Users :size="15" />
        <span>Users Directory ({{ adminData.records.users.length }})</span>
      </button>

      <button
        type="button"
        class="btn btn-sm"
        :class="activeSection === 'offices' ? 'btn-primary' : 'btn-secondary'"
        style="display: inline-flex; align-items: center; gap: 6px;"
        @click="activeSection = 'offices'"
      >
        <Building2 :size="15" />
        <span>User Offices ({{ adminData.records.user_office_table.length }})</span>
      </button>
    </div>

    <!-- ── Users Management Section ── -->
    <section v-if="activeSection === 'users'" class="panel">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <div style="flex: 1; max-width: 400px;">
          <input
            v-model="searchUsers"
            type="text"
            class="form-input"
            style="width: 100%;"
            placeholder="Search users by name, username, email, or role..."
          />
        </div>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Full Name</th>
              <th>Username</th>
              <th>Email</th>
              <th>Office</th>
              <th>Role</th>
              <th>Status</th>
              <th style="width: 180px; text-align: center;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in paginatedUsers" :key="user.user_id">
              <td><strong>{{ user.name || user.username }}</strong></td>
              <td style="font-family: var(--font-mono); font-size: 0.825rem;">{{ user.username }}</td>
              <td>{{ maskEmail(user.email) || '—' }}</td>
              <td>{{ user.user_office_name || 'N/A' }}</td>
              <td>
                <span class="badge badge-neutral">{{ user.role }}</span>
              </td>
              <td>
                <span
                  class="badge"
                  :class="user.activity_status === 'Active' ? 'badge-success' : user.activity_status === 'Deactivated' ? 'badge-danger' : 'badge-warning'"
                >
                  {{ user.activity_status }}
                </span>
              </td>
              <td style="text-align: center;">
                <!-- Admin account (Technical Staff) is protected: no deactivate / delete -->
                <span v-if="Number(user.level_id) === 4" style="font-size: 0.8rem; color: var(--text-muted);">Protected</span>
                <div v-else style="display: inline-flex; gap: 0.35rem;">
                  <!-- Deactivate Button (if Active) -->
                  <button
                    v-if="user.activity_status === 'Active'"
                    type="button"
                    class="btn btn-sm btn-secondary"
                    style="color: var(--color-warning);"
                    title="Deactivate Account"
                    @click="handleDeactivateUser(user.user_id, user.username, user)"
                  >
                    Deactivate
                  </button>
                  <!-- Activate Button (if Deactivated or Pending) -->
                  <button
                    v-else
                    type="button"
                    class="btn btn-sm btn-secondary"
                    style="color: var(--color-success);"
                    title="Activate Account"
                    @click="handleActivateUser(user.user_id, user.username, user)"
                  >
                    Activate
                  </button>

                  <!-- Delete User -->
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    style="color: var(--color-danger); display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0;"
                    title="Delete User"
                    @click="handleDeleteRecord('users', user.user_id, user.username, user)"
                  >
                    <Trash2 :size="14" />
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="loading">
              <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                Loading user directory...
              </td>
            </tr>
            <tr v-else-if="!filteredUsers.length">
              <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                No matching user records found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Users Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="filteredUsers.length > 0"
        :current-page="usersPage"
        :total-pages="usersTotalPages"
        :total-items="filteredUsers.length"
        :page-size="usersPageSize"
        :page-size-options="[5, 10, 25, 50]"
        item-name="users"
        @update:current-page="usersPage = $event"
        @update:page-size="usersPageSize = $event; usersPage = 1"
      />
    </section>

    <!-- ── User Offices Section ── -->
    <section v-if="activeSection === 'offices'" class="panel">
      <div class="panel-header" style="background: var(--bg-subtle);">
        <div style="flex: 1; max-width: 400px;">
          <input
            v-model="searchOffices"
            type="text"
            class="form-input"
            style="width: 100%;"
            placeholder="Search offices..."
          />
        </div>
        <div>
          <button type="button" class="btn btn-primary" @click="openAddOfficeModal">
            + Add User Office
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width: 80px;">ID</th>
              <th>User Office Name</th>
              <th style="width: 140px; text-align: center;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="office in paginatedOffices" :key="office.user_office_id">
              <td style="font-family: var(--font-mono); color: var(--text-muted);">#{{ office.user_office_id }}</td>
              <td><strong>{{ office.user_office_name }}</strong></td>
              <td style="text-align: center;">
                <div style="display: inline-flex; gap: 0.4rem; align-items: center;">
                  <button type="button" class="btn btn-sm btn-secondary" style="display: inline-flex; align-items: center; gap: 4px;" @click="openEditOfficeModal(office)">
                    <Pencil :size="13" />
                    <span>Edit</span>
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary"
                    style="color: var(--color-danger); display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; padding: 0;"
                    title="Delete Office"
                    @click="handleDeleteRecord('user_office_table', office.user_office_id, office.user_office_name)"
                  >
                    <Trash2 :size="14" />
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredOffices.length">
              <td colspan="3" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                No offices found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Offices Pagination (< 1 2 3 ... x > Page items : Go to : ) -->
      <AppPagination
        v-if="filteredOffices.length > 0"
        :current-page="officesPage"
        :total-pages="officesTotalPages"
        :total-items="filteredOffices.length"
        :page-size="officesPageSize"
        :page-size-options="[5, 10, 25, 50]"
        item-name="offices"
        @update:current-page="officesPage = $event"
        @update:page-size="officesPageSize = $event; officesPage = 1"
      />
    </section>

    <!-- Modal for Adding / Editing Office -->
    <div v-if="isModalOpen" class="modal-backdrop" @click.self="isModalOpen = false">
      <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
          <h2 style="font-size: 1.25rem;">{{ modalTitle }}</h2>
          <button type="button" class="btn btn-sm btn-secondary" @click="isModalOpen = false">✕</button>
        </div>
        <div class="modal-body">
          <form @submit.prevent="handleSaveOffice" id="officeForm">
            <div class="form-group">
              <label class="form-label">User Office Name *</label>
              <input
                v-model="officeFormName"
                type="text"
                class="form-input"
                placeholder="e.g. Food Processing Center"
                required
              />
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="isModalOpen = false">Cancel</button>
          <button type="submit" form="officeForm" class="btn btn-primary" :disabled="modalLoading">
            {{ modalLoading ? 'Saving...' : 'Save Office' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Pending applicants ── */
.pending-panel {
  border-color: var(--color-warning);
}

.pending-head {
  background: var(--color-warning-bg);
}

.pending-title-wrap {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.pending-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--bg-surface);
  border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent);
  color: var(--color-warning);
}

.pending-head .panel-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--text-main);
}

.pending-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 22px;
  padding: 0 7px;
  border-radius: var(--radius-full);
  background: var(--color-warning);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 800;
}

.pending-actions-head {
  width: 220px;
  text-align: right !important;
}

/* Activate and Decline side by side, the same size */
.pending-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-wrap: nowrap;
}

.pending-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  min-width: 96px;
  white-space: nowrap;
}

.pending-btn-decline {
  color: var(--color-danger);
  border-color: color-mix(in srgb, var(--color-danger) 40%, transparent);
}

.pending-btn-decline:hover:not(:disabled) {
  background: var(--color-danger-bg);
  border-color: var(--color-danger);
}

@media (max-width: 640px) {
  .pending-actions {
    width: 100%;
  }
  .pending-btn {
    flex: 1;
  }
}
</style>
