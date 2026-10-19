import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { blueposPwa } from './vite.pwa'

export default defineConfig({
  plugins: [react(), blueposPwa()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
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
