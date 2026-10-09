<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import Navbar from './components/Navbar.vue'
import ToastContainer from './components/ToastContainer.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { useThemeStore } from './stores/themeStore'
import { useIdleTimeout } from './composables/useIdleTimeout'

const route = useRoute()
const themeStore = useThemeStore()

// Auto-logout after 15 minutes of inactivity
useIdleTimeout()

const alertCount = ref(0)
// Standalone pages without the navbar
const isAuthPage = computed(() => ['login', 'register', 'forgot-password', 'account-setup'].includes(route.name))

function handleUpdateAlerts(count) {
  alertCount.value = count
}

onMounted(() => {
  themeStore.init()
})
</script>

<template>
  <div :class="isAuthPage ? 'login-page-standalone' : 'app-container'">
    <!-- Navbar only displayed for authenticated app views -->
    <Navbar v-if="!isAuthPage" :alert-count="alertCount" />
    <main :class="isAuthPage ? 'login-viewport' : 'main-content'">
      <router-view :key="$route.fullPath" @update-alerts="handleUpdateAlerts" />
    </main>
    <ToastContainer />
    <ConfirmDialog />
  </div>
</template>
