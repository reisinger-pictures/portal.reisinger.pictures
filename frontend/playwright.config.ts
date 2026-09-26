import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

const isCi = process.env.CI === 'true' || process.env.CI === '1';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: isCi,
    retries: isCi ? 2 : 0,
    workers: isCi ? 4 : 8,
    // Timeout semantics (Playwright) — explicit, per AGENTS.md E2E Timeout Policy:
    //  - `timeout` below is the PER-TEST budget (Playwright default: 30s). 120s
    //    covers the heaviest login/upload/checkout flows without masking hangs.
    //  - The whole-run budget is separately bounded at 25 min (1500000 ms) for
    //    CI headroom. In completed run 36035927250, the slowest parallel shard
    //    reached 17m09s under the former 900000 ms cap and left 26 tests unrun;
    //    the serial shard passed in 6m51s. Re-measure after E2E changes.
    timeout: 120000,
    globalTimeout: 1500000,
    maxFailures: isCi ? 10 : 0,
    // CI must stay on the text-only reporter. The local HTML report remains a
    // developer-only debugging aid and is never an upload target.
    reporter: isCi ? [['list']] : [['html', {open: 'never'}]],
    // Keep CI output outside the checkout and discard it after the run. This is
    // defense in depth in addition to the workflow's no-upload policy.
    outputDir: isCi ? '/tmp/portal-playwright-results' : 'test-results',
    preserveOutput: isCi ? 'never' : 'always',
    use: {
        baseURL: 'http://localhost:4321',
        // SECURITY: a Playwright trace serializes the browser storage state,
        // including httpOnly auth cookies. This repository is public, so
        // uploaded CI artifacts are effectively world-readable. Traces are
        // therefore NEVER captured under CI; locally opt in with `PW_TRACE=1`.
        trace: isCi ? 'off' : process.env.PW_TRACE === '1' ? 'on-first-retry' : 'off',
        screenshot: 'off',
        video: 'off',
    },
    projects: [
        {name: 'Desktop Chrome', use: {...devices['Desktop Chrome'], viewport: {width: 1920, height: 950},}},
        {name: 'Mobile Chrome', use: {...devices['Galaxy A55']}},
    ],
});
