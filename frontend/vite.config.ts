import {defineConfig} from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [react(), tailwindcss()],
    build: {
        chunkSizeWarningLimit: 1024,
    },
    server: {
        port: 4321,
        proxy: {
            '/api': {
                target: 'https://portal.test',
                changeOrigin: true,
                secure: false,
                cookieDomainRewrite: "localhost",
            }
        }
    }
})