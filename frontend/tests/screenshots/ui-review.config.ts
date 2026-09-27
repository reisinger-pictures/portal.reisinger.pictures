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
import { seedOpenModelInvite, seedPhotographer, seedRegisteredModel } from './seeds';

export type UiReviewState = 'filled' | 'empty';
export type UiReviewViewport = 'desktop' | 'mobile';
export type UiReviewAuth = 'guest' | 'admin' | 'photographer';

export interface UiReviewSeedContext {
    request: APIRequestContext;
}

/** Resolves dynamic params (ids/tokens) and other seed values at runtime. */
export type UiReviewSeed = (context: UiReviewSeedContext) => Promise<Record<string, unknown>>;

export interface UiReviewNavStep {
    kind: 'goto' | 'fill' | 'click';
    /** `goto`: route pattern; `:param` tokens are resolved from the seed result. */
    path?: string;
    /** `fill`: why this UI step is needed (documentation). */
    reason?: string;
    /** `fill`: label of the input scoped to <main>. */
    label?: string;
    /** `fill`: seed key providing the value. */
    valueKey?: string;
    /**
     * `click`: locator (scoped to <main>) of the element to activate.
     *
     * Needed for states a route load cannot reach. A dialog is the case: it
     * changes nothing about the URL, so `goto` has nothing to express — the
     * only way in is the button that opens it.
     *
     * `fill`: the `fill` locator, scoped to <main>, that takes precedence over
     * `label`; use it when several controls share an accessible name. On
     * `/admin-models` three controls are called "Suche" (global search input,
     * global search submit button, model filter input), so a label-only step
     * there is a Playwright strict-mode violation.
     */
    target?: string;
    /**
     * `click`: locator that must become visible after the activation (e.g. the
     * dialog itself), so a capture never races the open animation.
     */
    waitFor?: string;
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
        // ---- Kamera / FTP (neue Oberflaechen) --------------------------------
        {
            name: 'photographer-dashboard',
            path: '/',
            states: ['filled'],
            auth: 'photographer',
            seeds: {
                filled: context => seedPhotographer(context.request),
            },
            note: 'Photographer landing: the FTP inbox with the camera connection table, the credentials button, the account status and the button that opens the setup guide dialog. Photographer-only, so the admin login cannot reach it.',
        },
        {
            // A dialog changes nothing about the URL, so it is only reachable by
            // activation — the `click` nav step exists for exactly this case.
            name: 'photographer-guide-dialog',
            path: '/',
            states: ['filled'],
            auth: 'photographer',
            seeds: {
                filled: context => seedPhotographer(context.request),
            },
            nav: [
                { kind: 'click', target: 'button:has-text("Anleitung öffnen")', waitFor: '[role="dialog"]' },
            ],
            note: 'The camera setup guide as a dialog. Captured because the dialog is the surface the owner asked for and a route load cannot reach it.',
        },
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
                    // Disambiguator: three controls on this page carry the
                    // accessible name "Suche" (global search input, global
                    // search submit button, model filter input), so the label
                    // alone is a strict-mode violation.
                    target: '#model-filter-q',
                    valueKey: 'q',
                    reason: 'The Models search filters React state, not the URL — the seed value must be typed into the "Suche" input for a deterministic filled/empty table.',
                },
            ],
            note: 'filled = one model registered through the public API (search filters to its unique first name). empty = a search term without matches → EmptyState "Keine Models gefunden".',
        },

        // ---- Dialoge (nur per Klick erreichbar) --------------------------------
        // Like `photographer-guide-dialog` above: a dialog changes nothing about
        // the URL, so each entry needs a `click` nav step to be reachable.
        {
            // Flow: an admin prices a package with the calculator before writing
            // a manual invoice.
            name: 'shooting-calculator-dialog',
            path: '/admin-manual-invoice',
            states: ['filled'],
            auth: 'admin',
            nav: [
                {
                    kind: 'click',
                    target: 'role=button[name="Paket-Kalkulator"]',
                    waitFor: 'role=dialog[name="Standard Tarif Rechner"]',
                },
            ],
            note: 'The package calculator as a dialog on the manual-invoice page. The dialog is named "Standard Tarif Rechner" because the calculator opens in its default mode.',
        },
        {
            // Flow: a photographer creates a new photo job from the production
            // board.
            name: 'photo-job-dialog',
            path: '/boards?tab=production',
            states: ['filled'],
            auth: 'photographer',
            seeds: {
                filled: context => seedPhotographer(context.request),
            },
            nav: [
                // One "Neuer Auftrag" button is rendered per production column,
                // so the name alone matches all of them (strict-mode
                // violation). `nth=0` pins the first column deterministically —
                // which is also the board's default status
                // (`defaultStatus = columns[0].status`).
                { kind: 'click', target: 'role=button[name="Neuer Auftrag"] >> nth=0', waitFor: 'role=dialog[name="Neuen Auftrag anlegen"]' },
            ],
            note: 'The new photo-job form as a dialog. The trigger is an icon-only button whose accessible name comes from its title, hence the role/name locator.',
        },
        {
            // Flow: an admin switches to the Volume-Pricing tab and creates a
            // new preset.
            name: 'volume-preset-dialog',
            path: '/settings',
            states: ['filled'],
            auth: 'admin',
            nav: [
                { kind: 'click', target: 'label.tab:has-text("Volume-Pricing")', waitFor: 'role=button[name="Neues Preset"]' },
                { kind: 'click', target: 'role=button[name="Neues Preset"]', waitFor: 'role=dialog[name="Neues Volume-Preset"]' },
            ],
            note: 'The new volume preset as a dialog. The Volume-Pricing tab is not the default, so the first step activates the tab that the second step acts on.',
        },
        {
            // Flow: an admin searches the seeded model and opens its detail
            // dialog.
            name: 'model-detail-dialog',
            path: '/admin-models',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedRegisteredModel(context.request),
            },
            nav: [
                {
                    kind: 'fill',
                    label: 'Suche',
                    // Same disambiguator as `admin-models` above — same page,
                    // same three "Suche" controls.
                    target: '#model-filter-q',
                    valueKey: 'q',
                    reason: 'The Models search filters React state, not the URL — the seed value must be typed into the "Suche" input for a deterministic filled/empty table.',
                },
                { kind: 'click', target: 'role=button[name*="UIReview"]', waitFor: 'role=dialog' },
            ],
            note: 'The detail dialog of the seeded model. The search step is copied from `admin-models` above so the table shows that single model. Only `role=dialog` is asserted: the dialog\'s accessible name is the model\'s display name, which the harness cannot substitute.',
        },
    ],
};

export const routes = uiReviewConfig.routes;
