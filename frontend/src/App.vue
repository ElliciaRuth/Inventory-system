<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import Navbar from './components/Navbar.vue'
import { useThemeStore } from './stores/themeStore'

const route = useRoute()
const themeStore = useThemeStore()

const alertCount = ref(0)
// Standalone pages without the navbar
const isAuthPage = computed(() => ['login', 'register', 'account-setup'].includes(route.name))

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
  </div>
</template>
