// Playwright config for the UI-review screenshot set.
//
// Deliberately SEPARATE from the standard playwright.config.ts: this set only
// captures screenshots and must never run inside the normal E2E suite
// (`playwright test` / CI). Run it with `pnpm test:screenshots`.
import { defineConfig, devices } from '@playwright/test';
import { SCREENSHOTS_BASE_URL, SCREENSHOT_OUTPUT_DIR } from './tests/screenshots/harness';

export default defineConfig({
    testDir: './tests/screenshots',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // A modest worker count keeps the screenshot set under the backend's
    // per-IP login throttle: every admin test logs in through the UI and every
    // seed authenticates via the API, so high concurrency can burst past the
    // budget. Login-bearing seeds are cached per worker (see seeds.ts).
    workers: process.env.CI ? 2 : 2,
    timeout: 120000,
    reporter: [
        ['html', { open: 'never', outputFolder: 'playwright-report/ui-screenshots' }],
    ],
    use: {
        baseURL: SCREENSHOTS_BASE_URL,
        trace: 'off',
        video: 'off',
    },
    outputDir: SCREENSHOT_OUTPUT_DIR,
    projects: [
        { name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 950 } } },
        { name: 'Mobile Chrome', use: { ...devices['Galaxy A55'] } },
    ],
});
