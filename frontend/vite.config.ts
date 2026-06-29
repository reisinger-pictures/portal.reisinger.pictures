import {defineConfig, loadEnv} from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({mode}) => {
    // Load .env, .env.[mode] (e.g. .env.b2b / .env.atr) so the proxy target/port can differ
    // per brand instance without extra dependencies. Two Vite instances distinguish the brand
    // locally: B2B (port 4321 → portal.test) and ATR (port 4322 → portal-atr.test). The proxy
    // target decides which host the backend sees, so BrandRegistry::fromHost() resolves correctly
    // while all frontend URLs stay relative. See features/infrastructure/12-brand-registry...md.
    const env = loadEnv(mode, process.cwd(), '')
    const port = Number(env.VITE_PORT ?? 4321)
    const apiTarget = env.VITE_API_TARGET ?? 'https://portal.test'

    return {
        define: {
            __APP_BUILD_TIME__: JSON.stringify(new Date().toISOString())
        },
        plugins: [
            react(),
            tailwindcss()
        ],
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
                            if (id.includes("@tiptap/")){
                                return "vendor-tiptap"
                            }
                        }
                    }
                }
            }
        },
        server: {
            port,
            proxy: {
                '/api': {
                    target: apiTarget,
                    changeOrigin: true,
                    secure: false,
                    cookieDomainRewrite: "localhost",
                }
            }
        }
    }
})
