<script setup>
import bsuLogo from '../assets/images/bsu-logo.png'
import bakeryLogo from '../assets/images/bakery-logo.png'
import fpcLogo from '../assets/images/fpc-logo.png'

defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <div class="auth-logos-header" :class="{ 'is-compact': compact }">
    <div class="auth-logos-row" role="region" aria-label="BSU Institution and Department Logos">
      <!-- Bakery Logo (Flanking Left) -->
      <div class="auth-logo-badge auth-logo-dept" title="BSU Bakery Project">
        <img
          :src="bakeryLogo"
          alt="BSU Bakery Project Logo"
          class="auth-logo-img"
          loading="eager"
        />
      </div>

      <!-- BSU Logo (Middle, Primary Institution Seal) -->
      <div class="auth-logo-badge auth-logo-main" title="Benguet State University (Primary Institution)">
        <img
          :src="bsuLogo"
          alt="Benguet State University Official Seal"
          class="auth-logo-img"
          loading="eager"
        />
      </div>

      <!-- FPC Logo (Flanking Right) -->
      <div class="auth-logo-badge auth-logo-dept" title="BSU Food Processing Center">
        <img
          :src="fpcLogo"
          alt="BSU Food Processing Center Logo"
          class="auth-logo-img"
          loading="eager"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.auth-logos-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  margin-bottom: 1.5rem;
  width: 100%;
}

.auth-logos-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  width: 100%;
}

.auth-logo-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  /* Transparent PNG logos: no white frame, shadow follows the logo's own shape */
  filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.12));
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), filter 0.25s ease;
  flex-shrink: 0;
}

.auth-logo-badge:hover {
  transform: translateY(-2px) scale(1.05);
  filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.18));
}

/* Primary institution seal (BSU) - middle with visual hierarchy */
.auth-logo-main {
  width: 72px;
  height: 72px;
}

/* Flanking department logos (Bakery & FPC) */
.auth-logo-dept {
  width: 58px;
  height: 58px;
}

.auth-logo-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

/* Compact variant (e.g. for brand panel) */
.is-compact .auth-logos-row {
  gap: 0.85rem;
}
.is-compact .auth-logo-main {
  width: 56px;
  height: 56px;
}
.is-compact .auth-logo-dept {
  width: 44px;
  height: 44px;
}
.is-compact .auth-logo-badge {
  filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.25));
}

/* Dark mode styling */
:global(html[data-theme='dark']) .auth-logo-badge {
  filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.5));
}

/* Responsive Auto-scaling on Mobile Devices */
@media (max-width: 480px) {
  .auth-logos-header {
    margin-bottom: 1.15rem;
  }
  .auth-logos-row {
    gap: 0.75rem;
  }
  .auth-logo-main {
    width: 60px;
    height: 60px;
  }
  .auth-logo-dept {
    width: 48px;
    height: 48px;
  }
}

@media (max-width: 360px) {
  .auth-logos-row {
    gap: 0.5rem;
  }
  .auth-logo-main {
    width: 52px;
    height: 52px;
  }
  .auth-logo-dept {
    width: 42px;
    height: 42px;
  }
}
</style>
