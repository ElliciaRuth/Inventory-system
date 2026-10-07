<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [Number, String], default: 0 },
  hint: { type: String, default: '' },
  variant: { type: String, default: 'primary' },
  icon: { type: String, default: '📦' },
  active: { type: Boolean, default: false },
})

defineEmits(['click'])
</script>

<template>
  <div
    class="stat-card"
    :class="[`variant-${variant}`, { active }]"
    @click="$emit('click')"
  >
    <div class="stat-card-header">
      <span class="stat-card-title">{{ title }}</span>
      <div class="stat-card-icon">
        <span>{{ icon }}</span>
      </div>
    </div>
    <div class="stat-card-value">{{ value }}</div>
    <div class="stat-card-hint">{{ hint }}</div>
  </div>
</template>
