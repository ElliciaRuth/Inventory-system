<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useAuthStore } from '../stores/authStore'
import bsuLogo from '../assets/images/bsu-logo.png'
import bakeryLogo from '../assets/images/bakery-logo.png'
import fpcLogo from '../assets/images/fpc-logo.png'

const emit = defineEmits(['done'])

const authStore = useAuthStore()

const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
// How long the splash stays up before fading out
const DURATION = reducedMotion ? 1200 : 2600

const hour = new Date().getHours()
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
const message = authStore.isAuthenticated ? `Welcome back, ${authStore.userName}` : 'Welcome'

const visible = ref(true)
let timer = null

function dismiss() {
  clearTimeout(timer)
  visible.value = false
}

function onKey(e) {
  if (e.key === 'Escape' || e.key === 'Enter' || e.key === ' ') dismiss()
}

onMounted(() => {
  timer = setTimeout(dismiss, DURATION)
  window.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <!-- No fade-in: the screen is solid from the first frame, so the page behind never shows
       through. The logos and text animate in on their own; only the exit fades. -->
  <Transition name="splash" @after-leave="emit('done')">
    <div
      v-if="visible"
      class="splash-screen"
      :style="{ '--splash-duration': `${DURATION}ms` }"
      role="status"
      aria-live="polite"
      title="Click to skip"
      @click="dismiss"
    >
      <div class="splash-content">
        <div class="splash-logos">
          <img :src="bakeryLogo" alt="BSU Bakery Project Logo" class="splash-logo splash-logo-left" />
          <img :src="bsuLogo" alt="Benguet State University Official Seal" class="splash-logo splash-logo-main" />
          <img :src="fpcLogo" alt="BSU Food Processing Center Logo" class="splash-logo splash-logo-right" />
        </div>

        <p class="splash-eyebrow">{{ greeting }}</p>
        <h1 class="splash-title">BSU Integrated Inventory<br />Monitoring System</h1>
        <p class="splash-message">{{ message }}</p>

        <div class="splash-progress"><span></span></div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.splash-screen {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  cursor: pointer;
  color: #fff;
  text-align: center;
  background:
    radial-gradient(circle at 100% 0, #99f6e459, #0000 40%),
    radial-gradient(circle at 0 100%, #2dd4bf33, #0000 45%),
    linear-gradient(145deg, #0f3d3e, #115e59 62%, #0f766e);
}
html[data-theme='dark'] .splash-screen {
  background:
    radial-gradient(circle at 100% 0, #2dd4bf38, #0000 40%),
    linear-gradient(135deg, #071316 0%, #12383a 58%, #0f766e 100%);
}
html[data-theme='bsu'] .splash-screen {
  background:
    radial-gradient(circle at 100% 0, #e6d6283d, #0000 40%),
    radial-gradient(circle at 0 100%, #e6d6281f, #0000 45%),
    linear-gradient(135deg, #0a2203 0%, #12380a 56%, #1a5209 100%);
}

.splash-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  max-width: 640px;
}

/* ── Logos: seal pops in, department logos slide in from the sides ── */
.splash-logos {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  margin-bottom: 28px;
}

.splash-logo {
  object-fit: contain;
  filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.35));
}

.splash-logo-main {
  width: 104px;
  height: 104px;
  animation: splash-pop 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s both;
}

.splash-logo-left,
.splash-logo-right {
  width: 76px;
  height: 76px;
}
.splash-logo-left {
  animation: splash-slide-left 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.4s both;
}
.splash-logo-right {
  animation: splash-slide-right 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.4s both;
}

/* ── Text ── */
.splash-eyebrow {
  font-size: 12.5px;
  font-weight: 700;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: #ffffffbf;
  margin-bottom: 10px;
  animation: splash-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.6s both;
}

.splash-title {
  font-family: var(--font-display);
  font-size: clamp(1.8rem, 4.5vw, 3rem);
  font-weight: 800;
  line-height: 1.08;
  letter-spacing: -0.02em;
  color: #fff;
  animation: splash-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.75s both;
}

.splash-message {
  margin-top: 14px;
  font-size: 16px;
  font-weight: 500;
  color: #ecfdf5eb;
  overflow-wrap: anywhere;
  animation: splash-rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.95s both;
}

/* ── Progress bar fills over the splash duration ── */
.splash-progress {
  width: 180px;
  height: 4px;
  margin-top: 34px;
  border-radius: 9999px;
  background: #ffffff26;
  overflow: hidden;
  animation: splash-rise 0.5s ease 0.95s both;
}
.splash-progress span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #fff;
  transform-origin: left;
  animation: splash-fill var(--splash-duration) linear both;
}
html[data-theme='bsu'] .splash-progress span {
  background: #e6d628;
}
html[data-theme='dark'] .splash-progress span {
  background: #10b981;
}

/* ── Leave ── */
.splash-leave-active {
  transition: opacity 0.55s ease, transform 0.55s ease;
}
.splash-leave-to {
  opacity: 0;
  transform: scale(1.04);
}

@keyframes splash-pop {
  from { opacity: 0; transform: scale(0.4); }
  to { opacity: 1; transform: scale(1); }
}
@keyframes splash-slide-left {
  from { opacity: 0; transform: translateX(40px) scale(0.8); }
  to { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes splash-slide-right {
  from { opacity: 0; transform: translateX(-40px) scale(0.8); }
  to { opacity: 1; transform: translateX(0) scale(1); }
}
@keyframes splash-rise {
  from { opacity: 0; transform: translateY(14px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes splash-fill {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}

@media (prefers-reduced-motion: reduce) {
  .splash-logo,
  .splash-eyebrow,
  .splash-title,
  .splash-message,
  .splash-progress {
    animation: none;
  }
  .splash-leave-to {
    transform: none;
  }
}

@media (max-width: 480px) {
  .splash-logos {
    gap: 0.85rem;
  }
  .splash-logo-main {
    width: 80px;
    height: 80px;
  }
  .splash-logo-left,
  .splash-logo-right {
    width: 58px;
    height: 58px;
  }
}
</style>
