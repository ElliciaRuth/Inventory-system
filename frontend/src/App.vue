<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import Navbar from './components/Navbar.vue'
import { useAuthStore } from './stores/authStore'
import { useThemeStore } from './stores/themeStore'

const route = useRoute()
const authStore = useAuthStore()
const themeStore = useThemeStore()

const alertCount = ref(0)
const isAuthPage = computed(() => ['login', 'register'].includes(route.name))

function handleUpdateAlerts(count) {
  alertCount.value = count
}

onMounted(() => {
  themeStore.init()
  authStore.checkAuth()
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
