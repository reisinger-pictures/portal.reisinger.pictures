import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 4321,
    proxy: {
      '/api': {
        target: 'https://portal.test', // Deine lokale Herd-Domain
        changeOrigin: true,
        secure: false, // Wichtig für lokale Zertifikate
        cookieDomainRewrite: "localhost",
      }
    }
  }
})