import {defineConfig, devices} from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : 8,
    maxFailures: 773,
    reporter: [
        ['html', {open: 'never'}]
    ],
    use: {
        baseURL: 'http://localhost:4321',
        trace: 'on-first-retry',
        video: 'off',
    },
    projects: [
        {name: 'Desktop Chrome', use: {...devices['Desktop Chrome'], viewport: {width: 1920, height: 950},}},
        {name: 'Mobile Chrome', use: {...devices['Galaxy A55']}},
    ],
});
