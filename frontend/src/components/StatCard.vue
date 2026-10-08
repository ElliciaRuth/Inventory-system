<script setup>
defineProps({
  title: { type: String, required: true },
  value: { type: [Number, String], default: 0 },
  hint: { type: String, default: '' },
  variant: { type: String, default: 'primary' },
  icon: { type: [String, Object, Function], default: null },
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
      <div v-if="icon" class="stat-card-icon">
        <component :is="icon" v-if="typeof icon === 'object' || typeof icon === 'function'" :size="20" />
        <span v-else>{{ icon }}</span>
      </div>
    </div>
    <div class="stat-card-value">{{ value }}</div>
    <div class="stat-card-hint">{{ hint }}</div>
  </div>
</template>
