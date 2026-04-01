import {defineConfig} from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    plugins: [react(), tailwindcss()],
    build: {
        chunkSizeWarningLimit: 1024,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    // Only extract isolated, heavy libraries. 
                    // Let Rollup natively chunk React and core utilities to prevent circular dependencies.
                    if (id.includes('node_modules')) {
                        if (id.includes('recharts')) {
                            return 'vendor-recharts';
                        }
                        if (id.includes('photoswipe')) {
                            return 'vendor-photoswipe';
                        }
                    }
                }
            }
        }
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