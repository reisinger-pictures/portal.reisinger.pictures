// Playwright config for the UI-review screenshot set.
//
// Deliberately SEPARATE from the functional E2E suite (playwright.config.ts):
// this set only captures screenshots and never asserts behavior. Screenshots
// land under `test-results/ui-screenshots/<state>/<viewport>/<name>.png`.
//
// The Vite dev server proxies `/api` to the backend (default https://portal.test,
// see vite.config.ts). `.test` resolves to loopback and the TLS handshake hangs
// (requests never settle → `networkidle` would time out). So `VITE_API_PROXY` is
// pinned to a fast-refusing port: API calls fail immediately with a 502 and the
// app settles into its error states — exactly the state this harness captures.
import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/screenshots",
  testMatch: "**/*.spec.ts",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : 2,
  timeout: 120000,
  reporter: [["html", { open: "never", outputFolder: "playwright-report/ui-screenshots" }]],
  use: {
    baseURL: "http://localhost:5176",
    trace: "off",
    video: "off",
  },
  webServer: {
    command: "VITE_API_PROXY=http://127.0.0.1:1 pnpm dev --port 5176 --strictPort",
    url: "http://localhost:5176",
    reuseExistingServer: true,
    timeout: 120000,
  },
  outputDir: "test-results/ui-screenshots",
  projects: [
    { name: "Desktop Chrome", use: { ...devices["Desktop Chrome"], viewport: { width: 1920, height: 950 } } },
    { name: "Mobile Chrome", use: { ...devices["Galaxy A55"] } },
  ],
});
