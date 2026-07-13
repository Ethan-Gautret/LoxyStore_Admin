import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
// Deployed under https://loxystore.fr/synchro/ in production, root in dev.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/synchro/' : '/',
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
}))
