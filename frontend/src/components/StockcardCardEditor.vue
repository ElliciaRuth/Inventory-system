<script setup>
import { ref, computed, onMounted } from 'vue'
import { X, AlertTriangle, Plus, Eraser, Undo2, Info } from 'lucide-vue-next'
import { importApi } from '../api/import'

// Correct one Excel stock card before importing. Edits go to the server, which checks the
// whole file again; the Excel file itself is never changed.
const props = defineProps({
  token: { type: String, required: true },
  label: { type: String, required: true },
  warnings: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'applied'])

const HEADER_LABELS = {
  item: 'Item',
  stockno: 'Stock No.',
  description: 'Description',
  unitofmeasurement: 'Unit of Measurement',
}
const COLUMN_LABELS = {
  date: 'Date',
  reference: 'Reference',
  receipt: 'Receipt Qty',
  issue: 'Issue Qty',
  office: 'Office',
  balance: 'Balance Qty',
}

const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const card = ref(null)
const header = ref({})
const rows = ref([])
let original = { header: {}, rows: {} } // values as loaded, to send only what changed
let nextRow = null

// Rows the notes talk about ("Row 36 …") are highlighted
const flaggedRows = computed(() => {
  const set = new Set()
  for (const w of props.warnings) {
    for (const m of w.matchAll(/\brow (\d+)/gi)) set.add(Number(m[1]))
  }
  return set
})

const headerFields = computed(() => Object.keys(HEADER_LABELS).filter((f) => card.value?.header?.[f] !== null && card.value?.header?.[f] !== undefined))

const edits = computed(() => {
  const list = []
  for (const f of headerFields.value) {
    if ((header.value[f] ?? '') !== (original.header[f] ?? '')) {
      list.push({ card: props.label, row: null, field: f, value: header.value[f] ?? '' })
    }
  }
  for (const r of rows.value) {
    for (const f of card.value?.columns || []) {
      const before = original.rows[r.row]?.[f] ?? ''
      if ((r[f] ?? '') !== before) list.push({ card: props.label, row: r.row, field: f, value: r[f] ?? '' })
    }
  }
  return list
})

function isChanged(row, field) {
  return (row[field] ?? '') !== (original.rows[row.row]?.[field] ?? '')
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await importApi.getCard(props.token, props.label)
    card.value = res.data
    header.value = { ...res.data.header }
    rows.value = res.data.rows.map((r) => {
      const copy = { row: r.row }
      for (const f of res.data.columns) copy[f] = r[f] ?? ''
      return copy
    })
    original = {
      header: { ...res.data.header },
      rows: Object.fromEntries(rows.value.map((r) => [r.row, { ...r }])),
    }
    nextRow = res.data.next_row
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'The card could not be loaded.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

function clearRow(row) {
  for (const f of card.value.columns) row[f] = ''
}

function undoRow(row) {
  const before = original.rows[row.row]
  for (const f of card.value.columns) row[f] = before?.[f] ?? ''
}

function addRow() {
  if (!nextRow) return
  const row = { row: nextRow }
  for (const f of card.value.columns) row[f] = ''
  rows.value.push(row)
  nextRow += 1
}

async function apply() {
  if (!edits.value.length || saving.value) return
  saving.value = true
  errorMessage.value = ''
  try {
    const res = await importApi.reviseStockcards(props.token, edits.value)
    emit('applied', res.data.plan)
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'The corrections could not be applied.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="modal-backdrop editor-backdrop" @click.self="!saving && emit('close')">
    <div class="modal-card editor-card">
      <div class="modal-header">
        <div>
          <h2 style="font-size: 1.15rem;">Correct card: {{ label }}</h2>
          <p class="panel-subtitle">Fix misinputs here; the file is checked again when you apply. Your Excel file is not changed.</p>
        </div>
        <button type="button" class="btn btn-sm btn-secondary" :disabled="saving" @click="emit('close')">
          <X :size="16" />
        </button>
      </div>

      <div class="modal-body">
        <div v-if="errorMessage" class="editor-alert editor-alert-danger">
          <AlertTriangle :size="16" /> <span>{{ errorMessage }}</span>
        </div>

        <p v-if="loading" class="editor-muted">Loading card…</p>

        <template v-else-if="card">
          <div v-if="warnings.length" class="editor-alert editor-alert-warning">
            <AlertTriangle :size="16" />
            <ul>
              <li v-for="w in warnings" :key="w">{{ w }}</li>
            </ul>
          </div>

          <div class="editor-header-grid">
            <div v-for="f in headerFields" :key="f" class="form-group">
              <label class="form-label">{{ HEADER_LABELS[f] }}</label>
              <input
                v-model="header[f]"
                type="text"
                class="form-input"
                :class="{ 'is-changed': (header[f] ?? '') !== (original.header[f] ?? '') }"
              />
            </div>
          </div>

          <p v-if="!card.columns.length" class="editor-muted">
            This card has no Date / Reference / Receipt / Issue / Balance table, so only its header can be corrected.
          </p>

          <template v-else>
            <div class="editor-hint">
              <Info :size="14" />
              Dates as written on the card (e.g. 07/15 or 07/15/2026). Clear a whole row to leave it out.
            </div>
            <div class="table-responsive editor-table-wrap">
              <table class="data-table editor-table">
                <thead>
                  <tr>
                    <th style="width: 52px;">Row</th>
                    <th v-for="f in card.columns" :key="f">{{ COLUMN_LABELS[f] }}</th>
                    <th style="width: 72px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in rows" :key="r.row" :class="{ 'is-flagged': flaggedRows.has(r.row) }">
                    <td class="editor-rowno">
                      <AlertTriangle v-if="flaggedRows.has(r.row)" :size="12" />
                      {{ r.row }}
                    </td>
                    <td v-for="f in card.columns" :key="f">
                      <input
                        v-model="r[f]"
                        type="text"
                        class="editor-cell"
                        :class="{ 'is-num': ['receipt', 'issue', 'balance'].includes(f), 'is-changed': isChanged(r, f) }"
                        :inputmode="['receipt', 'issue', 'balance'].includes(f) ? 'decimal' : 'text'"
                      />
                    </td>
                    <td class="editor-actions">
                      <button type="button" class="editor-icon-btn" title="Clear this row (leave it out)" @click="clearRow(r)">
                        <Eraser :size="14" />
                      </button>
                      <button type="button" class="editor-icon-btn" title="Undo changes in this row" @click="undoRow(r)">
                        <Undo2 :size="14" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <button v-if="nextRow" type="button" class="btn btn-sm btn-secondary" style="margin-top: 0.6rem;" @click="addRow">
              <Plus :size="14" /> Add row
            </button>
          </template>
        </template>
      </div>

      <div class="modal-footer">
        <span class="editor-muted" style="margin-right: auto;">
          {{ edits.length ? `${edits.length} change${edits.length === 1 ? '' : 's'}` : 'No changes yet' }}
        </span>
        <button type="button" class="btn btn-secondary" :disabled="saving" @click="emit('close')">Cancel</button>
        <button type="button" class="btn btn-primary" :disabled="!edits.length || saving" @click="apply">
          {{ saving ? 'Checking the file…' : 'Apply corrections' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.editor-backdrop {
  z-index: 260;
}

.editor-card {
  max-width: 980px;
}

.editor-muted {
  color: var(--text-muted);
  font-size: 0.85rem;
}

.editor-alert {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  padding: 0.7rem 1rem;
  border-radius: var(--radius-md);
  font-size: 0.83rem;
  line-height: 1.5;
  margin-bottom: 1rem;
}

.editor-alert > svg {
  flex-shrink: 0;
  margin-top: 0.15rem;
}

.editor-alert ul {
  margin: 0;
  padding-left: 1rem;
}

.editor-alert-danger { background: var(--color-danger-bg); color: var(--color-danger); }
.editor-alert-warning { background: var(--color-warning-bg); color: var(--color-warning); }

.editor-header-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0 1rem;
}

.editor-hint {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 0.5rem;
  font-size: 0.78rem;
  color: var(--text-muted);
}

.editor-table-wrap {
  max-height: 46vh;
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
}

.editor-table {
  font-size: 0.82rem;
}

.editor-table thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: var(--bg-subtle);
}

.editor-table td {
  padding: 0.25rem 0.35rem;
}

.editor-table tr.is-flagged td {
  background: var(--color-warning-bg);
}

.editor-rowno {
  font-family: var(--font-mono);
  color: var(--text-muted);
  white-space: nowrap;
}

.editor-rowno svg {
  color: var(--color-warning);
  vertical-align: -1px;
}

.editor-cell {
  width: 100%;
  min-width: 70px;
  padding: 0.3rem 0.45rem;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-main);
  font: inherit;
}

.editor-cell:hover {
  border-color: var(--border-subtle);
}

.editor-cell:focus {
  outline: none;
  border-color: var(--color-primary);
  background: var(--bg-surface);
}

.editor-cell.is-num {
  text-align: right;
  font-family: var(--font-mono);
}

.editor-cell.is-changed,
.form-input.is-changed {
  border-color: var(--color-info);
  background: var(--color-info-bg);
}

.editor-actions {
  white-space: nowrap;
  text-align: right;
}

.editor-icon-btn {
  display: inline-flex;
  padding: 4px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

.editor-icon-btn:hover {
  background: var(--bg-muted);
  color: var(--text-main);
}
</style>
