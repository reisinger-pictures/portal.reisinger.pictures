import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 4 : 8,
    // Timeout semantics (Playwright) — explicit, per AGENTS.md E2E Timeout Policy:
    //  - `timeout` below is the PER-TEST budget (Playwright default: 30s). 120s
    //    covers the heaviest login/upload/checkout flows without masking hangs.
    //  - The policy's measured ~7 min → doubled 15 min (900000 ms) is the budget
    //    for the WHOLE run (all tests/workers), i.e. Playwright's `globalTimeout`.
    //  - Deliberately NOT set here yet: with `workers: CI ? 4 : 8` and retries: 2,
    //    the 4-worker CI runtime is ~2x the 8-worker local measurement and may sit
    //    close to 15 min. A global cap must be validated against a real CI run
    //    before enabling it; the intended value is 900000 ms.
    timeout: 120000,
    maxFailures: process.env.CI ? 10 : 0,
    reporter: [
        ['html', {open: 'never'}]
    ],
    use: {
        baseURL: 'http://localhost:4321',
        // SECURITY: a Playwright trace serializes the browser storage state,
        // including httpOnly auth cookies. This repository is public, so
        // uploaded CI artifacts are effectively world-readable. Traces are
        // therefore NEVER captured under CI; locally opt in with `PW_TRACE=1`.
        trace: !process.env.CI && process.env.PW_TRACE === '1' ? 'on-first-retry' : 'off',
        video: 'off',
    },
    projects: [
        {name: 'Desktop Chrome', use: {...devices['Desktop Chrome'], viewport: {width: 1920, height: 950},}},
        {name: 'Mobile Chrome', use: {...devices['Galaxy A55']}},
    ],
});
