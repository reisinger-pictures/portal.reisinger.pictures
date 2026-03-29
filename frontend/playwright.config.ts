import {defineConfig, devices} from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : 8,
    reporter: 'html',
    // globalSetup entfernt! Tests laufen nun zerstörungsfrei gegen die Dev-DB.
    use: {
        baseURL: 'http://localhost:4321',
        trace: 'on-first-retry',
        video: 'retain-on-failure',
    },
    projects: [
        {name: 'Desktop Chrome', use: {...devices['Desktop Chrome']  ,viewport: {width: 1920, height: 1080},}},
        {name: 'Mobile Chrome', use: {...devices['Galaxy A55']}},
    ],
});
