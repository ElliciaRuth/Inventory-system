<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { AlertTriangle, CheckCircle2, HelpCircle, Info, ShieldAlert } from 'lucide-vue-next'
import { confirmState, settleConfirm } from '../composables/useConfirm'

const cancelBtn = ref(null)
const confirmBtn = ref(null)

const icon = computed(
  () => ({ danger: ShieldAlert, warning: AlertTriangle, success: CheckCircle2, info: Info }[confirmState.variant] || HelpCircle)
)

// Focus the safe choice for destructive actions, so a stray Enter cancels (a notice has only OK)
watch(
  () => confirmState.open,
  async (open) => {
    if (!open) return
    await nextTick()
    ;(confirmState.alert || confirmState.variant === 'primary' ? confirmBtn : cancelBtn).value?.focus()
  }
)

function onKeydown(e) {
  if (confirmState.open && e.key === 'Escape') settleConfirm(false)
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Transition name="cd-fade">
    <div v-if="confirmState.open" class="modal-backdrop cd-backdrop" @click.self="settleConfirm(false)">
      <div
        class="cd-card"
        :class="`cd-${confirmState.variant}`"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="cd-title"
        aria-describedby="cd-message"
      >
        <div class="cd-body">
          <span class="cd-icon"><component :is="icon" :size="26" /></span>
          <h2 id="cd-title" class="cd-title">{{ confirmState.title }}</h2>
          <p v-if="confirmState.message" id="cd-message" class="cd-message">{{ confirmState.message }}</p>
          <dl v-if="confirmState.details.length" class="cd-details">
            <div v-for="([label, value], i) in confirmState.details" :key="i" class="cd-detail">
              <dt>{{ label }}</dt>
              <dd>{{ value }}</dd>
            </div>
          </dl>
        </div>
        <div class="cd-actions">
          <button v-if="!confirmState.alert" ref="cancelBtn" type="button" class="btn btn-secondary cd-btn" @click="settleConfirm(false)">
            {{ confirmState.cancelText }}
          </button>
          <button ref="confirmBtn" type="button" class="btn cd-btn cd-confirm" @click="settleConfirm(true)">
            {{ confirmState.confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.cd-backdrop {
  z-index: 1300;
}

.cd-card {
  --cd-accent: var(--color-primary);
  --cd-accent-bg: var(--color-primary-light);
  width: 100%;
  max-width: 460px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-top: 4px solid var(--cd-accent);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-xl);
  overflow: hidden;
  animation: cd-pop 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}

.cd-danger {
  --cd-accent: var(--color-danger);
  --cd-accent-bg: var(--color-danger-bg);
}

.cd-warning {
  --cd-accent: var(--color-warning);
  --cd-accent-bg: var(--color-warning-bg);
}

.cd-success {
  --cd-accent: var(--color-success);
  --cd-accent-bg: var(--color-success-bg);
}

.cd-info {
  --cd-accent: var(--color-info);
  --cd-accent-bg: var(--color-info-bg);
}

.cd-body {
  padding: 28px 28px 20px;
  text-align: center;
}

.cd-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  margin-bottom: 14px;
  border-radius: 50%;
  background: var(--cd-accent-bg);
  color: var(--cd-accent);
  box-shadow: 0 0 0 6px color-mix(in srgb, var(--cd-accent-bg) 50%, transparent);
}

.cd-title {
  margin: 0 0 8px;
  font-family: var(--font-display);
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--text-main);
}

.cd-message {
  margin: 0;
  font-size: 14px;
  line-height: 1.55;
  color: var(--text-muted);
  overflow-wrap: anywhere;
}

/* What is being deleted / saved: label on the left, value on the right */
.cd-details {
  margin: 16px 0 0;
  padding: 4px 14px;
  border: 1px solid var(--border-subtle);
  border-left: 3px solid var(--cd-accent);
  border-radius: var(--radius-md);
  background: var(--bg-subtle);
  text-align: left;
}

.cd-detail {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 16px;
  padding: 7px 0;
  font-size: 13px;
}

.cd-detail + .cd-detail {
  border-top: 1px solid var(--border-subtle);
}

.cd-detail dt {
  flex-shrink: 0;
  color: var(--text-muted);
  font-weight: 600;
}

.cd-detail dd {
  margin: 0;
  color: var(--text-main);
  font-weight: 700;
  text-align: right;
  overflow-wrap: anywhere;
}

.cd-actions {
  display: flex;
  gap: 10px;
  padding: 16px 20px;
  background: var(--bg-subtle);
  border-top: 1px solid var(--border-subtle);
}

.cd-btn {
  flex: 1;
  min-height: 44px;
}

.cd-confirm {
  background: var(--cd-accent);
  color: #fff;
  box-shadow: 0 4px 12px color-mix(in srgb, var(--cd-accent) 30%, transparent);
}

.cd-confirm:hover {
  filter: brightness(1.08);
}

.cd-btn:focus-visible {
  outline: 2px solid var(--cd-accent);
  outline-offset: 2px;
}

.cd-fade-enter-active,
.cd-fade-leave-active {
  transition: opacity 0.18s ease;
}

.cd-fade-enter-from,
.cd-fade-leave-to {
  opacity: 0;
}

@keyframes cd-pop {
  from {
    opacity: 0;
    transform: translateY(12px) scale(0.96);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

/* Phones: stack the buttons, confirm on top within thumb reach */
@media (max-width: 420px) {
  .cd-body {
    padding: 24px 20px 16px;
  }
  .cd-actions {
    flex-direction: column-reverse;
  }
}
</style>
