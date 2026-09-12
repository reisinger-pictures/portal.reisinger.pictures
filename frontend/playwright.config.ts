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
    //  - Validated 2026-09-12 (CI run 34710924406): each CI shard invocation runs
    //    ~3 min, the full local run ~7 min — both far below the 15 min cap.
    timeout: 120000,
    globalTimeout: 900000,
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
