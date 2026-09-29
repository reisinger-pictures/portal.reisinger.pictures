import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

const isCi = process.env.CI === 'true' || process.env.CI === '1';

// Cloudflare's documented always-passing dummy site key — the documented
// counterpart of the dummy secret `scripts/e2e-up.sh` hands the backend
// (E2E_TURNSTILE_SITE_KEY / E2E_TURNSTILE_SECRET). Without it on the frontend
// side, `ClientCartView` sees an empty key, renders its "no site key" notice
// instead of the widget, and leaves the checkout button disabled — so the
// Turnstile checkout spec fails on a missing Vite env var, not on a defect.
// Never a real key: this value is public by definition and is only ever paired
// with the dummy secret on an E2E-only backend.
const TURSTILE_DUMMY_SITE_KEY = '1x00000000000000000000AA';

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
    // Playwright starts the servers it needs and WAITS for them.
    //
    // Without this block a dead server produced one identical
    // `net::ERR_CONNECTION_REFUSED` per spec — 18 of them in the smoke run on
    // 2026-09-28 — and none of them said *why*. The specs were fine; nothing was
    // listening. That is the single worst shape a test failure can take, because
    // the count scales with the suite while the cause stays at one.
    //
    // `port` rather than `url` for the backend on purpose: Playwright treats only
    // 2xx/3xx/400/401/402/403 as "up", and this backend has no health route —
    // `/api/auth/me` without the `Accept: application/json` header renders an
    // HTML error page, which is neither. A port check asks the only question
    // that actually matters here: is something listening.
    //
    // Omitted under CI on purpose: the workflow starts its own backend on :8000
    // and its own Vite on :4321, and a second starter would race them. CI is
    // green on that path and must stay untouched.
    webServer: isCi
        ? undefined
        : [
              {
                  // Runs the full preparation (services, .env.e2e, migrate, seed,
                  // fixtures, Scout index) and then execs `artisan serve`, so
                  // starting from nothing gives a usable database, not an empty
                  // one. The timeout covers that, not just the server boot.
                  // `cwd: '..'` is the repo root, so the path is relative to
                  // there — not to frontend/. Getting this wrong exits 127.
                  command: 'bash scripts/e2e-up.sh',
                  cwd: '..',
                  port: 8001,
                  reuseExistingServer: true,
                  timeout: 300_000,
                  stdout: 'ignore',
                  stderr: 'pipe',
              },
              {
                  command: 'pnpm dev',
                  // Without this the proxy falls back to `https://portal.test`,
                  // which needs Valet/Herd — not installed here. The specs would
                  // then talk to whatever that host resolves to.
                  //
                  // `VITE_TURNSTILE_SITE_KEY` mirrors the backend dummy pair from
                  // `scripts/e2e-up.sh`; CI passes the same value in the
                  // `pnpm dev` step env, so both paths converge on one key.
                  // FALLBACK ONLY: an already-exported key wins, so a developer
                  // (or any future harness) with a real key is never overridden
                  // by the dummy. This block is skipped entirely under CI
                  // (`webServer: isCi ? undefined : ...`), where the workflow
                  // exports the key itself — so CI precedence cannot be affected.
                  env: {
                      VITE_API_PROXY: 'http://127.0.0.1:8001',
                      VITE_TURNSTILE_SITE_KEY: process.env.VITE_TURNSTILE_SITE_KEY?.trim()
                          || TURSTILE_DUMMY_SITE_KEY,
                  },
                  url: 'http://localhost:4321/',
                  reuseExistingServer: true,
                  timeout: 120_000,
                  stdout: 'ignore',
                  stderr: 'pipe',
              },
          ],
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
