// Generic manifest-driven screenshot spec for the ui-review skill.
//
// Reads the route/state/viewport matrix from the manifest and captures a
// full-page PNG PER COMBO **plus** viewport-height SECTION captures of the
// whole page (see `captureSections`). This file is intentionally generic —
// route specifics (paths, auth) live in the manifest.
//
// WHY SECTIONS: Full-page PNGs of long pages get downscaled for the vision
// model — regions below the fold become unreadable. `captureSections`
// therefore scrolls the ENTIRE page in 80 %-viewport steps (20 % overlap) and
// saves `<name>-secN.png` files. It detects the REAL scroll container: the
// window normally, but if the app scrolls in an inner overflow container
// (e.g. `<main class="…overflow-auto">`), that container is scrolled instead.

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import path from 'node:path';
import process from 'node:process';
import { routes } from './ui-review.config';
import type { UiReviewRoute, UiReviewState, UiReviewViewport } from './ui-review.config';

const PRIMARY_ORIGIN = 'http://127.0.0.1:4321';
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'admin';
const SCREENSHOT_OUTPUT_DIR = 'test-results/ui-screenshots'; // mirrors the config outputDir

const out = (state: UiReviewState, viewport: UiReviewViewport, file: string) =>
    path.resolve(process.cwd(), SCREENSHOT_OUTPUT_DIR, state, viewport, file);

function viewportForProject(projectName: string): UiReviewViewport {
    return projectName === 'Mobile Chrome' ? 'mobile' : 'desktop';
}

function resolvePath(pattern: string, params: Record<string, unknown>): string {
    return pattern.replace(/:([A-Za-z]+)/g, (_match, key: string) => {
        const value = params[key];
        if (value === undefined || value === null) {
            throw new Error(`Route param "${key}" was not resolved for "${pattern}"`);
        }
        return String(value);
    });
}

/** Let the SPA + i18n settle so later clicks never race a layout shift. */
async function waitForAppSettled(page: Page, expectedTitle?: string): Promise<void> {
    await page.waitForLoadState('networkidle');
    if (expectedTitle) {
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
 * UI login using the AuthHelper selector strategy (sidebar menu button →
 * backdrop modal → E-Mail/Passwort inputs → "Login" button). After submit the
 * auth redirect chain must finish before any nav runs. A plain admin lands on
 * "/" or "/admin/*".
 */
async function loginAdminViaUi(page: Page, origin: string, email: string, password: string): Promise<void> {
    await page.goto(`${origin}/`);

    await expect(page.getByTestId('app-loader').first()).toBeHidden({ timeout: 5000 });
    await expect(page.locator('main').first()).toBeVisible({ timeout: 5000 });

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
        await emailInput.fill(email);
        await page.fill('input[placeholder="Passwort"]', password);
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

async function settleAndCapture(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    viewport: UiReviewViewport,
): Promise<void> {
    await waitForAppSettled(page, route.expectedTitle);
    await expect(page.getByRole('main')).toBeVisible();
    await page.screenshot({ path: out(state, viewport, `${route.name}.png`), fullPage: true });
    await captureSections(page, state, viewport, route.name);
}

for (const route of routes) {
    for (const state of route.states) {
        for (const viewport of route.viewports ?? ['desktop', 'mobile']) {
            test(`screenshot ${route.name} (${state}, ${viewport})`, { tag: ['@screenshot'] }, async ({ page }, testInfo) => {
                test.skip(
                    viewportForProject(testInfo.project.name) !== viewport,
                    `project ${testInfo.project.name} renders the ${viewportForProject(testInfo.project.name)} viewport`,
                );

                const origin = PRIMARY_ORIGIN;
                const seed: Record<string, unknown> = {};

                if (route.auth === 'admin') {
                    await loginAdminViaUi(page, origin, ADMIN_EMAIL, ADMIN_PASSWORD);
                    // Admin is now on "/" — navigate to the target admin route.
                    await page.goto(`${origin}${resolvePath(route.path, seed)}`);
                    await waitForAppSettled(page, route.expectedTitle);
                } else {
                    // Guest (e.g. the login page) — direct URL is the allowed
                    // initial-load exception.
                    await page.goto(`${origin}${resolvePath(route.path, seed)}`);
                    await waitForAppSettled(page, route.expectedTitle);
                }

                await settleAndCapture(page, route, state, viewport);
            });
        }
    }
}
