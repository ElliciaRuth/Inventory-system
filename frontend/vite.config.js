import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [vue()],
    server: {
      host: '0.0.0.0',
      port: 5173,
      proxy: {
        // Forward API calls to the CodeIgniter backend (`php spark serve` in ../backend).
        // Override with VITE_DEV_API_TARGET in frontend/.env.local if it runs elsewhere.
        '/api': {
          target: env.VITE_DEV_API_TARGET || 'http://localhost:8080',
          changeOrigin: true,
          secure: false,
          // The backend sees every proxied request as coming from this PC. Pass the real
          // client address (overwriting anything the browser sent) so per-device login locks
          // and rate limits don't lock out everyone at once; the backend trusts this header
          // only from 127.0.0.1 (Config\App::$proxyIPs).
          configure: (proxy) => {
            proxy.on('proxyReq', (proxyReq, req) => {
              proxyReq.setHeader('X-Real-IP', (req.socket.remoteAddress || '').replace(/^::ffff:/, ''))
            })
          },
        },
        // Finished-product barcode SVGs are written to backend/public/barcodes
        '/barcodes': {
          target: env.VITE_DEV_API_TARGET || 'http://localhost:8080',
          changeOrigin: true,
        },
      },
    },
  }
})
