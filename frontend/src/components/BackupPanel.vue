<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Database, Upload, FolderOpen, Download, RotateCcw } from 'lucide-vue-next'
import { backupsApi } from '../api/backups'
import { triggerBlobDownload } from '../api/export'
import { useAuthStore } from '../stores/authStore'
import { toast, errorMessage } from '../composables/useToast'

const authStore = useAuthStore()

const INTERVALS = [
  [1, 'Every 1 hour'], [2, 'Every 2 hours'], [4, 'Every 4 hours'], [6, 'Every 6 hours'],
  [8, 'Every 8 hours'], [12, 'Every 12 hours'], [24, 'Once a day (24 h)'], [48, 'Every 2 days'],
  [72, 'Every 3 days'], [168, 'Once a week'], [720, 'Once a month'], [0, 'Manual only'],
]

const backups = ref([])
const loading = ref(true)
const busy = ref('')
const fileInput = ref(null)
const config = reactive({ backup_dir: '', backup_dir_2: '', backup_interval_hours: 24, backup_time: '00:00' })

function formatSize(bytes) {
  const n = Number(bytes || 0)
  if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`
  if (n >= 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${n} B`
}

async function load() {
  try {
    const res = await backupsApi.list()
    backups.value = res.data?.backups || []
    Object.assign(config, {
      backup_dir: res.data?.backup_dir || '',
      backup_dir_2: res.data?.backup_dir_2 || '',
      backup_interval_hours: Number(res.data?.backup_interval_hours ?? 24),
      backup_time: res.data?.backup_time || '00:00',
    })
  } catch (err) {
    toast(errorMessage(err, 'Failed to load backups.'), 'error')
  } finally {
    loading.value = false
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

function backupNow() {
  run('backup', () => backupsApi.run())
}

function restore(backup) {
  if (!confirm(`Restore "${backup.backup_filename}"? Existing records will be overwritten with the data in this backup.`)) return
  run(`restore-${backup.backup_id}`, () => backupsApi.restoreFromBackup(backup.backup_id))
}

function restoreFromFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!confirm(`Restore from "${file.name}"? Existing records will be overwritten with the data in this file.`)) return
  run('restore-file', () => backupsApi.restoreFromFile(file))
}

async function download(backup) {
  busy.value = `download-${backup.backup_id}`
  try {
    const res = await backupsApi.download(backup.backup_id)
    triggerBlobDownload(res.data, backup.backup_filename)
  } catch (err) {
    toast(errorMessage(err, 'Download failed.'), 'error')
  } finally {
    busy.value = ''
  }
}

function saveConfig() {
  run('config', () => backupsApi.saveConfig({ ...config }))
}

onMounted(load)
</script>

<template>
  <section class="panel" style="margin-bottom: 1.5rem;">
    <div class="panel-header">
      <div class="panel-title-group">
        <h2 class="panel-title" style="display: flex; align-items: center; gap: 8px;">
          <Database :size="18" style="color: var(--color-primary);" />
          <span>Data Backup &amp; Restore</span>
        </h2>
        <p class="panel-subtitle">Backups cover your office's records. Automatic backups run after login when one is due.</p>
      </div>
    </div>

    <div style="padding: 1.25rem;">
      <!-- Settings: managers only -->
      <form v-if="authStore.levelId >= 3" class="backup-config" @submit.prevent="saveConfig">
        <div class="form-group" style="flex: 2;">
          <label class="form-label">Storage Directory — Drive 1</label>
          <input v-model="config.backup_dir" class="form-input" placeholder="e.g. writable/backups/" required />
        </div>
        <div class="form-group" style="flex: 2;">
          <label class="form-label">Storage Directory — Drive 2 (optional mirror)</label>
          <input v-model="config.backup_dir_2" class="form-input" placeholder="Leave blank to skip" />
        </div>
        <div class="form-group" style="flex: 1;">
          <label class="form-label">Auto-Backup Every</label>
          <select v-model.number="config.backup_interval_hours" class="form-select">
            <option v-for="[hours, label] in INTERVALS" :key="hours" :value="hours">{{ label }}</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">At Time</label>
          <input v-model="config.backup_time" type="time" class="form-input" />
        </div>
        <div class="form-group" style="justify-content: flex-end;">
          <button type="submit" class="btn btn-secondary" :disabled="!!busy">Save Settings</button>
        </div>
      </form>

      <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
        <button type="button" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;" :disabled="!!busy" @click="backupNow">
          <Upload :size="15" />
          <span>{{ busy === 'backup' ? 'Backing up…' : 'Backup Now' }}</span>
        </button>
        <input ref="fileInput" type="file" accept=".sql" style="display: none;" @change="restoreFromFile" />
        <button type="button" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;" :disabled="!!busy" @click="fileInput.click()">
          <FolderOpen :size="15" />
          <span>Restore from .sql File</span>
        </button>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Slot</th>
              <th>Filename</th>
              <th>Date Created</th>
              <th>Size</th>
              <th>Office</th>
              <th>Created By</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="7" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">Loading backups…</td></tr>
            <tr v-else-if="!backups.length"><td colspan="7" style="text-align: center; padding: 1.5rem; color: var(--text-muted);">No backups yet.</td></tr>
            <tr v-for="b in backups" :key="b.backup_id">
              <td>{{ b.backup_slot }}</td>
              <td style="font-family: var(--font-mono); font-size: 0.8rem;">{{ b.backup_filename }}</td>
              <td>{{ b.created_at }}</td>
              <td>{{ formatSize(b.file_size_bytes) }}</td>
              <td>{{ b.office_name }}</td>
              <td>{{ b.created_by_name }}</td>
              <td style="text-align: right; white-space: nowrap;">
                <button type="button" class="btn btn-sm btn-secondary" style="display: inline-flex; align-items: center; gap: 4px;" :disabled="!!busy" @click="download(b)">
                  <Download :size="13" />
                  <span>Download</span>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" style="margin-left: 0.4rem; display: inline-flex; align-items: center; gap: 4px;" :disabled="!!busy" @click="restore(b)">
                  <RotateCcw :size="13" />
                  <span>Restore</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<style scoped>
.backup-config {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding-bottom: 1rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid var(--border-subtle);
}

.backup-config .form-group {
  min-width: 160px;
  margin-bottom: 0;
}
</style>
