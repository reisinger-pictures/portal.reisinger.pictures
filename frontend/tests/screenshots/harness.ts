// Shared constants for the UI-review screenshot harness.
//
// Dependency-free on purpose: playwright.screenshots.config.ts imports this
// file, so it must not pull the seed helpers (and their app/E2E imports) into
// the Playwright config process.

/**
 * Local Vite dev server that serves the portal SPA.
 *
 * 4322 — NOT the default 4321: the review machine runs a foreign Astro dev
 * server on 4321, so the portal dev server was started on 4322. Override with
 * SCREENSHOTS_BASE_URL when the harness runs elsewhere.
 */
export const SCREENSHOTS_BASE_URL = process.env.SCREENSHOTS_BASE_URL ?? 'http://127.0.0.1:4322';

/** Mirrors `outputDir` in playwright.screenshots.config.ts. */
export const SCREENSHOT_OUTPUT_DIR = 'test-results/ui-screenshots';

/**
 * Static `<title>` from frontend/index.html (the app does not use react-helmet
 * for the title). Asserted before every capture so a foreign dev server on the
 * port is never silently screenshotted.
 */
export const APP_TITLE = 'Reisinger Foto Portal';
