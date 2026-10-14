<script setup>
// Save / Discard for inline editing. It sticks to the bottom of the screen while the page
// scrolls, so Save is always one click (or Ctrl+S) away, whatever row is being edited.
import { computed, onMounted, onUnmounted } from 'vue'
import { AlertTriangle, Loader2, Save, Undo2 } from 'lucide-vue-next'

const props = defineProps({
  count: { type: Number, default: 0 },
  saving: { type: Boolean, default: false },
  // A problem to fix before saving (shown instead of the count)
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
})

const emit = defineEmits(['save', 'discard'])

const label = computed(() => {
  if (props.count === 0) return 'No unsaved changes'
  return props.count === 1 ? '1 unsaved change' : `${props.count} unsaved changes`
})

function onKeydown(event) {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    if (props.count > 0 && !props.saving) emit('save')
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="unsaved-bar" :class="{ 'has-changes': count > 0, 'has-error': !!error }" role="region" aria-label="Save changes">
    <div class="unsaved-text" aria-live="polite">
      <AlertTriangle v-if="error" :size="16" class="unsaved-icon" />
      <span v-if="count > 0 && !error" class="unsaved-dot" aria-hidden="true"></span>
      <div>
        <strong>{{ error || label }}</strong>
        <small v-if="hint && !error">{{ hint }}</small>
      </div>
    </div>
    <div class="unsaved-actions">
      <button type="button" class="btn btn-secondary btn-sm" :disabled="count === 0 || saving" @click="emit('discard')">
        <Undo2 :size="14" /> Discard
      </button>
      <button type="button" class="btn btn-primary btn-sm" :disabled="count === 0 || saving" title="Save changes (Ctrl+S)" @click="emit('save')">
        <component :is="saving ? Loader2 : Save" :size="14" :class="{ 'is-spinning': saving }" />
        {{ saving ? 'Saving…' : 'Save changes' }}
        <kbd v-if="!saving">Ctrl+S</kbd>
      </button>
    </div>
  </div>
</template>

<style scoped>
.unsaved-bar {
  position: sticky;
  bottom: 12px;
  z-index: 40;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
  margin-top: 1rem;
  padding: 0.7rem 0.9rem 0.7rem 1.1rem;
  border: 1px solid var(--border-hover);
  border-radius: var(--radius-lg);
  background: var(--bg-surface);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.16);
}

.unsaved-bar.has-changes {
  border-color: var(--color-warning);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.16), 0 0 0 3px var(--color-warning-bg);
}

.unsaved-bar.has-error {
  border-color: var(--color-danger);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.16), 0 0 0 3px var(--color-danger-bg);
}

.unsaved-text {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
  font-size: 0.875rem;
  color: var(--text-main);
}

.unsaved-text div {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.unsaved-text small {
  font-size: 0.75rem;
  color: var(--text-muted);
}

.has-error .unsaved-text strong,
.unsaved-icon {
  color: var(--color-danger);
}

.unsaved-icon {
  flex-shrink: 0;
}

.unsaved-dot {
  width: 9px;
  height: 9px;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-warning);
}

.unsaved-actions {
  display: flex;
  gap: 0.5rem;
  margin-left: auto;
}

.unsaved-actions .btn {
  gap: 6px;
}

kbd {
  padding: 0 5px;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.18);
  font-family: var(--font-mono);
  font-size: 0.68rem;
  font-weight: 500;
}

.is-spinning {
  animation: unsaved-spin 0.8s linear infinite;
}

@keyframes unsaved-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (max-width: 480px) {
  .unsaved-actions {
    width: 100%;
  }
  .unsaved-actions .btn {
    flex: 1;
    justify-content: center;
  }
  kbd {
    display: none;
  }
}
</style>
