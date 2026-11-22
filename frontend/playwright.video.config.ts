// Demo-video category — dedicated config, deliberately separate from
// playwright.config.ts (functional E2E) and playwright.screenshots.config.ts
// (UI review). Neither of those records video; this one exists only to capture
// the walkthrough in tests/video/. Run it with `pnpm test:demo-video`.
//
// The webServer block mirrors the functional suite so a clean machine can
// reproduce the capture with one command. Both servers are reused when they
// are already up, which keeps a running dev instance untouched.
import { defineConfig, devices } from '@playwright/test';

const TURNSTILE_DUMMY_SITE_KEY = '1x00000000000000000000AA';

export default defineConfig({
    testDir: './tests/video',
    testMatch: '**/*.spec.ts',
    workers: 1,
    retries: 0,
    timeout: 180000,
    reporter: [['list']],
    outputDir: 'test-results/demo-video',
    preserveOutput: 'always',
    webServer: [
        {
            command: 'bash scripts/e2e-up.sh',
            cwd: '..',
            port: 8001,
            reuseExistingServer: true,
            timeout: 300_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
        {
            command: 'pnpm dev',
            env: {
                VITE_API_PROXY: 'http://127.0.0.1:8001',
                VITE_TURNSTILE_SITE_KEY: process.env.VITE_TURNSTILE_SITE_KEY?.trim() || TURNSTILE_DUMMY_SITE_KEY,
            },
            url: 'http://localhost:4321/',
            reuseExistingServer: true,
            timeout: 120_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
    ],
    use: {
        baseURL: 'http://localhost:4321',
        // Pinned so date inputs and all German UI strings render deterministically.
        locale: 'de-DE',
        trace: 'off',
        screenshot: 'off',
        // The spec creates its own context with `recordVideo` AFTER login, so
        // the automated page fixture must not record anything. Publishing the
        // video happens only when every assertion passed (see the spec).
        video: 'off',
    },
    projects: [
        { name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 720 } } },
    ],
});
