import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false, // Sequenziell lassen, um UI-Ressourcen zu schonen
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1, 
  reporter: 'html',
  // globalSetup entfernt! Tests laufen nun zerstörungsfrei gegen die Dev-DB.
  use: {
    baseURL: 'http://localhost:4321',
    trace: 'on-first-retry',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
