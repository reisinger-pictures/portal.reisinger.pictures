import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 4 : 8,
    timeout: 120000,
    maxFailures: process.env.CI ? 10 : 0,
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
