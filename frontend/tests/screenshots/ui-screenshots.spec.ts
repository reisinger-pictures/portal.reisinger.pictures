// Generic manifest-driven screenshot spec for the ui-review skill.
//
// Reads the route × state × viewport matrix from ui-review.config.ts and
// captures, per combo, a full-page PNG PLUS viewport-height section PNGs (see
// `captureSections`). This file is intentionally generic — route specifics
// (paths, auth, seeds, nav steps) live in the manifest. Adding a route is a
// manifest edit only.
//
// WHY SECTIONS: full-page PNGs of long pages get downscaled for the vision
// model — regions below the fold become unreadable. `captureSections` scrolls
// the REAL scroll container in 80 %-viewport steps (20 % overlap) and writes
// `<name>-secN.png`. It detects both window scrolling and an inner overflow
// container (100vh layout with `<main class="…overflow-auto">`).
//
// The manifest's `expectedTitle` (default APP_TITLE) is asserted so a foreign
// dev server on the port can never be silently screenshotted.
//
// Navigation: guest pages load by direct URL (initial-load exception). Admin
// pages log in through the real sidebar login form and then load the route by
// URL — the app derives the admin view from the path and the sidebar is
// off-canvas on mobile, so header-driven clicks are unreliable there. This is
// the dedicated screenshot harness, not the functional E2E suite.

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import path from 'node:path';
import process from 'node:process';
import { APP_TITLE, SCREENSHOT_OUTPUT_DIR } from './harness';
import { routes } from './ui-review.config';
import type { UiReviewNavStep, UiReviewRoute, UiReviewState, UiReviewViewport } from './ui-review.config';

const ADMIN_EMAIL = process.env.ADMIN_EMAIL ?? 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD ?? 'admin';

const out = (state: UiReviewState, viewport: UiReviewViewport, file: string) =>
    // page.screenshot resolves relative paths against process.cwd(), not the
    // config outputDir — build the absolute path explicitly.
    path.resolve(process.cwd(), SCREENSHOT_OUTPUT_DIR, state, viewport, file);

function viewportForProject(projectName: string): UiReviewViewport {
    return projectName === 'Mobile Chrome' ? 'mobile' : 'desktop';
}

function resolvePath(pattern: string, params: Record<string, unknown>): string {
    return pattern.replace(/:([A-Za-z]+)/g, (_match, key: string) => {
        const value = params[key];
        if (value === undefined || value === null) {
            throw new Error(`Route param "${key}" was not resolved by the seed for "${pattern}"`);
        }
        return String(value);
    });
}

/** Let the SPA + i18n settle so later clicks never race a layout shift. */
async function waitForAppSettled(page: Page, expectedTitle?: string): Promise<void> {
    await page.waitForLoadState('networkidle');
    if (expectedTitle) {
        // Guard: ensures this app is rendered and not a foreign dev server that
        // happens to occupy the port (prevents silent wrong captures).
        await expect(page).toHaveTitle(expectedTitle);
    }
    await page.waitForTimeout(300);
}

/**
 * Captures the whole page in readable viewport-height sections (80 % step,
 * 20 % overlap). Detects the real scroll container: prefers the window
 * (document.scrollingElement); if the app scrolls in an INNER overflow
 * container, that container is scrolled instead.
 */
async function captureSections(
    page: Page,
    state: UiReviewState,
    viewport: UiReviewViewport,
    name: string,
): Promise<void> {
    const scroller = await page.evaluate(() => {
        const doc = document.scrollingElement;
        const winH = window.innerHeight;
        if (doc && doc.scrollHeight > winH + 4) {
            return { kind: 'window', max: doc.scrollHeight - winH, step: Math.round(winH * 0.8) };
        }
        const main = document.querySelector('main');
        if (main && main.scrollHeight > main.clientHeight + 4) {
            return {
                kind: 'main',
                max: main.scrollHeight - main.clientHeight,
                step: Math.round(main.clientHeight * 0.8),
            };
        }
        return { kind: 'window', max: 0, step: Math.round(winH * 0.8) };
    });
    const scroll = (y: number) =>
        page.evaluate(
            ({ kind, y }) => {
                if (kind === 'main') {
                    const el = document.querySelector('main');
                    if (el) el.scrollTop = y;
                } else {
                    window.scrollTo(0, y);
                }
            },
            { kind: scroller.kind, y },
        );
    let y = 0;
    let i = 0;
    for (;;) {
        await scroll(y);
        await page.waitForTimeout(150);
        await page.screenshot({ path: out(state, viewport, `${name}-sec${i}.png`), fullPage: false });
        if (y >= scroller.max) break;
        i += 1;
        y = Math.min(scroller.max, y + scroller.step);
    }
    await scroll(0);
}

/**
 * UI login using the sidebar login form (header menu button → backdrop modal →
 * E-Mail/Passwort inputs → Enter). A plain admin lands on "/".
 */
async function loginAdminViaUi(page: Page): Promise<void> {
    await page.goto('/');

    await expect(page.getByTestId('app-loader').first()).toBeHidden({ timeout: 10000 });
    await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });

    const menuBtn = page.locator('header button').filter({ has: page.locator('svg') }).first();
    const emailInput = page.locator('input[placeholder="E-Mail Adresse"]').first();
    const backdrop = page.locator('div.fixed.inset-0').first();

    if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
        await expect(async () => {
            if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                await menuBtn.click();
            }
            await expect(backdrop).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 10000 });
    }

    if (await emailInput.isVisible()) {
        await emailInput.fill(ADMIN_EMAIL);
        await page.fill('input[placeholder="Passwort"]', ADMIN_PASSWORD);
        await page.getByRole('button', { name: 'Login' }).first().scrollIntoViewIfNeeded();
        await page.keyboard.press('Enter');
        await expect(emailInput).toBeHidden({ timeout: 15000 });
    }

    if (await backdrop.isVisible()) {
        await backdrop.click();
        await expect(backdrop).toBeHidden({ timeout: 5000 });
    }

    await expect(page).toHaveURL(/\/(admin.*)?$/);
    await waitForAppSettled(page);
}

/** Apply one manifest nav step (deterministic post-load UI interaction). */
async function applyNavStep(page: Page, step: UiReviewNavStep, seed: Record<string, unknown>): Promise<void> {
    if (step.kind === 'goto') {
        if (!step.path) throw new Error('nav step "goto" requires a path');
        // Direct-URL load — only for justified deep links; the manifest documents each case.
        await page.goto(resolvePath(step.path, seed));
        await waitForAppSettled(page);
        return;
    }

    if (!step.label || !step.valueKey) throw new Error('nav step "fill" requires label and valueKey');
    await page.locator('main').getByLabel(step.label, { exact: true }).fill(String(seed[step.valueKey] ?? ''));
    await waitForAppSettled(page);
}

async function settleAndCapture(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    viewport: UiReviewViewport,
): Promise<void> {
    await waitForAppSettled(page, route.expectedTitle ?? APP_TITLE);
    await expect(page.getByRole('main')).toBeVisible();
    await page.screenshot({ path: out(state, viewport, `${route.name}.png`), fullPage: true });
    await captureSections(page, state, viewport, route.name);
}

for (const route of routes) {
    for (const state of route.states) {
        for (const viewport of route.viewports ?? ['desktop', 'mobile']) {
            test(`screenshot ${route.name} (${state}, ${viewport})`, { tag: ['@screenshot'] }, async ({ page, request }, testInfo) => {
                test.skip(
                    viewportForProject(testInfo.project.name) !== viewport,
                    `project ${testInfo.project.name} renders the ${viewportForProject(testInfo.project.name)} viewport`,
                );

                // Seed BEFORE navigation so route params exist. Seed helpers are
                // per-worker cached (see seeds.ts).
                const seed = route.seeds?.[state] ? await route.seeds[state]({ request }) : {};

                if (route.auth === 'admin') {
                    await loginAdminViaUi(page);
                }

                // Initial load: the guest initial-load / deep-link exception.
                await page.goto(resolvePath(route.path, seed));
                await waitForAppSettled(page, route.expectedTitle ?? APP_TITLE);

                for (const step of route.nav ?? []) {
                    await applyNavStep(page, step, seed);
                }

                await settleAndCapture(page, route, state, viewport);
            });
        }
    }
}
