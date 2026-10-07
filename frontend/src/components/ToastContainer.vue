<script setup>
import { toasts } from '../composables/useToast'
</script>

<template>
  <div class="toast-container" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast" :class="`toast-${t.type}`">
      {{ t.message }}
    </div>
  </div>
</template>
