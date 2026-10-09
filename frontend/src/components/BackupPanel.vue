<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import {
  Database, PackagePlus, Archive, RotateCcw, CalendarClock, Download, Lock, LockOpen,
  ShieldCheck, CheckCircle2, AlertTriangle, Building2, Boxes, Users, Settings, HardDrive, Clock,
  FolderTree, FileText, Folder, UploadCloud, KeyRound, ListChecks,
} from 'lucide-vue-next'
import { backupsApi } from '../api/backups'
import { triggerBlobDownload } from '../api/export'
import { toast, errorMessage } from '../composables/useToast'

const INTERVALS = [
  [1, 'Every 1 hour'], [2, 'Every 2 hours'], [4, 'Every 4 hours'], [6, 'Every 6 hours'],
  [8, 'Every 8 hours'], [12, 'Every 12 hours'], [24, 'Once a day (24 h)'], [48, 'Every 2 days'],
  [72, 'Every 3 days'], [168, 'Once a week'], [720, 'Once a month'], [0, 'Manual only'],
]
const SECTION_ICONS = { setup: Building2, inventory: Boxes, users: Users, settings: Settings }
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

const view = ref('create') // create | restore | archive | schedule
const loading = ref(true)
const busy = ref('')
const backups = ref([])
const sections = ref([])
const config = reactive({ backup_dir: '', backup_dir_2: '', backup_interval_hours: 24, backup_time: '00:00' })

function formatSize(bytes) {
  const n = Number(bytes || 0)
  if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`
  if (n >= 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${n} B`
}

function sectionTitle(key) {
  return sections.value.find((s) => s.key === key)?.title || key
}

function ago(datetime) {
  const t = new Date(String(datetime).replace(' ', 'T')).getTime()
  if (!t) return '—'
  const mins = Math.round((Date.now() - t) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins} min ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`
  const days = Math.round(hours / 24)
  return `${days} day${days === 1 ? '' : 's'} ago`
}

function dateParts(datetime) {
  const [d, time] = String(datetime || '').split(' ')
  const [y, m, day] = (d || '').split('-').map(Number)
  return { day: day || '–', month: MONTHS[(m || 1) - 1] || '', year: y || '', time: (time || '').slice(0, 5) }
}

async function load() {
  try {
    const res = await backupsApi.list()
    backups.value = res.data?.backups || []
    sections.value = res.data?.sections || []
    if (!Object.keys(chosen).length) {
      for (const s of sections.value) chosen[s.key] = true
    }
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

onMounted(load)

// ── Status strip ─────────────────────────────────────────────────────────

const lastBackup = computed(() => [...backups.value].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)))[0] || null)
const scheduleLabel = computed(() => {
  const h = Number(config.backup_interval_hours)
  if (!h) return 'Manual only'
  const label = INTERVALS.find(([v]) => v === h)?.[1] || `Every ${h} hours`
  return h >= 24 ? `${label.replace(' (24 h)', '')} at ${config.backup_time}` : label
})
const health = computed(() => {
  if (!lastBackup.value) return { tone: 'warn', text: 'No backup yet' }
  const hours = (Date.now() - new Date(String(lastBackup.value.created_at).replace(' ', 'T')).getTime()) / 3600000
  const limit = Math.max(Number(config.backup_interval_hours) || 24, 24) * 2
  return hours > limit ? { tone: 'warn', text: 'Backup is overdue' } : { tone: 'ok', text: 'Backups are up to date' }
})

// ── Back up ──────────────────────────────────────────────────────────────

const chosen = reactive({})
const protect = ref(false)
const password = ref('')
const password2 = ref('')
const created = ref(null)

const requiredBy = computed(() => {
  const map = {}
  for (const s of sections.value) {
    if (!chosen[s.key]) continue
    for (const r of s.requires || []) map[r] = s.title
  }
  return map
})
const selectedSections = computed(() => sections.value.filter((s) => chosen[s.key] || requiredBy.value[s.key]).map((s) => s.key))
const passwordProblem = computed(() => {
  if (!protect.value) return ''
  if (password.value.length < 8) return 'Use at least 8 characters.'
  if (password.value !== password2.value) return 'The two passwords do not match.'
  return ''
})
const canCreate = computed(() => !busy.value && selectedSections.value.length > 0 && !passwordProblem.value)

// The package as a folder tree, following the switches
const tree = computed(() => {
  const lines = [
    { depth: 0, folder: false, name: 'README.txt', note: 'how to restore, in plain words' },
    { depth: 0, folder: false, name: 'manifest.json', note: 'checksum of every file' },
    { depth: 0, folder: false, name: 'schema.sql', note: 'restores onto an empty server' },
    { depth: 0, folder: false, name: 'data.json', note: 'the records' },
    { depth: 0, folder: true, name: 'spreadsheets', note: 'open in Excel' },
  ]
  for (const key of selectedSections.value) {
    const title = sectionTitle(key)
    lines.push({ depth: 1, folder: false, name: `${title} - ….csv`, note: '' })
  }
  if (selectedSections.value.includes('inventory')) {
    lines.push({ depth: 1, folder: false, name: 'Inventory Summary.csv', note: 'stock on hand' })
    lines.push({ depth: 0, folder: true, name: 'barcodes', note: 'batch barcode images' })
  }
  return lines
})

function toggleSection(key) {
  if (requiredBy.value[key]) return
  chosen[key] = !chosen[key]
}

async function createBackup() {
  if (!canCreate.value) return
  busy.value = 'create'
  created.value = null
  try {
    const res = await backupsApi.run({ sections: selectedSections.value, password: protect.value ? password.value : '' })
    created.value = { backup_id: res.data?.backup_id, filename: res.data?.filename }
    toast(res.message || 'Backup created.')
    password.value = ''
    password2.value = ''
    await load()
  } catch (err) {
    toast(errorMessage(err, 'Backup failed.'), 'error')
  } finally {
    busy.value = ''
  }
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

// ── Restore ──────────────────────────────────────────────────────────────

const fileInput = ref(null)
const dragging = ref(false)
const source = ref(null)
const restorePassword = ref('')
const needsPassword = ref(false)
const info = ref(null)
const restoreChoice = reactive({})
const understood = ref(false)
const restoreError = ref('')
const restoreDone = ref(null)

const restoreSections = computed(() => {
  if (!info.value || info.value.legacy) return []
  const picked = new Set(info.value.sections.filter((s) => restoreChoice[s.key]).map((s) => s.key))
  for (const s of info.value.sections) if (picked.has(s.key)) for (const r of s.requires || []) picked.add(r)
  return info.value.sections.filter((s) => picked.has(s.key)).map((s) => s.key)
})
const canRestore = computed(() =>
  !busy.value && info.value && understood.value &&
  (info.value.legacy || (info.value.office_match && restoreSections.value.length > 0))
)
// The plan beside the restore steps: each item is done, current, upcoming or blocked
const restorePlan = computed(() => {
  const chosen = !!source.value
  const locked = needsPassword.value || !!info.value?.encrypted
  const checked = !!info.value
  const blocked = chosen && !checked && !!restoreError.value && !needsPassword.value
  const wrongOffice = checked && !info.value.legacy && !info.value.office_match
  const done = !!restoreDone.value
  const working = busy.value === 'restore'

  const state = (isDone, isCurrent, isBlocked = false) => (isBlocked ? 'blocked' : isDone ? 'done' : isCurrent ? 'current' : 'todo')

  const items = [
    {
      title: 'Choose the backup',
      text: chosen ? source.value.name : 'A .zip or .bsubackup file from a USB drive, or one from the archive.',
      state: state(chosen, !chosen),
    },
  ]
  if (locked) {
    items.push({
      title: 'Unlock it',
      text: checked ? 'Password accepted.' : 'Enter the password the backup was protected with.',
      state: state(checked, needsPassword.value),
    })
  }
  items.push(
    {
      title: 'The system checks it',
      text: blocked
        ? 'This file could not be used; see the message on the left.'
        : wrongOffice
          ? `It belongs to ${info.value.office?.name}, so it can't be restored here.`
          : checked && !info.value.legacy
            ? `All ${info.value.files} files match their checksums. Made ${info.value.created_at}.`
            : 'Password, office and a checksum of every file. A damaged or edited backup is refused.',
      state: state(checked && !wrongOffice, chosen && !checked && !needsPassword.value, blocked || wrongOffice),
    },
    {
      title: 'You pick what to restore',
      text: info.value?.legacy
        ? 'An older SQL backup restores everything in it.'
        : checked
          ? `${restoreSections.value.length} section${restoreSections.value.length === 1 ? '' : 's'} chosen. Inventory replaces; the rest is merged.`
          : 'Untick anything you want to keep as it is now.',
      state: state(done || (checked && understood.value), checked && !understood.value && !wrongOffice),
    },
    {
      title: 'A safety backup is saved',
      text: "Today's data is copied to the archive first, so the restore can be undone.",
      state: state(done, working),
    },
    {
      title: 'Restore',
      text: 'All or nothing: if anything goes wrong, nothing changes.',
      state: state(done, working || (checked && understood.value && !done)),
    },
  )

  return items
})

function resetRestore() {
  source.value = null
  restorePassword.value = ''
  needsPassword.value = false
  info.value = null
  understood.value = false
  restoreError.value = ''
  restoreDone.value = null
  for (const k of Object.keys(restoreChoice)) delete restoreChoice[k]
  if (fileInput.value) fileInput.value.value = ''
}

function useFile(file) {
  if (!file) return
  resetRestore()
  source.value = { file, name: file.name }
  inspect()
}

function onDrop(event) {
  dragging.value = false
  useFile(event.dataTransfer?.files?.[0])
}

function restoreFromArchive(backup) {
  resetRestore()
  source.value = { backupId: backup.backup_id, name: backup.backup_filename }
  view.value = 'restore'
  inspect()
}

async function inspect() {
  if (!source.value) return
  busy.value = 'inspect'
  restoreError.value = ''
  try {
    const res = await backupsApi.inspect(source.value, restorePassword.value)
    info.value = res.data
    needsPassword.value = false
    for (const s of res.data.sections || []) restoreChoice[s.key] = true
  } catch (err) {
    info.value = null
    needsPassword.value = !!err.response?.data?.errors?.password_required
    restoreError.value = errorMessage(err, 'The backup could not be read.')
  } finally {
    busy.value = ''
  }
}

async function restore() {
  if (!canRestore.value) return
  busy.value = 'restore'
  restoreError.value = ''
  try {
    const res = await backupsApi.restore(source.value, { sections: restoreSections.value, password: restorePassword.value })
    restoreDone.value = res.message || 'Restore completed.'
    toast(restoreDone.value)
    await load()
  } catch (err) {
    restoreError.value = errorMessage(err, 'Restore failed; nothing was changed.')
  } finally {
    busy.value = ''
  }
}

// ── Schedule ─────────────────────────────────────────────────────────────

async function saveConfig() {
  busy.value = 'config'
  try {
    const res = await backupsApi.saveConfig({ ...config })
    toast(res.message || 'Saved.')
    await load()
  } catch (err) {
    toast(errorMessage(err), 'error')
  } finally {
    busy.value = ''
  }
}

const VIEWS = computed(() => [
  { key: 'create', icon: PackagePlus, title: 'Back up', text: 'Make a new package' },
  { key: 'restore', icon: RotateCcw, title: 'Restore', text: 'Bring records back' },
  { key: 'archive', icon: Archive, title: 'Archive', text: `${backups.value.length} saved backup${backups.value.length === 1 ? '' : 's'}` },
  { key: 'schedule', icon: CalendarClock, title: 'Schedule', text: scheduleLabel.value },
])
</script>

<template>
  <section class="panel bk">
    <!-- Title + status strip -->
    <div class="bk-top">
      <div class="bk-heading">
        <span class="bk-heading-icon"><Database :size="20" /></span>
        <div>
          <h2>Data Backup &amp; Restore</h2>
          <p>Complete packages of your office's records, kept on this computer. Nothing is sent online.</p>
        </div>
      </div>

      <div class="bk-status" :class="`is-${health.tone}`">
        <div class="bk-status-main">
          <component :is="health.tone === 'ok' ? CheckCircle2 : AlertTriangle" :size="18" />
          <strong>{{ health.text }}</strong>
        </div>
        <div class="bk-status-item"><Clock :size="14" /> Last: {{ lastBackup ? ago(lastBackup.created_at) : 'never' }}</div>
        <div class="bk-status-item"><CalendarClock :size="14" /> Automatic: {{ scheduleLabel }}</div>
        <div class="bk-status-item"><HardDrive :size="14" /> {{ config.backup_dir_2 ? 'Drive 1 + mirror' : 'Drive 1' }}</div>
      </div>
    </div>

    <!-- Action tiles -->
    <div class="bk-tiles" role="tablist">
      <button
        v-for="v in VIEWS"
        :key="v.key"
        type="button"
        role="tab"
        class="bk-tile"
        :class="{ 'is-active': view === v.key }"
        :aria-selected="view === v.key"
        @click="view = v.key"
      >
        <span class="bk-tile-icon"><component :is="v.icon" :size="18" /></span>
        <span class="bk-tile-text"><strong>{{ v.title }}</strong><small>{{ v.text }}</small></span>
      </button>
    </div>

    <div class="bk-body">
      <p v-if="loading" class="bk-muted">Loading backups…</p>

      <!-- ═════════ Back up ═════════ -->
      <div v-else-if="view === 'create'" class="bk-create">
        <div class="bk-col">
          <h3 class="bk-h3">What to include</h3>
          <ul class="bk-switches">
            <li
              v-for="s in sections"
              :key="s.key"
              :class="{ 'is-on': selectedSections.includes(s.key), 'is-locked': requiredBy[s.key] }"
              @click="toggleSection(s.key)"
            >
              <span class="bk-switch-icon"><component :is="SECTION_ICONS[s.key]" :size="18" /></span>
              <span class="bk-switch-text">
                <strong>{{ s.title }}</strong>
                <small>{{ s.description }}</small>
                <em v-if="requiredBy[s.key]">Included because {{ requiredBy[s.key] }} needs it</em>
              </span>
              <span
                class="bk-toggle"
                role="switch"
                :aria-checked="selectedSections.includes(s.key)"
                :aria-label="s.title"
              ><span /></span>
            </li>
          </ul>

          <h3 class="bk-h3">Protection</h3>
          <div class="bk-protect" :class="{ 'is-on': protect }">
            <label class="bk-protect-row">
              <Lock :size="18" />
              <span class="bk-switch-text">
                <strong>Password-protect (AES-256)</strong>
                <small>Recommended before copying to a USB drive. Saved as .bsubackup; opens only in this system.</small>
              </span>
              <input v-model="protect" type="checkbox" class="bk-sr" />
              <span class="bk-toggle" role="switch" :aria-checked="protect"><span /></span>
            </label>
            <div v-if="protect" class="bk-protect-fields">
              <input v-model="password" type="password" class="form-input" autocomplete="new-password" placeholder="Password (8+ characters)" />
              <input v-model="password2" type="password" class="form-input" autocomplete="new-password" placeholder="Type it again" />
              <p v-if="passwordProblem && password" class="bk-inline-warn"><AlertTriangle :size="13" /> {{ passwordProblem }}</p>
              <p class="bk-inline-note"><KeyRound :size="13" /> Keep the password somewhere safe; the backup can't be restored without it.</p>
            </div>
          </div>
        </div>

        <aside class="bk-preview">
          <div class="bk-preview-head">
            <FolderTree :size="16" />
            <span>Package preview</span>
          </div>
          <div class="bk-tree">
            <div class="bk-tree-root">
              <Folder :size="14" /> BSU-Inventory_{{ lastBackup?.office_name || 'Office' }}_….{{ protect ? 'bsubackup' : 'zip' }}
            </div>
            <div v-for="(line, i) in tree" :key="i" class="bk-tree-line" :class="{ 'is-folder': line.folder }" :style="{ paddingLeft: `${1 + line.depth * 1.1}rem` }">
              <component :is="line.folder ? Folder : FileText" :size="13" />
              <span class="bk-tree-name">{{ line.name }}{{ line.folder ? '/' : '' }}</span>
              <span v-if="line.note" class="bk-tree-note">— {{ line.note }}</span>
            </div>
          </div>
          <p class="bk-preview-foot">
            <ShieldCheck :size="13" />
            {{ protect ? 'Encrypted as a whole; the password unlocks it.' : 'Opens in Windows like a normal folder.' }}
          </p>
        </aside>

        <div class="bk-bar">
          <div class="bk-bar-chips">
            <span v-for="key in selectedSections" :key="key" class="bk-chip">{{ sectionTitle(key) }}</span>
            <span v-if="protect" class="bk-chip is-lock"><Lock :size="11" /> Protected</span>
            <span v-if="!selectedSections.length" class="bk-muted">Switch on at least one section.</span>
          </div>
          <button type="button" class="btn btn-primary" :disabled="!canCreate" @click="createBackup">
            <PackagePlus :size="16" />
            {{ busy === 'create' ? 'Creating backup…' : 'Create backup' }}
          </button>
        </div>

        <div v-if="created" class="bk-done">
          <CheckCircle2 :size="20" />
          <div>
            <strong>Backup saved</strong>
            <span class="bk-mono">{{ created.filename }}</span>
          </div>
          <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="download({ backup_id: created.backup_id, backup_filename: created.filename })">
            <Download :size="14" /> Download a copy
          </button>
        </div>
      </div>

      <!-- ═════════ Restore ═════════ -->
      <div v-else-if="view === 'restore'" class="bk-restore-layout">
      <div class="bk-restore">
        <!-- Step 1 (the plan on the right shows the progress) -->
        <div
          v-if="!source"
          class="bk-drop"
          :class="{ 'is-dragging': dragging }"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
          @click="fileInput.click()"
        >
          <input ref="fileInput" type="file" accept=".zip,.bsubackup,.sql" class="bk-sr" @change="useFile($event.target.files?.[0])" />
          <span class="bk-drop-icon"><UploadCloud :size="28" /></span>
          <strong>Drop a backup file here, or click to choose one</strong>
          <small>.zip or .bsubackup packages, or an older .sql backup</small>
          <button type="button" class="btn btn-sm btn-secondary" @click.stop="view = 'archive'">
            <Archive :size="14" /> Or pick one from the archive
          </button>
        </div>

        <template v-else>
          <div class="bk-source">
            <HardDrive :size="16" />
            <span class="bk-mono">{{ source.name }}</span>
            <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="resetRestore">Choose another</button>
          </div>

          <p v-if="busy === 'inspect'" class="bk-muted">Checking the backup…</p>

          <div v-if="needsPassword" class="bk-unlock">
            <Lock :size="18" />
            <input v-model="restorePassword" type="password" class="form-input" placeholder="This backup is password-protected — enter its password" @keyup.enter="inspect" />
            <button type="button" class="btn btn-primary" :disabled="!restorePassword || !!busy" @click="inspect">
              <LockOpen :size="15" /> Unlock
            </button>
          </div>

          <div v-if="restoreError" class="bk-error"><AlertTriangle :size="16" /> <span>{{ restoreError }}</span></div>

          <!-- Step 2 & 3 -->
          <template v-if="info">
            <div v-if="info.legacy" class="bk-legacy">
              <Archive :size="18" />
              <span>An older SQL backup from before packages existed. It restores everything in it at once and can't be checked beforehand.</span>
            </div>

            <template v-else>
              <dl class="bk-facts">
                <div><dt>Office</dt><dd>{{ info.office?.name }}</dd></div>
                <div><dt>Made</dt><dd>{{ info.created_at }}</dd></div>
                <div><dt>By</dt><dd>{{ info.created_by }}</dd></div>
                <div><dt>Check</dt><dd class="is-ok"><CheckCircle2 :size="13" /> {{ info.files }} files verified</dd></div>
                <div><dt>Protection</dt><dd>{{ info.encrypted ? 'Password' : 'None' }}</dd></div>
              </dl>

              <div v-if="!info.office_match" class="bk-error">
                <AlertTriangle :size="16" />
                <span>This backup belongs to <strong>{{ info.office?.name }}</strong>. A backup can only be restored into its own office.</span>
              </div>

              <h3 class="bk-h3">What to restore</h3>
              <ul class="bk-switches">
                <li
                  v-for="s in info.sections"
                  :key="s.key"
                  :class="{ 'is-on': restoreSections.includes(s.key) }"
                  @click="restoreChoice[s.key] = !restoreChoice[s.key]"
                >
                  <span class="bk-switch-icon"><component :is="SECTION_ICONS[s.key]" :size="18" /></span>
                  <span class="bk-switch-text">
                    <strong>{{ s.title }}</strong>
                    <small><template v-for="(n, name, i) in s.rows" :key="name">{{ i ? ' · ' : '' }}{{ n }} {{ name.toLowerCase() }}</template></small>
                    <em :class="{ 'is-danger': s.key === 'inventory' }">
                      {{ s.key === 'inventory' ? "Replaces this office's current inventory" : 'Updates and adds records; nothing is deleted' }}
                    </em>
                  </span>
                  <span class="bk-toggle" role="switch" :aria-checked="restoreSections.includes(s.key)"><span /></span>
                </li>
              </ul>
            </template>

            <div class="bk-confirm">
              <label class="bk-confirm-check">
                <input v-model="understood" type="checkbox" />
                <span>I understand the chosen records will be replaced. A safety backup of the current data is made first, so this can be undone.</span>
              </label>
              <button type="button" class="btn btn-primary bk-btn-warn" :disabled="!canRestore" @click="restore">
                <RotateCcw :size="16" /> {{ busy === 'restore' ? 'Restoring…' : 'Restore now' }}
              </button>
            </div>

            <div v-if="restoreDone" class="bk-done">
              <CheckCircle2 :size="20" />
              <div><strong>Restore complete</strong><span>{{ restoreDone }}</span></div>
            </div>
          </template>
        </template>
      </div>

        <!-- The plan: what happens, step by step, and what to do afterwards -->
        <aside class="bk-plan">
          <div class="bk-plan-head">
            <component :is="restoreDone ? CheckCircle2 : ListChecks" :size="16" />
            <span>{{ restoreDone ? 'What to do now' : 'What happens next' }}</span>
          </div>

          <ol v-if="!restoreDone" class="bk-plan-list">
            <li v-for="(item, i) in restorePlan" :key="item.title" :class="`is-${item.state}`">
              <span class="bk-plan-dot">
                <CheckCircle2 v-if="item.state === 'done'" :size="15" />
                <AlertTriangle v-else-if="item.state === 'blocked'" :size="14" />
                <template v-else>{{ i + 1 }}</template>
              </span>
              <div>
                <strong>{{ item.title }}</strong>
                <!-- Details only where they matter now; upcoming steps stay a short list -->
                <p v-if="item.state !== 'todo'">{{ item.text }}</p>
              </div>
            </li>
          </ol>

          <ol v-else class="bk-plan-list">
            <li class="is-current">
              <span class="bk-plan-dot"><Boxes :size="14" /></span>
              <div><strong>Check the records</strong><p>Open the Stockcard Ledger and Product List to see that everything looks right.</p></div>
            </li>
            <li class="is-current">
              <span class="bk-plan-dot"><Archive :size="14" /></span>
              <div><strong>Changed your mind?</strong><p>The safety backup marked "(before restore)" is in the Archive; restoring it puts things back.</p></div>
            </li>
            <li class="is-current">
              <span class="bk-plan-dot"><PackagePlus :size="14" /></span>
              <div><strong>Make a fresh backup</strong><p>Once you're happy, create a new backup and copy it to a USB drive.</p></div>
            </li>
          </ol>

          <p class="bk-plan-foot">
            <ShieldCheck :size="14" />
            <span>Nothing changes until you press <strong>Restore now</strong>, and a safety copy is made first.</span>
          </p>
        </aside>
      </div>

      <!-- ═════════ Archive ═════════ -->
      <div v-else-if="view === 'archive'">
        <p class="bk-muted" style="margin-bottom: 1rem;">The newest 10 backups are kept in the backup folder. Download any you want to keep on a USB drive.</p>
        <p v-if="!backups.length" class="bk-empty">No backups yet. Use "Back up" to make the first one.</p>
        <ul class="bk-list">
          <li v-for="b in backups" :key="b.backup_id">
            <div class="bk-date">
              <span class="bk-date-month">{{ dateParts(b.created_at).month }}</span>
              <span class="bk-date-day">{{ dateParts(b.created_at).day }}</span>
              <span class="bk-date-year">{{ dateParts(b.created_at).year }}</span>
            </div>
            <div class="bk-list-main">
              <div class="bk-list-name">
                <span class="bk-mono">{{ b.backup_filename }}</span>
                <Lock v-if="Number(b.encrypted)" :size="13" class="bk-lock" />
              </div>
              <div class="bk-list-chips">
                <template v-if="b.backup_format === 'package'">
                  <span v-for="key in String(b.sections || '').split(',').filter(Boolean)" :key="key" class="bk-chip">{{ sectionTitle(key) }}</span>
                </template>
                <span v-else class="bk-chip is-legacy">Older SQL backup</span>
              </div>
              <div class="bk-list-meta">
                {{ dateParts(b.created_at).time }} · {{ formatSize(b.file_size_bytes) }} · {{ b.created_by_name }} · {{ ago(b.created_at) }}
              </div>
            </div>
            <div class="bk-list-actions">
              <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="download(b)"><Download :size="13" /> Download</button>
              <button type="button" class="btn btn-sm btn-secondary" :disabled="!!busy" @click="restoreFromArchive(b)"><RotateCcw :size="13" /> Restore</button>
            </div>
          </li>
        </ul>
      </div>

      <!-- ═════════ Schedule ═════════ -->
      <form v-else-if="view === 'schedule'" class="bk-schedule" @submit.prevent="saveConfig">
        <div class="bk-schedule-card">
          <h3 class="bk-h3"><CalendarClock :size="16" /> Automatic backups</h3>
          <div class="bk-schedule-row">
            <div class="form-group">
              <label class="form-label">How often</label>
              <select v-model.number="config.backup_interval_hours" class="form-select">
                <option v-for="[hours, label] in INTERVALS" :key="hours" :value="hours">{{ label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">At time</label>
              <input v-model="config.backup_time" type="time" class="form-input" :disabled="Number(config.backup_interval_hours) < 24" />
            </div>
          </div>
          <p class="bk-inline-note"><Clock :size="13" /> Runs when a custodian or manager opens the system and one is due. Automatic backups contain everything and have no password.</p>
        </div>

        <div class="bk-schedule-card">
          <h3 class="bk-h3"><HardDrive :size="16" /> Where backups are saved</h3>
          <div class="form-group">
            <label class="form-label">Drive 1 — backup folder</label>
            <input v-model="config.backup_dir" class="form-input" placeholder="e.g. writable/backups/" required />
          </div>
          <div class="form-group">
            <label class="form-label">Drive 2 — mirror copy (optional, e.g. a USB or second disk)</label>
            <input v-model="config.backup_dir_2" class="form-input" placeholder="Leave blank to skip" />
          </div>
        </div>

        <div class="bk-schedule-save">
          <button type="submit" class="btn btn-primary" :disabled="!!busy">{{ busy === 'config' ? 'Saving…' : 'Save settings' }}</button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.bk {
  margin-bottom: 1.5rem;
  overflow: hidden;
}

/* ── Top ── */
.bk-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 1.25rem 1.5rem;
  background: linear-gradient(120deg, var(--color-primary-light), transparent 70%);
  border-bottom: 1px solid var(--border-subtle);
}

.bk-heading {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}

.bk-heading h2 {
  margin: 0;
  font-size: 1.15rem;
}

.bk-heading p {
  margin: 0.15rem 0 0;
  font-size: 0.82rem;
  color: var(--text-muted);
}

.bk-heading-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #fff;
  flex-shrink: 0;
}

.bk-status {
  display: flex;
  align-items: center;
  gap: 0.35rem 1rem;
  flex-wrap: wrap;
  padding: 0.55rem 0.9rem;
  border-radius: var(--radius-md);
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface);
  font-size: 0.8rem;
  color: var(--text-muted);
}

.bk-status-main {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
}

.bk-status.is-ok .bk-status-main { color: var(--color-success); }
.bk-status.is-warn .bk-status-main { color: var(--color-warning); }

.bk-status-item {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

/* ── Tiles ── */
.bk-tiles {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.75rem;
  padding: 1rem 1.5rem 0;
}

.bk-tile {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 0.9rem;
  border: 1.5px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  background: var(--bg-surface);
  color: var(--text-main);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color var(--transition-fast), transform var(--transition-fast), box-shadow var(--transition-fast);
}

.bk-tile:hover {
  border-color: var(--border-hover);
  transform: translateY(-1px);
}

.bk-tile.is-active {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.bk-tile-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  color: var(--color-primary);
  flex-shrink: 0;
}

.bk-tile.is-active .bk-tile-icon {
  background: var(--color-primary);
  color: #fff;
}

.bk-tile-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.bk-tile-text small {
  color: var(--text-muted);
  font-size: 0.75rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bk-body {
  padding: 1.25rem 1.5rem 1.5rem;
}

.bk-muted {
  margin: 0;
  color: var(--text-muted);
  font-size: 0.85rem;
}

.bk-h3 {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0 0 0.6rem;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.bk-mono {
  font-family: var(--font-mono);
  font-size: 0.78rem;
  word-break: break-all;
}

.bk-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

/* ── Back up ── */
.bk-create {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 1.25rem 1.5rem;
}

/* Grid children may shrink below their content (long file names, one-line tree rows),
   otherwise they widen the whole page on phones */
.bk-create > *,
.bk-restore-layout > *,
.bk-schedule > * {
  min-width: 0;
}

.bk-col {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.bk-switches {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.bk-switches li {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  border-top: 1px solid var(--border-subtle);
  cursor: pointer;
  transition: background var(--transition-fast);
}

.bk-switches li:first-child {
  border-top: 0;
}

.bk-switches li:hover {
  background: var(--bg-subtle);
}

.bk-switches li.is-locked {
  cursor: default;
}

.bk-switch-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--bg-muted);
  color: var(--text-muted);
  flex-shrink: 0;
  transition: background var(--transition-fast), color var(--transition-fast);
}

.bk-switches li.is-on .bk-switch-icon {
  background: var(--color-primary-light);
  color: var(--color-primary);
}

.bk-switch-text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  flex: 1;
  min-width: 0;
}

.bk-switch-text strong {
  font-size: 0.9rem;
}

.bk-switch-text small {
  font-size: 0.78rem;
  color: var(--text-muted);
  line-height: 1.4;
}

.bk-switch-text em {
  font-style: normal;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--color-primary);
}

.bk-switch-text em.is-danger {
  color: var(--color-danger);
}

/* iOS-style switch */
.bk-toggle {
  position: relative;
  width: 42px;
  height: 24px;
  border-radius: 999px;
  background: var(--border-hover);
  flex-shrink: 0;
  transition: background var(--transition-fast);
}

.bk-toggle span {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  transition: transform var(--transition-fast);
}

li.is-on .bk-toggle,
.bk-protect.is-on .bk-toggle {
  background: var(--color-primary);
}

li.is-on .bk-toggle span,
.bk-protect.is-on .bk-toggle span {
  transform: translateX(18px);
}

li.is-locked .bk-toggle {
  opacity: 0.55;
}

.bk-protect {
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.bk-protect-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  cursor: pointer;
}

.bk-protect-row > svg {
  color: var(--color-warning);
  flex-shrink: 0;
}

.bk-protect-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.6rem;
  padding: 0 1rem 1rem;
}

.bk-inline-note,
.bk-inline-warn {
  grid-column: 1 / -1;
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.78rem;
  color: var(--text-muted);
  line-height: 1.45;
}

.bk-inline-note svg,
.bk-inline-warn svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
}

.bk-inline-warn {
  color: var(--color-warning);
}

/* Package preview: a folder tree in a card that follows the active theme */
.bk-preview {
  align-self: start;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  background: var(--bg-surface);
  color: var(--text-main);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
}

.bk-preview-head {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-subtle);
  color: var(--text-muted);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.bk-preview-head svg {
  color: var(--color-primary);
}

.bk-tree {
  overflow-x: auto;
  padding: 0.75rem 0.9rem;
  font-size: 0.8rem;
  line-height: 1.85;
}

.bk-tree-root {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin-bottom: 0.15rem;
  color: var(--color-primary);
  font-family: var(--font-mono);
  font-size: 0.76rem;
  font-weight: 700;
  word-break: break-all;
}

.bk-tree-line {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  white-space: nowrap;
}

.bk-tree-line svg {
  flex-shrink: 0;
  color: var(--text-muted);
}

.bk-tree-line.is-folder svg {
  color: var(--color-warning);
}

/* The name always shows in full; only the explanation is shortened */
.bk-tree-name {
  flex-shrink: 0;
  font-weight: 600;
}

.bk-tree-line.is-folder .bk-tree-name {
  font-weight: 700;
}

.bk-tree-note {
  min-width: 0;
  color: var(--text-muted);
  font-size: 0.74rem;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 600px) {
  /* Too narrow for the explanations next to each file */
  .bk-tree-note {
    display: none;
  }
}

.bk-preview-foot {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  padding: 0.65rem 1rem;
  border-top: 1px solid var(--border-subtle);
  background: var(--bg-subtle);
  font-size: 0.75rem;
  color: var(--text-muted);
}

.bk-preview-foot svg {
  flex-shrink: 0;
  color: var(--color-success);
}

.bk-bar {
  grid-column: 1 / -1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
}

.bk-bar-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.bk-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.12rem 0.6rem;
  border-radius: var(--radius-full);
  background: var(--color-primary-light);
  color: var(--color-primary);
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}

.bk-chip.is-lock {
  background: var(--color-warning-bg);
  color: var(--color-warning);
}

.bk-chip.is-legacy {
  background: var(--bg-muted);
  color: var(--text-muted);
}

.bk-done {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  background: var(--color-success-bg);
  color: var(--color-success);
  margin-top: 0.75rem;
}

.bk-done > div {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
}

.bk-done span {
  color: var(--text-main);
  font-size: 0.82rem;
}

/* ── Restore ── */
.bk-restore-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 1.25rem;
  align-items: stretch; /* both columns share one height */
}

.bk-restore {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

/* The plan beside the restore steps */
.bk-plan {
  align-self: start;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  background: var(--bg-surface);
  overflow: hidden;
}

.bk-plan-head {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-subtle);
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.bk-plan-head svg {
  color: var(--color-primary);
}

.bk-plan-list {
  list-style: none;
  margin: 0;
  padding: 1rem 1.1rem 0.35rem;
}

.bk-plan-list li {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  min-height: 26px;
  padding-bottom: 1rem;
}

.bk-plan-list li > div {
  padding-top: 0.2rem;
}

/* The line joining the steps */
.bk-plan-list li:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 12px;
  top: 28px;
  bottom: 2px;
  width: 2px;
  background: var(--border-subtle);
}

.bk-plan-list li.is-done:not(:last-child)::before {
  background: var(--color-success);
}

.bk-plan-dot {
  position: relative;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 2px solid var(--border-hover);
  background: var(--bg-surface);
  color: var(--text-subtle);
  font-size: 0.75rem;
  font-weight: 800;
  flex-shrink: 0;
}

.bk-plan-list li strong {
  display: block;
  font-size: 0.85rem;
  color: var(--text-subtle);
}

.bk-plan-list li p {
  margin: 0.1rem 0 0;
  font-size: 0.76rem;
  line-height: 1.45;
  color: var(--text-subtle);
  word-break: break-word;
}

.bk-plan-list li.is-current .bk-plan-dot {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: #fff;
}

.bk-plan-list li.is-current strong,
.bk-plan-list li.is-done strong {
  color: var(--text-main);
}

.bk-plan-list li.is-current p,
.bk-plan-list li.is-done p {
  color: var(--text-muted);
}

.bk-plan-list li.is-done .bk-plan-dot {
  border-color: var(--color-success);
  color: var(--color-success);
}

.bk-plan-list li.is-blocked .bk-plan-dot {
  border-color: var(--color-danger);
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

.bk-plan-list li.is-blocked strong,
.bk-plan-list li.is-blocked p {
  color: var(--color-danger);
}

.bk-plan-foot {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0;
  padding: 0.8rem 1.1rem;
  border-top: 1px solid var(--border-subtle);
  background: var(--bg-subtle);
  font-size: 0.76rem;
  line-height: 1.5;
  color: var(--text-muted);
}

.bk-plan-foot svg {
  flex-shrink: 0;
  margin-top: 0.1rem;
  color: var(--color-primary);
}

.bk-plan-foot strong {
  color: var(--text-main);
}

.bk-drop {
  flex: 1; /* fills the column so it lines up with the plan */
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  min-height: 260px;
  padding: 2rem 1rem;
  border: 1.5px dashed var(--border-hover);
  border-radius: var(--radius-lg);
  background: var(--bg-subtle);
  color: var(--color-primary);
  text-align: center;
  cursor: pointer;
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.bk-drop:hover,
.bk-drop.is-dragging {
  border-color: var(--color-primary);
  background: var(--color-primary-light);
}

.bk-drop-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 60px;
  height: 60px;
  margin-bottom: 0.35rem;
  border-radius: 50%;
  background: var(--bg-surface);
  box-shadow: var(--shadow-sm);
  transition: transform var(--transition-fast);
}

.bk-drop:hover .bk-drop-icon,
.bk-drop.is-dragging .bk-drop-icon {
  transform: translateY(-3px);
}

.bk-drop strong {
  font-size: 0.95rem;
  color: var(--text-main);
}

.bk-drop small {
  color: var(--text-muted);
  margin-bottom: 0.4rem;
}

.bk-source {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
  padding: 0.7rem 1rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  margin-bottom: 1rem;
}

.bk-source .bk-mono {
  flex: 1;
}

.bk-unlock {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  margin-bottom: 1rem;
}

.bk-unlock .form-input {
  flex: 1;
}

.bk-error,
.bk-legacy {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-md);
  font-size: 0.85rem;
  line-height: 1.5;
  margin-bottom: 1rem;
}

.bk-error {
  background: var(--color-danger-bg);
  color: var(--color-danger);
}

.bk-legacy {
  background: var(--bg-subtle);
  color: var(--text-muted);
}

.bk-error svg,
.bk-legacy svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
}

.bk-facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 0.6rem;
  margin: 0 0 1.25rem;
}

.bk-facts > div {
  padding: 0.6rem 0.8rem;
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
}

.bk-facts dt {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.bk-facts dd {
  margin: 0.1rem 0 0;
  font-size: 0.85rem;
  font-weight: 700;
}

.bk-facts dd.is-ok {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  color: var(--color-success);
}

.bk-confirm {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-warning);
  background: var(--color-warning-bg);
}

.bk-confirm-check {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  flex: 1;
  min-width: 240px;
  font-size: 0.83rem;
  line-height: 1.45;
  cursor: pointer;
}

.bk-confirm-check input {
  margin-top: 0.2rem;
  accent-color: var(--color-warning);
}

.bk-btn-warn,
.bk-btn-warn:hover:not(:disabled) {
  background: var(--color-warning);
  border-color: var(--color-warning);
}

/* ── Archive ── */
.bk-empty {
  padding: 2rem;
  text-align: center;
  color: var(--text-muted);
  border: 1px dashed var(--border-subtle);
  border-radius: var(--radius-lg);
}

.bk-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.6rem;
}

.bk-list li {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  background: var(--bg-surface);
}

.bk-date {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 56px;
  padding: 0.3rem 0;
  border-radius: var(--radius-md);
  background: var(--color-primary-light);
  color: var(--color-primary);
  flex-shrink: 0;
  line-height: 1.1;
}

.bk-date-month {
  font-size: 0.68rem;
  font-weight: 800;
  text-transform: uppercase;
}

.bk-date-day {
  font-size: 1.3rem;
  font-weight: 800;
}

.bk-date-year {
  font-size: 0.65rem;
  opacity: 0.8;
}

.bk-list-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.bk-list-name {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.bk-lock {
  color: var(--color-warning);
  flex-shrink: 0;
}

.bk-list-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}

.bk-list-meta {
  font-size: 0.75rem;
  color: var(--text-muted);
}

.bk-list-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}

/* ── Schedule ── */
.bk-schedule {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.25rem;
}

.bk-schedule-card {
  padding: 1.1rem 1.25rem;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
}

.bk-schedule-row {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 0.75rem;
}

.bk-schedule-save {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
}

/* ── Narrow screens ── */
@media (max-width: 1000px) {
  .bk-create,
  .bk-schedule,
  .bk-restore-layout {
    grid-template-columns: 1fr;
  }

  .bk-plan {
    position: static;
  }

  .bk-tiles {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 600px) {
  .bk-top,
  .bk-body {
    padding-left: 1rem;
    padding-right: 1rem;
  }

  .bk-tiles {
    padding: 1rem 1rem 0;
  }

  .bk-protect-fields {
    grid-template-columns: 1fr;
  }

  .bk-list li {
    flex-wrap: wrap;
  }

  .bk-list-actions {
    width: 100%;
    justify-content: flex-start;
  }
}
</style>
