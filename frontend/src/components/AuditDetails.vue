<script setup>
import { computed } from 'vue'
import { ArrowRight, Hash, Clock, Tag } from 'lucide-vue-next'

// Readable view of an audit entry's details: a before → after table for changes,
// labelled values for everything else, and the entry's own facts underneath.
const props = defineProps({
  log: { type: Object, required: true },
  tone: { type: String, default: 'info' },
  when: { type: Function, required: true },
})

// Labels that read better than the field name turned into words
const LABELS = {
  attempts_left: 'Attempts left',
  locked_minutes: 'Locked for',
  minutes_left: 'Time left',
  username_tried: 'Username tried',
  address_in_use: 'Address already in use',
  safety_backup: 'Safety backup',
  batch_qty: 'Batch quantity',
  batch_no: 'Batch no.',
  stock_after: 'Stock after',
  reason_was: 'Previous reason',
  expiry_warning_days: 'Expiry warning',
  expiry_danger_days: 'Expiry danger',
  user_office_id: 'Office ID',
  request_id: 'Request ID',
  transaction_id: 'Transaction ID',
  borrow_id: 'Borrow ID',
  pending_setup: 'Pending setup',
  archived_at: 'Archived at',
  protected: 'Password-protected',
  category_changes: 'Category changes',
  type_overrides: 'Category changes',
  qty: 'Quantity',
  stock_no: 'Stock No.',
  stock_before: 'Stock before',
  sub_product: 'Sub-product',
  date_received: 'Date received',
  due_date: 'Due date',
  borrow_notes: 'Notes',
  borrow_settled: 'Borrow settled',
  usage: 'Usage',
  technical_details: 'Technical details (for IT)',
  bytes_sent: 'Size sent',
  limit_bytes: 'Size limit',
  route: 'Page address',
  method: 'Request type',
  bucket: 'Limit',
  expiration_date: 'Expiry date',
  manufacturing_date: 'Manufacturing date',
  unit_cost: 'Unit cost',
  copy_id: 'Sub-product ID',
}

// Category changes made in an import preview: [{ product, unit, from, to }].
// Older entries stored the raw product keys ("office|name|unit|size|detected type" → chosen).
function categoryRows(key, value) {
  if (key === 'category_changes' && Array.isArray(value)) {
    return value.filter(isPlainObject).map((c) => ({ product: c.product, unit: c.unit, from: c.from, to: c.to }))
  }
  if (key === 'type_overrides' && isPlainObject(value)) {
    return Object.entries(value)
      .map(([productKey, chosen]) => {
        const [, name = '', unit = '', , detected = ''] = String(productKey).split('|')
        return { product: name.replace(/\b\w/g, (c) => c.toUpperCase()), unit, from: detected, to: String(chosen ?? '').trim() }
      })
      .filter((c) => c.to && c.to.toLowerCase() !== c.from.toLowerCase())
  }
  return null
}

function label(key) {
  if (LABELS[key]) return LABELS[key]
  const words = String(key).replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
const isScalar = (v) => v === null || ['string', 'number', 'boolean'].includes(typeof v)

const isEmpty = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)

// "2026-11-06" or "2026-11-06 09:19:10" → "Nov 6, 2026" (with the time when there is one)
function formatDate(value) {
  const m = String(value).match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?$/)
  if (!m) return null
  const d = new Date(`${m[1]}T${m[2] || '00:00'}:00`)
  if (Number.isNaN(d.getTime())) return null
  const day = d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  return m[2] ? `${day}, ${d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : day
}

function format(key, value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'number' || /^\d+(\.\d+)?$/.test(String(value))) {
    if (/minutes?$/.test(key)) return `${value} min`
    if (/days$/.test(key)) return `${value} day${Number(value) === 1 ? '' : 's'}`
    if (/bytes$/.test(key)) {
      const n = Number(value)
      return n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : n >= 1024 ? `${Math.round(n / 1024)} KB` : `${n} bytes`
    }
    if (/(cost|price|amount)$/.test(key)) return `₱${Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  }
  return formatDate(value) ?? String(value)
}

// Anything else nested (objects in objects, lists of mixed things) as plain "label: value" lines,
// with the parent's name in front: never shown as code
function flatten(value, prefix = '') {
  if (isScalar(value)) return [{ label: prefix || 'Value', value: format(prefix, value) }]
  if (Array.isArray(value)) {
    if (value.every(isScalar)) return [{ label: prefix || 'Values', value: value.map((v) => format(prefix, v)).join(', ') || '—' }]
    return value.flatMap((item, i) => flatten(item, `${prefix ? `${prefix} ` : ''}#${i + 1}`))
  }
  return Object.entries(value)
    .filter(([, v]) => !isEmpty(v))
    .flatMap(([k, v]) => {
      const name = label(k)
      return isScalar(v) ? [{ label: prefix ? `${prefix} › ${name}` : name, value: format(k, v) }] : flatten(v, prefix ? `${prefix} › ${name}` : name)
    })
}

// A list of records with plain values (batches taken, rows imported…) reads as a small table.
// An "x_id" column is left out when the same thing also has a readable name or number.
function recordTable(list) {
  const keys = [...new Set(list.flatMap((row) => Object.keys(row)))]
  const shown = keys.filter((k) => {
    const base = k.replace(/_id$/, '')
    if (base === k) return true
    return !keys.some((other) => other !== k && [base, `${base}_no`, `${base}_name`, `${base}_number`].includes(other))
  })
  // Columns that are empty in every row add nothing
  const columns = shown.filter((k) => list.some((row) => !isEmpty(row[k])))
  return {
    columns: columns.map((k) => ({ key: k, label: label(k), numeric: list.every((row) => isEmpty(row[k]) || typeof row[k] === 'number') })),
    rows: list.map((row) => Object.fromEntries(columns.map((k) => [k, format(k, row[k])]))),
  }
}

// "stock.adjust_out" → "Stock › Adjust out"
const actionLabel = computed(() =>
  String(props.log.action || '')
    .split('.')
    .map((part) => {
      const words = part.replace(/_/g, ' ').trim()
      return words.charAt(0).toUpperCase() + words.slice(1)
    })
    .join(' › ')
)

const details = computed(() => (isPlainObject(props.log.details) ? props.log.details : { value: props.log.details }))

// Rows for "before" / "after" objects (a record that was edited, or created with "after" only)
const changeRows = computed(() => {
  const before = isPlainObject(details.value.before) ? details.value.before : null
  const after = isPlainObject(details.value.after) ? details.value.after : null
  if (!before && !after) return []
  const keys = [...new Set([...Object.keys(before || {}), ...Object.keys(after || {})])]
  return keys.map((key) => {
    const was = before?.[key]
    const now = after?.[key]
    return {
      key,
      label: label(key),
      before: before ? format(key, isScalar(was) ? was : JSON.stringify(was)) : null,
      after: after ? format(key, isScalar(now) ? now : JSON.stringify(now)) : null,
      changed: !before || !after || JSON.stringify(was) !== JSON.stringify(now),
    }
  })
})
const hasBefore = computed(() => isPlainObject(details.value.before))
const hasAfter = computed(() => isPlainObject(details.value.after))

// Everything else, as label → value. Small { before, after } pairs read as "a → b".
const fields = computed(() =>
  Object.entries(details.value)
    .filter(([key]) => !(key === 'before' && hasBefore.value) && !(key === 'after' && hasAfter.value))
    // Nothing recorded for it (e.g. no borrow behind a stock movement): leave it out
    .filter(([key, value]) => !isEmpty(value) || key === 'category_changes' || key === 'type_overrides')
    .map(([key, value]) => {
      const categories = categoryRows(key, value)
      if (categories) {
        return { key, label: label(key), kind: 'categories', items: categories }
      }
      if (isPlainObject(value) && 'before' in value && 'after' in value && isScalar(value.before) && isScalar(value.after)) {
        return { key, label: label(key), kind: 'pair', before: format(key, value.before), after: format(key, value.after) }
      }
      if (Array.isArray(value) && value.every(isScalar)) {
        return { key, label: label(key), kind: 'chips', items: value.map((v) => format(key, v)) }
      }
      if (isPlainObject(value) && Object.values(value).every(isScalar)) {
        return { key, label: label(key), kind: 'list', items: Object.entries(value).map(([k, v]) => ({ label: label(k), value: format(k, v) })) }
      }
      if (Array.isArray(value) && value.length && value.every((row) => isPlainObject(row) && Object.values(row).every(isScalar))) {
        return { key, label: label(key), kind: 'records', ...recordTable(value) }
      }
      if (!isScalar(value)) {
        return { key, label: label(key), kind: 'list', items: flatten(value) }
      }
      return { key, label: label(key), kind: 'text', value: format(key, value) }
    })
)
</script>

<template>
  <div class="ad-card" :class="`is-${tone}`">
    <!-- Changes -->
    <section v-if="changeRows.length" class="ad-section">
      <h4 class="ad-heading">{{ hasBefore && hasAfter ? 'What changed' : hasAfter ? 'Values saved' : 'Values removed' }}</h4>
      <div class="ad-changes" :class="{ 'is-single': !(hasBefore && hasAfter) }">
        <div class="ad-changes-head">
          <span>Field</span>
          <span v-if="hasBefore">{{ hasAfter ? 'Before' : 'Value' }}</span>
          <span v-if="hasAfter">{{ hasBefore ? 'After' : 'Value' }}</span>
        </div>
        <div v-for="row in changeRows" :key="row.key" class="ad-change" :class="{ 'is-same': !row.changed }">
          <span class="ad-change-label">{{ row.label }}</span>
          <span v-if="hasBefore" class="ad-change-before" :class="{ 'is-old': row.changed && hasAfter }">{{ row.before }}</span>
          <span v-if="hasAfter" class="ad-change-after" :class="{ 'is-new': row.changed && hasBefore }">{{ row.after }}</span>
        </div>
      </div>
    </section>

    <!-- Other details -->
    <section v-if="fields.length" class="ad-section">
      <h4 class="ad-heading">Details</h4>
      <dl class="ad-fields">
        <div v-for="f in fields" :key="f.key" class="ad-field" :class="{ 'is-wide': ['records', 'list', 'categories'].includes(f.kind) || f.key === 'technical_details' }">
          <dt>{{ f.label }}</dt>
          <dd v-if="f.kind === 'pair'" class="ad-pair">
            <span class="ad-pair-old">{{ f.before }}</span>
            <ArrowRight :size="13" />
            <span class="ad-pair-new">{{ f.after }}</span>
          </dd>
          <dd v-else-if="f.kind === 'chips'" class="ad-chips">
            <span v-for="(item, i) in f.items" :key="i" class="ad-chip">{{ item }}</span>
            <span v-if="!f.items.length" class="ad-none">None</span>
          </dd>
          <dd v-else-if="f.kind === 'list'" class="ad-sublist">
            <span v-for="item in f.items" :key="item.label"><em>{{ item.label }}</em> {{ item.value }}</span>
          </dd>
          <dd v-else-if="f.kind === 'categories'">
            <span v-if="!f.items.length" class="ad-none">None: every product kept its detected category</span>
            <div v-else class="ad-cats">
              <div class="ad-cats-head">
                <span>Product</span>
                <span>Unit</span>
                <span>Category</span>
              </div>
              <div v-for="(c, i) in f.items" :key="i" class="ad-cat">
                <span class="ad-cat-product">{{ c.product || '—' }}</span>
                <span class="ad-cat-unit">{{ c.unit || '—' }}</span>
                <span class="ad-pair">
                  <span class="ad-pair-old">{{ c.from || '—' }}</span>
                  <ArrowRight :size="13" />
                  <span class="ad-pair-new">{{ c.to }}</span>
                </span>
              </div>
            </div>
          </dd>
          <dd v-else-if="f.kind === 'records'">
            <div class="ad-records-wrap">
              <table class="ad-records">
                <thead>
                  <tr>
                    <th v-for="c in f.columns" :key="c.key" :class="{ 'is-num': c.numeric }">{{ c.label }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, i) in f.rows" :key="i">
                    <td v-for="c in f.columns" :key="c.key" :class="{ 'is-num': c.numeric }">{{ row[c.key] }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </dd>
          <dd v-else>{{ f.value }}</dd>
        </div>
      </dl>
    </section>

    <!-- The entry itself -->
    <footer class="ad-meta">
      <span><Hash :size="12" /> Entry {{ log.audit_id }}</span>
      <span :title="log.action"><Tag :size="12" /> {{ actionLabel }}</span>
      <span><Clock :size="12" /> {{ when(log.created_at) }}</span>
    </footer>
  </div>
</template>

<style scoped>
.ad-card {
  --ad-accent: var(--color-info);
  position: relative;
  margin: 0.15rem 0 0.35rem;
  padding: 1.1rem 1.25rem 0.9rem 1.4rem;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.ad-card::before {
  content: '';
  position: absolute;
  inset: 0 auto 0 0;
  width: 4px;
  background: var(--ad-accent);
}
.ad-card.is-danger { --ad-accent: var(--color-danger); }
.ad-card.is-warning { --ad-accent: var(--color-warning); }
.ad-card.is-success { --ad-accent: var(--color-success); }

.ad-section + .ad-section {
  margin-top: 1rem;
}
.ad-heading {
  font-family: var(--font-sans);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 0.55rem;
}

/* ── Labelled values ── */
.ad-fields {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 0.6rem;
}
.ad-field {
  min-width: 0;
  padding: 0.6rem 0.8rem;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
}
.ad-field.is-wide {
  grid-column: 1 / -1;
}
.ad-field dt {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--text-muted);
  margin-bottom: 0.2rem;
}
.ad-field dd {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-main);
  overflow-wrap: anywhere;
}

.ad-pair {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.ad-pair svg {
  color: var(--text-subtle);
}
.ad-pair-old {
  color: var(--text-muted);
  text-decoration: line-through;
  text-decoration-color: color-mix(in srgb, var(--color-danger) 60%, transparent);
}
.ad-pair-new {
  color: var(--color-success);
}

.ad-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
}
.ad-chip {
  padding: 0.1rem 0.55rem;
  border-radius: var(--radius-full);
  background: var(--color-primary-light);
  color: var(--color-primary);
  font-size: 0.78rem;
}
.ad-none {
  color: var(--text-subtle);
  font-weight: 500;
}

.ad-sublist {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1.1rem;
}
.ad-sublist em {
  font-style: normal;
  font-weight: 500;
  color: var(--text-muted);
  margin-right: 0.25rem;
}

/* Category changes: one row per product */
.ad-cats {
  display: grid;
  margin-top: 0.25rem;
  max-height: 320px;
  overflow-y: auto;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
}
.ad-cats-head,
.ad-cat {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 0.8fr) minmax(0, 2fr);
  gap: 0.75rem;
  align-items: center;
  padding: 0.45rem 0.75rem;
}
.ad-cats-head {
  position: sticky;
  top: 0;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-subtle);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.ad-cat {
  font-size: 0.82rem;
}
.ad-cat + .ad-cat {
  border-top: 1px solid var(--border-subtle);
}
.ad-cat-product {
  font-weight: 600;
  color: var(--text-main);
  overflow-wrap: anywhere;
}
.ad-cat-unit {
  color: var(--text-muted);
}
@media (max-width: 640px) {
  .ad-cats-head {
    display: none;
  }
  .ad-cat {
    grid-template-columns: 1fr auto;
  }
  .ad-cat .ad-pair {
    grid-column: 1 / -1;
  }
}

/* A list of records (batches taken, …) as a small table */
.ad-records-wrap {
  margin-top: 0.25rem;
  max-height: 320px;
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
}
.ad-records {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.84rem;
}
.ad-records th,
.ad-records td {
  padding: 0.45rem 0.75rem;
  text-align: left;
  white-space: nowrap;
}
.ad-records th {
  position: sticky;
  top: 0;
  background: var(--bg-subtle);
  border-bottom: 1px solid var(--border-subtle);
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.ad-records td {
  font-weight: 600;
  color: var(--text-main);
}
.ad-records tr + tr td {
  border-top: 1px solid var(--border-subtle);
}
.ad-records .is-num {
  text-align: right;
}

/* ── Before / after ── */
.ad-changes {
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  overflow: hidden;
  font-size: 0.86rem;
}
.ad-changes-head,
.ad-change {
  display: grid;
  grid-template-columns: minmax(120px, 0.8fr) 1fr 1fr;
  gap: 0.75rem;
  padding: 0.5rem 0.85rem;
}
.ad-changes.is-single .ad-changes-head,
.ad-changes.is-single .ad-change {
  grid-template-columns: minmax(120px, 0.8fr) 2fr;
}
.ad-changes-head {
  background: var(--bg-subtle);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.ad-change {
  border-top: 1px solid var(--border-subtle);
  overflow-wrap: anywhere;
}
.ad-change.is-same {
  color: var(--text-subtle);
}
.ad-change-label {
  font-weight: 600;
  color: var(--text-muted);
}
.ad-change-before.is-old {
  color: var(--color-danger);
  text-decoration: line-through;
  text-decoration-color: color-mix(in srgb, var(--color-danger) 50%, transparent);
}
.ad-change-after.is-new {
  color: var(--color-success);
  font-weight: 600;
}

/* ── Entry facts ── */
.ad-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1.25rem;
  margin-top: 0.95rem;
  padding-top: 0.75rem;
  border-top: 1px dashed var(--border-subtle);
  font-size: 0.76rem;
  color: var(--text-subtle);
}
.ad-meta span {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

@media (max-width: 640px) {
  .ad-changes-head {
    display: none;
  }
  .ad-change,
  .ad-changes.is-single .ad-change {
    grid-template-columns: 1fr;
    gap: 0.15rem;
  }
}
</style>
