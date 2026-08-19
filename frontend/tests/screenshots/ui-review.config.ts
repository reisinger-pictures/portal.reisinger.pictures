// UI-review route manifest — the single source of truth for which pages get
// screenshotted and in which states. Edit this file to add/remove routes; the
// generic spec picks the changes up automatically.
//
// This harness intentionally has NO fixture-tenant logic: the app has a single
// primary tenant and we capture the "filled" (real production data) state only.

export type UiReviewState = 'filled' | 'empty';
export type UiReviewViewport = 'desktop' | 'mobile';
export type UiReviewAuth = 'guest' | 'admin' | 'user' | 'none';

export interface UiReviewRoute {
    name: string;
    /** Route pattern; `:param` tokens are resolved from the seed result where used. */
    path: string;
    states: UiReviewState[];
    auth?: UiReviewAuth;
    viewports?: UiReviewViewport[];
    /** Static <title> of the app — the spec asserts it so a foreign dev-server
     *  on the port can never be silently screenshotted. Required when the app
     *  has a stable title. */
    expectedTitle?: string;
    note?: string;
}

export interface UiReviewConfig {
    /** Mirrors `outputDir` in playwright.screenshots.config.ts. */
    outputDir: string;
    routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
    outputDir: 'test-results/ui-screenshots',
    routes: [
        {
            name: 'login',
            path: '/login',
            states: ['filled'],
            auth: 'guest',
            note: 'Guest login page (filled = the form as shown to anonymous visitors).',
        },
        {
            name: 'dashboard',
            path: '/',
            states: ['filled'],
            auth: 'admin',
            note: 'Admin lands on the dashboard ("Übersicht") after login.',
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
            note: 'ManagementSettingsView route (Einstellungen).',
        },
        {
            name: 'manual-offer',
            path: '/admin-manual-offer',
            states: ['filled'],
            auth: 'admin',
        },
        {
            name: 'contracts',
            path: '/admin-contracts',
            states: ['filled'],
            auth: 'admin',
        },
    ],
    // DEFERRED (documented, not captured):
    // - empty-tenant fixture: skipped — the app uses a single primary tenant,
    //   no data-less tenant exists to screenshot an "empty" state against.
    // - public gallery: skipped — requires a live public gallery slug + its
    //   anonymous deep link; out of scope for the admin UI-review pass.
};

export const routes = uiReviewConfig.routes;
