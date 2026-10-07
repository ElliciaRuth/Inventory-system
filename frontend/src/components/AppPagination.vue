<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
    default: 1,
  },
  totalPages: {
    type: Number,
    required: true,
    default: 1,
  },
  totalItems: {
    type: Number,
    default: null,
  },
  pageSize: {
    type: Number,
    default: 10,
  },
  pageSizeOptions: {
    type: Array,
    default: () => [5, 10, 25, 50, 100],
  },
  showInfo: {
    type: Boolean,
    default: true,
  },
  itemName: {
    type: String,
    default: 'items',
  },
})

const emit = defineEmits([
  'update:currentPage',
  'update:pageSize',
  'page-change',
  'page-size-change',
])

const gotoVal = ref('')

// Compute items for the smart ellipsis pagination: < 1 2 3 ... x >
const visiblePages = computed(() => {
  const total = Math.max(1, props.totalPages)
  const current = Math.min(Math.max(1, props.currentPage), total)

  // When total pages is 7 or fewer, show all page numbers
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  // Matches exact pattern: 1 2 3 ... x near the start
  if (current <= 2) {
    return [1, 2, 3, '...', total]
  }

  if (current === 3) {
    return [1, 2, 3, 4, '...', total]
  }

  // Near the end: 1 ... (total-2) (total-1) total
  if (current >= total - 1) {
    return [1, '...', total - 2, total - 1, total]
  }

  if (current === total - 2) {
    return [1, '...', total - 3, total - 2, total - 1, total]
  }

  // Middle: 1 ... (current-1) current (current+1) ... total
  return [1, '...', current - 1, current, current + 1, '...', total]
})

const rangeStart = computed(() => {
  if (props.totalItems === 0) return 0
  return (props.currentPage - 1) * props.pageSize + 1
})

const rangeEnd = computed(() => {
  if (props.totalItems === null || props.totalItems === undefined) return 0
  return Math.min(props.currentPage * props.pageSize, props.totalItems)
})

function setPage(page) {
  const target = Math.min(Math.max(1, page), Math.max(1, props.totalPages))
  if (target !== props.currentPage) {
    emit('update:currentPage', target)
    emit('page-change', target)
  }
}

function onPageSizeChange(newSize) {
  const sizeNum = Number(newSize)
  emit('update:pageSize', sizeNum)
  emit('page-size-change', sizeNum)
  // Reset to first page when page size changes
  emit('update:currentPage', 1)
  emit('page-change', 1)
}

function submitGoto() {
  if (!gotoVal.value) return
  const val = parseInt(gotoVal.value, 10)
  if (!isNaN(val)) {
    const total = Math.max(1, props.totalPages)
    const target = Math.min(Math.max(1, val), total)
    setPage(target)
  }
  gotoVal.value = ''
}
</script>

<template>
  <div class="app-pagination-container">
    <!-- Left: Informative record range (optional) -->
    <div v-if="showInfo" class="app-pagination-info">
      <template v-if="totalItems !== null && totalItems !== undefined">
        Showing <strong>{{ rangeStart }}</strong> to <strong>{{ rangeEnd }}</strong> of
        <strong>{{ totalItems }}</strong> {{ itemName }}
      </template>
      <template v-else>
        Page <strong>{{ currentPage }}</strong> of <strong>{{ Math.max(1, totalPages) }}</strong>
      </template>
    </div>

    <!-- Right: Requested pagination controls (< 1 2 3 ... x > Page items : Go to : ) -->
    <div class="app-pagination-controls">
      <!-- Previous Button (<) -->
      <button
        type="button"
        class="pagination-btn page-nav-btn"
        :disabled="currentPage <= 1"
        title="Previous Page"
        @click="setPage(currentPage - 1)"
      >
        &lt;
      </button>

      <!-- Page Numbers & Ellipsis (1 2 3 ... x) -->
      <div class="page-numbers-group">
        <template v-for="(p, idx) in visiblePages" :key="idx">
          <span v-if="p === '...'" class="pagination-ellipsis">...</span>
          <button
            v-else
            type="button"
            class="pagination-btn page-number-btn"
            :class="{ 'is-active': p === currentPage }"
            @click="setPage(p)"
          >
            {{ p }}
          </button>
        </template>
      </div>

      <!-- Next Button (>) -->
      <button
        type="button"
        class="pagination-btn page-nav-btn"
        :disabled="currentPage >= totalPages"
        title="Next Page"
        @click="setPage(currentPage + 1)"
      >
        &gt;
      </button>

      <!-- Page items : [Selector] -->
      <div class="page-items-selector">
        <label class="page-items-label">Page items :</label>
        <select
          :value="pageSize"
          class="page-items-select"
          @change="onPageSizeChange($event.target.value)"
        >
          <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">
            {{ opt }}
          </option>
        </select>
      </div>

      <!-- Go to : [Input] -->
      <div class="page-goto-group">
        <label class="page-goto-label">Go to :</label>
        <div class="page-goto-input-wrapper">
          <input
            v-model="gotoVal"
            type="number"
            min="1"
            :max="Math.max(1, totalPages)"
            class="page-goto-input"
            :placeholder="currentPage"
            @keydown.enter.prevent="submitGoto"
          />
          <button
            type="button"
            class="page-goto-btn"
            title="Jump to page"
            @click="submitGoto"
          >
            Go
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.app-pagination-container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  padding: 12px 18px;
  background: var(--bg-subtle, #fafafa);
  border-top: 1px solid var(--border-subtle, #e2e8f0);
  border-radius: 0 0 var(--radius-lg, 16px) var(--radius-lg, 16px);
  width: 100%;
  box-sizing: border-box;
}

.app-pagination-info {
  font-size: 13.5px;
  color: var(--text-muted, #64748b);
}

.app-pagination-info strong {
  color: var(--text-main, #0f172a);
  font-weight: 700;
}

.app-pagination-controls {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.page-numbers-group {
  display: flex;
  align-items: center;
  gap: 4px;
}

.pagination-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 34px;
  height: 34px;
  padding: 0 8px;
  background: var(--bg-surface, #ffffff);
  border: 1px solid var(--border-subtle, #cbd5e1);
  border-radius: 8px;
  color: var(--text-main, #0f172a);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
  user-select: none;
}

.pagination-btn:hover:not(:disabled) {
  border-color: var(--color-primary, #0f766e);
  color: var(--color-primary, #0f766e);
  background: var(--bg-muted, #f1f5f9);
}

.pagination-btn.is-active {
  background: var(--color-primary, #0f766e) !important;
  border-color: var(--color-primary, #0f766e) !important;
  color: #ffffff !important;
  font-weight: 700;
  box-shadow: 0 2px 8px var(--color-primary-glow, rgba(15, 118, 110, 0.3));
}

.pagination-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.pagination-ellipsis {
  padding: 0 4px;
  color: var(--text-muted, #64748b);
  font-weight: 700;
  font-size: 13px;
  user-select: none;
}

.page-items-selector {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: 4px;
}

.page-items-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted, #64748b);
  white-space: nowrap;
}

.page-items-select {
  height: 34px;
  padding: 0 10px;
  background: var(--bg-surface, #ffffff);
  border: 1px solid var(--border-subtle, #cbd5e1);
  border-radius: 8px;
  color: var(--text-main, #0f172a);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  outline: none;
  transition: all var(--transition-fast, 0.15s ease);
}

.page-items-select:focus {
  border-color: var(--border-focus, #0f766e);
  box-shadow: 0 0 0 3px var(--color-primary-light, rgba(15, 118, 110, 0.15));
}

.page-goto-group {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: 4px;
}

.page-goto-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted, #64748b);
  white-space: nowrap;
}

.page-goto-input-wrapper {
  display: flex;
  align-items: center;
  gap: 4px;
}

.page-goto-input {
  width: 52px;
  height: 34px;
  text-align: center;
  background: var(--bg-surface, #ffffff);
  border: 1px solid var(--border-subtle, #cbd5e1);
  border-radius: 8px;
  color: var(--text-main, #0f172a);
  font-size: 13px;
  font-weight: 600;
  outline: none;
  box-sizing: border-box;
  transition: all var(--transition-fast, 0.15s ease);
}

.page-goto-input:focus {
  border-color: var(--border-focus, #0f766e);
  box-shadow: 0 0 0 3px var(--color-primary-light, rgba(15, 118, 110, 0.15));
}

/* Remove number input spin buttons for cleaner UI */
.page-goto-input::-webkit-outer-spin-button,
.page-goto-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.page-goto-input[type='number'] {
  -moz-appearance: textfield;
}

.page-goto-btn {
  height: 34px;
  padding: 0 10px;
  background: var(--color-primary, #0f766e);
  color: #ffffff;
  border: none;
  border-radius: 8px;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  transition: all var(--transition-fast, 0.15s ease);
}

.page-goto-btn:hover {
  background: var(--color-primary-hover, #115e59);
  transform: translateY(-1px);
}
</style>
