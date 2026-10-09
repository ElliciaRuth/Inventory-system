<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { Search, X, ChevronDown, Check, Loader2 } from 'lucide-vue-next'
import { barcodesApi } from '../api/reports'

// One bar that is both a dropdown and a search: open it to browse every item,
// or type to filter. v-model is the selected product_id.
const props = defineProps({
  items: { type: Array, default: () => [] },
  modelValue: { type: Number, default: 0 },
  placeholder: { type: String, default: 'Search, or scan a barcode…' },
  // USB / wireless scanners type the code and press Enter: text that matches no item is looked up as a batch barcode
  scanBarcodes: { type: Boolean, default: true },
})
// scanned: the batch found for a scanned barcode ({ product_id, copy_id, batch_no, … })
const emit = defineEmits(['update:modelValue', 'scanned', 'scan-error'])
const scanning = ref(false)

const query = ref('')
const open = ref(false)
const active = ref(0)
const input = ref(null)
const menu = ref(null)

const selected = computed(() => props.items.find((i) => Number(i.product_id) === props.modelValue) || null)

function meta(item) {
  return [item.archived_at ? 'Archived' : '', item.stock_no, item.unit].filter(Boolean).join(' · ')
}

const results = computed(() => {
  const words = query.value.toLowerCase().trim().split(/\s+/).filter(Boolean)
  if (!words.length) return props.items // empty search = the full dropdown list

  const q = words.join(' ')
  return props.items
    .map((item) => {
      const name = String(item.product || '').toLowerCase()
      const haystack = [name, item.stock_no, item.product_no, item.product_description, item.unit]
        .map((v) => String(v ?? '').toLowerCase())
        .join(' ')
      if (!words.every((w) => haystack.includes(w))) return null
      // Names starting with the query first, then names containing it, then other matches
      return { item, score: name.startsWith(q) ? 0 : name.includes(q) ? 1 : 2 }
    })
    .filter(Boolean)
    .sort((a, b) => a.score - b.score || String(a.item.product).localeCompare(String(b.item.product)))
    .map((r) => r.item)
})

watch(query, () => {
  active.value = 0
  open.value = true
})

function openMenu() {
  if (open.value) return
  open.value = true
  query.value = ''
  // Start on the selected item, like a native dropdown
  const index = props.items.findIndex((i) => Number(i.product_id) === props.modelValue)
  nextTick(() => {
    active.value = Math.max(0, index)
    scrollToActive()
  })
}

function closeMenu() {
  open.value = false
  query.value = ''
}

function toggle() {
  if (open.value) {
    closeMenu()
  } else {
    input.value?.focus()
    openMenu()
  }
}

function choose(item) {
  if (!item) return
  emit('update:modelValue', Number(item.product_id))
  closeMenu()
  input.value?.blur()
}

function scrollToActive() {
  nextTick(() => {
    menu.value?.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' })
  })
}

function onKeydown(event) {
  const count = results.value.length
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (!open.value) return openMenu()
    if (!count) return
    active.value = (active.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count
    scrollToActive()
  } else if (event.key === 'Enter') {
    const text = query.value.trim()
    if (props.scanBarcodes && text && !count) {
      event.preventDefault()
      lookupBarcode(text)
    } else if (open.value && count) {
      event.preventDefault()
      choose(results.value[active.value])
    }
  } else if (event.key === 'Escape') {
    closeMenu()
  }
}

async function lookupBarcode(value) {
  scanning.value = true
  try {
    const res = await barcodesApi.lookup(value)
    const item = props.items.find((i) => Number(i.product_id) === Number(res.data?.product_id))
    if (!item) {
      emit('scan-error', `Barcode ${value} belongs to ${res.data?.product || 'a product'} that is not in this list.`)
      return
    }
    choose(item)
    emit('scanned', res.data)
  } catch (err) {
    emit('scan-error', err.response?.data?.message || `No product or batch matches “${value}”.`)
  } finally {
    scanning.value = false
  }
}

// Label split so the matched text can be highlighted inline
function parts(text) {
  const value = String(text ?? '')
  const q = query.value.trim()
  const index = q ? value.toLowerCase().indexOf(q.toLowerCase()) : -1
  if (index < 0) return [{ text: value, hit: false }]
  return [
    { text: value.slice(0, index), hit: false },
    { text: value.slice(index, index + q.length), hit: true },
    { text: value.slice(index + q.length), hit: false },
  ].filter((p) => p.text)
}
</script>

<template>
  <div class="picker" :class="{ 'is-open': open }" @focusout="closeMenu">
    <div class="picker-bar" @mousedown.self.prevent="toggle">
      <Search :size="16" class="picker-icon" />

      <input
        ref="input"
        v-model="query"
        type="text"
        class="picker-input"
        :placeholder="selected ? '' : placeholder"
        autocomplete="off"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open"
        @focus="openMenu"
        @click="openMenu"
        @keydown="onKeydown"
      />

      <!-- The selected item shows in the bar until you start typing -->
      <div v-if="!query && selected" class="picker-value" :class="{ 'is-dim': open }" @mousedown.prevent="toggle">
        <span class="picker-no">#{{ selected.product_no }}</span>
        <span class="picker-value-name">{{ selected.product }}</span>
        <span v-if="meta(selected)" class="picker-value-meta">{{ meta(selected) }}</span>
      </div>

      <Loader2 v-if="scanning" :size="15" class="picker-spin" aria-label="Looking up barcode" />
      <button v-if="query" type="button" class="picker-btn" title="Clear search" @mousedown.prevent="query = ''">
        <X :size="15" />
      </button>
      <button type="button" class="picker-btn" :title="open ? 'Close list' : 'Show all items'" @mousedown.prevent="toggle">
        <ChevronDown :size="17" class="picker-chevron" />
      </button>
    </div>

    <ul v-if="open" ref="menu" class="picker-menu" role="listbox">
      <li v-if="query && results.length" class="picker-count">{{ results.length }} match{{ results.length === 1 ? '' : 'es' }}</li>
      <li
        v-for="(item, i) in results"
        :key="item.product_id"
        role="option"
        class="picker-option"
        :aria-selected="Number(item.product_id) === modelValue"
        :class="{ 'is-active': i === active, 'is-selected': Number(item.product_id) === modelValue }"
        @mousedown.prevent="choose(item)"
        @mousemove="active = i"
      >
        <span class="picker-no">#{{ item.product_no }}</span>
        <span class="picker-option-text">
          <span class="picker-option-name"><template v-for="(part, p) in parts(item.product)" :key="p"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
          <span v-if="meta(item)" class="picker-option-meta">{{ meta(item) }}</span>
        </span>
        <Check v-if="Number(item.product_id) === modelValue" :size="15" class="picker-check" />
      </li>
      <li v-if="!results.length" class="picker-empty">
        No items match “{{ query }}”.<template v-if="scanBarcodes"> Press Enter to look it up as a barcode.</template>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.picker-spin {
  color: var(--color-primary);
  animation: picker-spin 0.8s linear infinite;
}

@keyframes picker-spin {
  to { transform: rotate(360deg); }
}

.picker {
  position: relative;
  width: 100%;
}

.picker-bar {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.25rem;
  min-height: 44px;
  padding: 0 0.4rem 0 0.75rem;
  background: var(--bg-surface);
  border: 1.5px solid var(--border-subtle);
  border-radius: var(--radius-md);
  cursor: text;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.picker-bar:hover {
  border-color: var(--border-hover);
}

.picker.is-open .picker-bar,
.picker-bar:focus-within {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px var(--color-primary-glow);
}

.picker-icon {
  flex-shrink: 0;
  color: var(--text-muted);
}

.picker-input {
  flex: 1;
  min-width: 0;
  height: 40px;
  padding: 0 0.4rem;
  border: 0;
  outline: none;
  background: transparent;
  color: var(--text-main);
  font: inherit;
  font-size: 0.92rem;
}

/* Selected item drawn over the empty input */
.picker-value {
  position: absolute;
  left: 2.35rem;
  right: 2.6rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  min-width: 0;
  white-space: nowrap;
  cursor: pointer;
}

.picker-value.is-dim {
  opacity: 0.45;
}

.picker-value-name {
  font-weight: 700;
  color: var(--text-main);
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}

.picker-value-meta {
  font-size: 0.78rem;
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  flex-shrink: 1;
  min-width: 0;
}

.picker-no {
  flex-shrink: 0;
  font-family: var(--font-mono);
  font-size: 0.75rem;
  color: var(--text-muted);
}

.picker-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text-muted);
  cursor: pointer;
}

.picker-btn:hover {
  background: var(--bg-muted);
  color: var(--text-main);
}

.picker-chevron {
  transition: transform var(--transition-fast);
}

.picker.is-open .picker-chevron {
  transform: rotate(180deg);
}

.picker-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 40;
  margin: 0;
  padding: 0.35rem;
  list-style: none;
  max-height: 340px;
  overflow-y: auto;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-lg);
}

.picker-count {
  padding: 0.3rem 0.65rem 0.4rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.picker-option {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 0.65rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.picker-option.is-active {
  background: var(--bg-subtle);
}

.picker-option.is-selected {
  background: var(--color-primary-light);
}

.picker-option .picker-no {
  min-width: 2.4rem;
}

.picker-option-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.picker-option-name {
  font-weight: 600;
  color: var(--text-main);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.picker-option-name mark {
  padding: 0;
  border-radius: 3px;
  background: var(--color-accent-light);
  color: var(--color-primary);
  font-weight: 800;
}

.picker-option-meta {
  font-size: 0.75rem;
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.picker-check {
  flex-shrink: 0;
  color: var(--color-primary);
}

.picker-empty {
  padding: 0.75rem;
  color: var(--text-muted);
  font-size: 0.85rem;
}
</style>
