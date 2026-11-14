
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Đặt VITE_DEV_HOST=0.0.0.0 trong frontend/.env để tablet/POS trong LAN truy cập được.
const devHost = process.env.VITE_DEV_HOST || '127.0.0.1'

export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
  ],

  server: {
    host: devHost,
    port: 5173,
    strictPort: true,

    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/login': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },

      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
