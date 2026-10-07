import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import './style.css'
import './responsive.css'
import App from './App.vue'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Wait for the first navigation (which loads the session) before rendering
router.isReady().then(() => app.mount('#app'))
