<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Clock, UserCheck, Users, AlertTriangle } from 'lucide-vue-next'
import { settingsApi } from '../api/settings'
import { useAuthStore } from '../stores/authStore'
import { toast, errorMessage } from '../composables/useToast'
import BackupPanel from '../components/BackupPanel.vue'

const authStore = useAuthStore()

const SECTION_TITLES = {
  users: 'Users',
  entity_table: 'Entity',
  unit_table: 'Unit',
  reference_table: 'Reference',
  type_of_product: 'Product Type',
  office_table: 'Office',
}

const data = ref({ definitions: {}, records: {}, pendingUsers: [], levels: [], userOffices: [] })
const loading = ref(true)
const busy = ref('')
const openSection = ref('')
const searches = reactive({})
const thresholds = reactive({ expiry_warning_days: 30, expiry_danger_days: 7 })

// Add / edit modal
const modal = reactive({ open: false, type: '', id: 0, values: {}, saving: false, error: '' })

const recordTypes = computed(() => Object.keys(data.value.definitions).filter((t) => t !== 'users'))

const currentUserId = computed(() => Number(authStore.user?.id || 0))

const users = computed(() =>
  (data.value.records.users || []).filter((u) => Number(u.user_id) !== currentUserId.value)
)

function filteredUsers(activityId) {
  const q = (searches.users || '').toLowerCase()
  return users.value.filter((u) => {
    const active = Number(u.user_activity_id) === 1
    if ((activityId === 1) !== active) return false
    return !q || [u.name, u.username, u.email, u.role, u.user_office_name].some((v) => String(v || '').toLowerCase().includes(q))
  })
}

function columnsFor(type) {
  const def = data.value.definitions[type]
  return def ? def.fields.filter((f) => f !== 'password' && !f.endsWith('_id')) : []
}

function labelFor(type, field) {
  const def = data.value.definitions[type]
  return def?.labels?.[field] || field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function rowsFor(type) {
  const q = (searches[type] || '').toLowerCase()
  const rows = data.value.records[type] || []
  if (!q) return rows
  return rows.filter((row) => columnsFor(type).some((c) => String(row[c] ?? '').toLowerCase().includes(q)))
}

function pkFor(type) {
  return data.value.definitions[type]?.pk
}

async function load() {
  try {
    const res = await settingsApi.getAll()
    data.value = { ...data.value, ...res.data }
    if (!openSection.value) {
      openSection.value = data.value.pendingUsers?.length ? 'pending' : recordTypes.value[0] || 'users'
    }
    if (authStore.levelId >= 3) {
      const sys = await settingsApi.getSystemSettings()
      Object.assign(thresholds, sys.data || {})
    }
  } catch (err) {
    toast(errorMessage(err, 'Failed to load settings.'), 'error')
  } finally {
    loading.value = false
  }
}

function toggle(section) {
  openSection.value = openSection.value === section ? '' : section
}

function openModal(type, row = null) {
  const def = data.value.definitions[type]
  const values = {}
  for (const field of def.fields) {
    values[field] = field === 'password' ? '' : row?.[field] ?? ''
  }
  Object.assign(modal, { open: true, type, id: row ? Number(row[def.pk]) : 0, values, saving: false, error: '' })
}

async function saveModal() {
  modal.saving = true
  modal.error = ''
  try {
    const res = await settingsApi.saveRecord(modal.type, { id: modal.id, ...modal.values })
    toast(res.message || 'Saved.')
    modal.open = false
    await load()
  } catch (err) {
    modal.error = errorMessage(err, 'Save failed.')
  } finally {
    modal.saving = false
  }
}

async function run(key, action) {
  busy.value = key
  try {
    const res = await action()
    toast(res.message || 'Done.')
    await load()
  } catch (err) {
    toast(errorMessage(err), 'error')
  } finally {
    busy.value = ''
  }
}

function deleteRecord(type, row) {
  if (!confirm(`Delete this ${SECTION_TITLES[type] || 'record'}?`)) return
  run(`delete-${type}-${row[pkFor(type)]}`, () => settingsApi.deleteRecord(type, row[pkFor(type)]))
}

function activate(user) {
  if (!confirm(`Activate ${user.username}?`)) return
  run(`activate-${user.user_id}`, () => settingsApi.activateUser(user.user_id))
}

function deactivate(user) {
  if (!confirm(`Deactivate ${user.username}? They will no longer be able to log in.`)) return
  run(`deactivate-${user.user_id}`, () => settingsApi.deactivateUser(user.user_id))
}

function saveThresholds() {
  run('thresholds', () =>
    settingsApi.saveSystemSettings(Number(thresholds.expiry_warning_days), Number(thresholds.expiry_danger_days))
  )
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-hero">
      <div>
        <p class="hero-eyebrow">Settings</p>
        <h1 class="hero-title">Others Management</h1>
        <p class="hero-subtitle">Maintain backups, users, references, units, product types and offices.</p>
      </div>
    </div>

    <BackupPanel />

    <div v-if="loading" class="panel" style="padding: 3rem; text-align: center; color: var(--text-muted);">Loading settings…</div>

    <template v-else>
      <!-- Expiry thresholds (managers) -->
      <section v-if="authStore.levelId >= 3" class="panel settings-section">
        <button type="button" class="section-toggle" @click="toggle('thresholds')">
          <span style="display: inline-flex; align-items: center; gap: 8px;"><Clock :size="16" /> Expiry Alert Defaults</span><span>{{ openSection === 'thresholds' ? '▴' : '▾' }}</span>
        </button>
        <form v-if="openSection === 'thresholds'" class="section-body" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;" @submit.prevent="saveThresholds">
          <div class="form-group" style="margin: 0;">
            <label class="form-label">Warning — days before expiry</label>
            <input v-model.number="thresholds.expiry_warning_days" type="number" min="1" max="365" class="form-input" />
          </div>
          <div class="form-group" style="margin: 0;">
            <label class="form-label">Danger — days before expiry</label>
            <input v-model.number="thresholds.expiry_danger_days" type="number" min="1" max="364" class="form-input" />
          </div>
          <button type="submit" class="btn btn-primary" :disabled="!!busy">Save</button>
        </form>
      </section>

      <!-- Pending applicants (managers) -->
      <section v-if="data.pendingUsers.length" class="panel settings-section" style="border: 1px solid var(--color-warning);">
        <button type="button" class="section-toggle" @click="toggle('pending')">
          <span style="display: inline-flex; align-items: center; gap: 8px;"><UserCheck :size="16" /> Pending Applicants ({{ data.pendingUsers.length }})</span><span>{{ openSection === 'pending' ? '▴' : '▾' }}</span>
        </button>
        <div v-if="openSection === 'pending'" class="table-responsive">
          <table class="data-table">
            <thead><tr><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Office</th><th></th></tr></thead>
            <tbody>
              <tr v-for="u in data.pendingUsers" :key="u.user_id">
                <td>{{ u.name }}</td>
                <td>{{ u.username }}</td>
                <td>{{ u.email }}</td>
                <td>{{ u.role }}</td>
                <td>{{ u.user_office_name || 'N/A' }}</td>
                <td style="text-align: right;">
                  <button type="button" class="btn btn-sm btn-primary" :disabled="!!busy" @click="activate(u)">Activate</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Users (managers) -->
      <section v-if="data.definitions.users" class="panel settings-section">
        <button type="button" class="section-toggle" @click="toggle('users')">
          <span style="display: inline-flex; align-items: center; gap: 8px;"><Users :size="16" /> Users</span><span>{{ openSection === 'users' ? '▴' : '▾' }}</span>
        </button>
        <div v-if="openSection === 'users'" class="section-body">
          <input v-model="searches.users" type="text" class="form-input" placeholder="Search users…" style="max-width: 320px; margin-bottom: 1rem;" />
          <template v-for="[activityId, title, color] in [[1, 'Active', 'var(--color-success)'], [2, 'Deactivated', 'var(--color-danger)']]" :key="activityId">
            <h3 :style="{ color, fontSize: '0.9rem', margin: '0.75rem 0 0.5rem' }">{{ title }} ({{ filteredUsers(activityId).length }})</h3>
            <div class="table-responsive">
              <table class="data-table">
                <thead><tr><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Office</th><th>Status</th><th></th></tr></thead>
                <tbody>
                  <tr v-if="!filteredUsers(activityId).length"><td colspan="7" style="text-align: center; color: var(--text-muted);">No {{ title.toLowerCase() }} users.</td></tr>
                  <tr v-for="u in filteredUsers(activityId)" :key="u.user_id">
                    <td>{{ u.name }}</td>
                    <td>{{ u.username }}</td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.role }}</td>
                    <td>{{ u.user_office_name }}</td>
                    <td><span class="badge" :class="activityId === 1 ? 'badge-success' : 'badge-danger'">{{ u.activity_status }}</span></td>
                    <td style="text-align: right; white-space: nowrap;">
                      <button type="button" class="btn btn-sm btn-secondary" @click="openModal('users', u)">Edit</button>
                      <button v-if="activityId === 1 && Number(u.level_id) !== 4" type="button" class="btn btn-sm btn-secondary" style="margin-left: 0.4rem; color: var(--color-danger);" :disabled="!!busy" @click="deactivate(u)">Deactivate</button>
                      <button v-else-if="activityId !== 1" type="button" class="btn btn-sm btn-primary" style="margin-left: 0.4rem;" :disabled="!!busy" @click="activate(u)">Activate</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </section>

      <!-- Reference data -->
      <section v-for="type in recordTypes" :key="type" class="panel settings-section">
        <button type="button" class="section-toggle" @click="toggle(type)">
          <span>{{ SECTION_TITLES[type] || type }}</span><span>{{ openSection === type ? '▴' : '▾' }}</span>
        </button>
        <div v-if="openSection === type" class="section-body">
          <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem;">
            <input v-model="searches[type]" type="text" class="form-input" :placeholder="`Search ${SECTION_TITLES[type] || type}…`" style="max-width: 320px;" />
            <button type="button" class="btn btn-primary" @click="openModal(type)">+ Add {{ SECTION_TITLES[type] || type }}</button>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th v-for="col in columnsFor(type)" :key="col">{{ labelFor(type, col) }}</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!rowsFor(type).length"><td :colspan="columnsFor(type).length + 1" style="text-align: center; color: var(--text-muted);">No records.</td></tr>
                <tr v-for="row in rowsFor(type)" :key="row[pkFor(type)]">
                  <td v-for="col in columnsFor(type)" :key="col">{{ row[col] }}</td>
                  <td style="text-align: right; white-space: nowrap;">
                    <button type="button" class="btn btn-sm btn-secondary" @click="openModal(type, row)">Edit</button>
                    <button type="button" class="btn btn-sm btn-secondary" style="margin-left: 0.4rem; color: var(--color-danger);" :disabled="!!busy" @click="deleteRecord(type, row)">Delete</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </template>

    <!-- Add / edit modal -->
    <div v-if="modal.open" class="modal-backdrop" @click.self="modal.open = false">
      <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
          <h2 style="font-size: 1.2rem;">{{ modal.id ? 'Edit' : 'Add' }} {{ SECTION_TITLES[modal.type] || modal.type }}</h2>
          <button type="button" class="btn btn-sm btn-secondary" @click="modal.open = false">✕</button>
        </div>
        <form class="modal-body" @submit.prevent="saveModal">
          <div v-if="modal.error" class="badge badge-danger" style="display: flex; align-items: center; gap: 8px; margin-bottom: 1rem; padding: 0.65rem 1rem; width: 100%; white-space: normal;">
            <AlertTriangle :size="16" style="flex-shrink: 0;" />
            <span>{{ modal.error }}</span>
          </div>
          <div v-for="field in data.definitions[modal.type].fields" :key="field" class="form-group">
            <template v-if="field === 'lvl_of_access_id'">
              <label class="form-label">Level of Access</label>
              <select v-model="modal.values[field]" class="form-select" required>
                <option value="">Select level</option>
                <option v-for="l in data.levels.filter((l) => Number(l.lvl_of_access) < 4)" :key="l.lvl_of_access_id" :value="String(l.lvl_of_access_id)">{{ l.role }}</option>
              </select>
            </template>
            <template v-else-if="field === 'user_office_id'">
              <label class="form-label">User Office</label>
              <select v-model="modal.values[field]" class="form-select" required>
                <option value="">Select user office</option>
                <option v-for="o in data.userOffices" :key="o.user_office_id" :value="String(o.user_office_id)">{{ o.user_office_name }}</option>
              </select>
            </template>
            <template v-else-if="field === 'password'">
              <label class="form-label">New Password <small style="font-weight: 400;">(leave blank to keep)</small></label>
              <input v-model="modal.values[field]" type="password" class="form-input" autocomplete="new-password" />
            </template>
            <template v-else>
              <label class="form-label">{{ labelFor(modal.type, field) }}</label>
              <input v-model="modal.values[field]" :type="field === 'email' ? 'email' : 'text'" class="form-input" :required="field !== 'email'" />
            </template>
          </div>
          <div class="modal-footer" style="padding: 0; border: 0;">
            <button type="button" class="btn btn-secondary" @click="modal.open = false">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="modal.saving">{{ modal.saving ? 'Saving…' : 'Save' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-section {
  margin-bottom: 1rem;
}

.section-toggle {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.25rem;
  background: none;
  border: 0;
  font: inherit;
  font-weight: 700;
  font-size: 1rem;
  color: var(--text-main);
  cursor: pointer;
}

.section-body {
  padding: 0 1.25rem 1.25rem;
}
</style>
