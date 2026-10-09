<script setup>
import { computed } from 'vue'

// Live "passwords match / do not match" note shown under a Confirm Password field.
// Give the confirm input :class="matchClass(password, confirm)" to colour its border.
const props = defineProps({
  password: { type: String, default: '' },
  confirm: { type: String, default: '' },
})

const state = computed(() => {
  if (!props.confirm) return ''
  return props.password === props.confirm ? 'match' : 'mismatch'
})
</script>

<script>
export function matchClass(password, confirm) {
  if (!confirm) return ''
  return password === confirm ? 'pw-input-match' : 'pw-input-mismatch'
}
</script>

<template>
  <small v-if="state" class="pw-match-hint" :class="`is-${state}`" aria-live="polite">
    {{ state === 'match' ? '✓ Passwords match.' : '✕ Passwords do not match.' }}
  </small>
</template>

<style scoped>
.pw-match-hint {
  display: block;
  margin-top: 6px;
  font-size: 12.5px;
  font-weight: 600;
}

.is-match {
  color: var(--color-success, #16a34a);
}

.is-mismatch {
  color: var(--color-danger, #dc2626);
}
</style>

<style>
/* Applied to the confirm input via matchClass() */
.pw-input-mismatch,
.pw-input-mismatch:focus {
  border-color: var(--color-danger, #dc2626) !important;
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-danger, #dc2626) 14%, transparent) !important;
}

.pw-input-match {
  border-color: var(--color-success, #16a34a) !important;
}
</style>
