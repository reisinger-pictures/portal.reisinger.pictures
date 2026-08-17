import {defineConfig} from 'vite'
import react, {reactCompilerPreset} from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import babel from '@rolldown/plugin-babel'
import lingui, {linguiTransformerBabelPreset} from '@lingui/vite-plugin'

export default defineConfig(() => {
    return {
        define: {
            __APP_BUILD_TIME__: JSON.stringify(new Date().toISOString())
        },
        plugins: [
            react(),
            tailwindcss(),
            lingui(),
            babel({presets: [reactCompilerPreset(), linguiTransformerBabelPreset()]}),
        ],
        optimizeDeps: {
            include: ['photoswipe', 'photoswipe/lightbox'],
        },
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
                            if (id.includes("@tiptap/")) {
                                return "vendor-tiptap"
                            }
                        }
                    }
                }
            }
        },
        server: {
            host: '0.0.0.0',
            port: 4321,
            allowedHosts: ['portal.localhost'],
            proxy: {
                '/api': {
                    target: process.env.VITE_API_PROXY || 'https://portal.test',
                    changeOrigin: true,
                    secure: false,
                    cookieDomainRewrite: '.localhost',
                }
            }
        },
        preview: {
            port: 4173,
            proxy: {
                '/api': {
                    target: process.env.VITE_API_PROXY || 'https://portal.test',
                    changeOrigin: true,
                    secure: false,
                    cookieDomainRewrite: '.localhost',
                }
            }
        }
    }
})
