import axios from 'axios'

// Configure Axios with intelligent fallback:
// First attempts relative /api (handled by Vite dev proxy),
// then falls back to direct XAMPP Apache endpoint if needed.
const client = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  timeout: 12000,
})

client.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config
    if (!originalRequest) return Promise.reject(error)

    // If request failed with 404/Network Error on relative /api, retry with Apache direct URL
    if (
      !originalRequest._retry &&
      (error.code === 'ERR_NETWORK' || error.response?.status === 404) &&
      !originalRequest.url.startsWith('http')
    ) {
      originalRequest._retry = true
      originalRequest.baseURL = 'http://localhost/Inventory-System/api'
      return client(originalRequest)
    }

    return Promise.reject(error)
  }
)

export default client
