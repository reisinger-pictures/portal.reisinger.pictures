// UI-review route manifest — the single source of truth for which pages get
// screenshotted and in which states. Edit this file to add/remove routes; the
// generic spec (ui-screenshots.spec.ts) picks the changes up automatically.
//
// This app is single-brand ("rp"); there is no data-less fixture tenant. Empty
// states are therefore either a genuine empty UI state (no search results) or a
// documented terminal state (invalid invite token) — never faked. Where a truly
// empty state is not reachable without deleting primary-brand data, the route
// documents that in `note` and is captured filled-only (see ui-review skill
// references/harness.md §5).

import type { APIRequestContext } from '@playwright/test';
import { SCREENSHOT_OUTPUT_DIR } from './harness';
import { seedOpenModelInvite, seedRegisteredModel } from './seeds';

export type UiReviewState = 'filled' | 'empty';
export type UiReviewViewport = 'desktop' | 'mobile';
export type UiReviewAuth = 'guest' | 'admin';

export interface UiReviewSeedContext {
    request: APIRequestContext;
}

/** Resolves dynamic params (ids/tokens) and other seed values at runtime. */
export type UiReviewSeed = (context: UiReviewSeedContext) => Promise<Record<string, unknown>>;

export interface UiReviewNavStep {
    kind: 'goto' | 'fill';
    /** `goto`: route pattern; `:param` tokens are resolved from the seed result. */
    path?: string;
    /** `fill`: why this UI step is needed (documentation). */
    reason?: string;
    /** `fill`: label of the input scoped to <main>. */
    label?: string;
    /** `fill`: seed key providing the value. */
    valueKey?: string;
}

export interface UiReviewRoute {
    name: string;
    /** Route pattern; `:param` tokens are resolved from the seed result where used. */
    path: string;
    states: UiReviewState[];
    auth?: UiReviewAuth;
    viewports?: UiReviewViewport[];
    /** Static <title> (defaults to APP_TITLE) — asserted so a foreign dev server
     *  on the port can never be silently screenshotted. */
    expectedTitle?: string;
    note?: string;
    /** Seed per state — only states that need deterministic data define one. */
    seeds?: Partial<Record<UiReviewState, UiReviewSeed>>;
    /** UI steps applied after the initial route load (manifest-driven). */
    nav?: UiReviewNavStep[];
}

export interface UiReviewConfig {
    /** Mirrors `outputDir` in playwright.screenshots.config.ts. */
    outputDir: string;
    routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
    outputDir: SCREENSHOT_OUTPUT_DIR,
    routes: [
        // ---- Core smoke probe -------------------------------------------------
        {
            name: 'login',
            path: '/',
            states: ['filled'],
            auth: 'guest',
            note: 'Anonymous landing (SearchView) including the sidebar login form. The app has no dedicated /login route — the form lives in the sidebar and is off-canvas on mobile (open it via the header menu button to review it filled).',
        },
        {
            name: 'dashboard',
            path: '/',
            states: ['filled'],
            auth: 'admin',
            note: 'Admin landing after login (ManagementDashboard, currentView "structure").',
        },
        {
            name: 'projects-board',
            path: '/admin-projects',
            states: ['filled'],
            auth: 'admin',
        },
        {
            name: 'settings',
            path: '/settings',
            states: ['filled'],
            auth: 'admin',
            note: 'ManagementSettingsView (Einstellungen).',
        },

        // ---- Model registration ----------------------------------------------
        {
            name: 'model-registration-public',
            path: '/model-registrierung/:token',
            states: ['filled', 'empty'],
            auth: 'guest',
            seeds: {
                filled: context => seedOpenModelInvite(context.request),
                // Unknown token → the terminal error page ("Einladung nicht gefunden").
                empty: async () => ({ token: 'ui-review-unbekannter-token' }),
            },
            note: 'Public one-time invite link. filled = valid open invite (questionnaire form). empty = unknown token → error page (not a data-empty form).',
        },
        // `/admin-model-invites` redirects to `/admin-models`; the invite form is
        // a modal opened by a button click, which the manifest's nav steps
        // (`goto`/`fill`) cannot trigger — covered by the E2E dialog test instead.
        {
            name: 'admin-models',
            path: '/admin-models',
            states: ['filled', 'empty'],
            auth: 'admin',
            seeds: {
                filled: context => seedRegisteredModel(context.request),
                empty: async () => ({ q: 'ui-review-kein-treffer' }),
            },
            nav: [
                {
                    kind: 'fill',
                    label: 'Suche',
                    valueKey: 'q',
                    reason: 'The Models search filters React state, not the URL — the seed value must be typed into the "Suche" input for a deterministic filled/empty table.',
                },
            ],
            note: 'filled = one model registered through the public API (search filters to its unique first name). empty = a search term without matches → EmptyState "Keine Models gefunden".',
        },
    ],
};

export const routes = uiReviewConfig.routes;
