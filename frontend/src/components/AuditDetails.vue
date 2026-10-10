<script setup>
import { computed } from 'vue'
import { ArrowRight, Hash, Clock, Monitor, Tag } from 'lucide-vue-next'

// Readable view of an audit entry's details: a before → after table for changes,
// labelled values for everything else, and the entry's own facts underneath.
const props = defineProps({
  log: { type: Object, required: true },
  tone: { type: String, default: 'info' },
  when: { type: Function, required: true },
  ipLabel: { type: Function, required: true },
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
}

function label(key) {
  if (LABELS[key]) return LABELS[key]
  const words = String(key).replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
const isScalar = (v) => v === null || ['string', 'number', 'boolean'].includes(typeof v)

function format(key, value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'number' || /^\d+(\.\d+)?$/.test(String(value))) {
    if (/minutes?$/.test(key)) return `${value} min`
    if (/days$/.test(key)) return `${value} day${Number(value) === 1 ? '' : 's'}`
  }
  return String(value)
}

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
    .map(([key, value]) => {
      if (isPlainObject(value) && 'before' in value && 'after' in value && isScalar(value.before) && isScalar(value.after)) {
        return { key, label: label(key), kind: 'pair', before: format(key, value.before), after: format(key, value.after) }
      }
      if (Array.isArray(value) && value.every(isScalar)) {
        return { key, label: label(key), kind: 'chips', items: value.map((v) => format(key, v)) }
      }
      if (isPlainObject(value) && Object.values(value).every(isScalar)) {
        return { key, label: label(key), kind: 'list', items: Object.entries(value).map(([k, v]) => ({ label: label(k), value: format(k, v) })) }
      }
      if (!isScalar(value)) {
        return { key, label: label(key), kind: 'json', value: JSON.stringify(value, null, 2) }
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
        <div v-for="f in fields" :key="f.key" class="ad-field" :class="{ 'is-wide': f.kind === 'json' || f.kind === 'list' }">
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
          <dd v-else-if="f.kind === 'json'"><pre class="ad-json">{{ f.value }}</pre></dd>
          <dd v-else>{{ f.value }}</dd>
        </div>
      </dl>
    </section>

    <!-- The entry itself -->
    <footer class="ad-meta">
      <span><Hash :size="12" /> Entry {{ log.audit_id }}</span>
      <span><Tag :size="12" /> <code>{{ log.action }}</code></span>
      <span><Clock :size="12" /> {{ when(log.created_at) }}</span>
      <span v-if="log.ip_address"><Monitor :size="12" /> {{ ipLabel(log.ip_address) }}</span>
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

.ad-json {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 0.78rem;
  font-weight: 400;
  white-space: pre-wrap;
  color: var(--text-muted);
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
.ad-meta code {
  font-family: var(--font-mono);
  font-size: 0.74rem;
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
