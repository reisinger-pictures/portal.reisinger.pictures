// Playwright config for the UI-review screenshot set.
//
// Deliberately SEPARATE from the standard playwright.config.ts: this set only
// captures screenshots and must never run inside the normal E2E suite.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/screenshots',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // A modest worker count keeps the screenshot set under the backend's
    // per-IP login throttle: every test logs in through the UI, so high
    // concurrency bursts past the budget (HTTP 429).
    workers: 2,
    timeout: 120000,
    reporter: [
        ['html', { open: 'never', outputFolder: 'playwright-report/ui-screenshots' }],
    ],
    use: {
        baseURL: 'http://127.0.0.1:4321',
        trace: 'off',
        video: 'off',
    },
    outputDir: 'test-results/ui-screenshots',
    projects: [
        { name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 950 } } },
        { name: 'Mobile Chrome', use: { ...devices['Galaxy A55'] } },
    ],
});
