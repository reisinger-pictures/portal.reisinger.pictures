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
import { seedGallery, seedGalleryGroup, seedNotifiedGallery, seedOpenModelInvite, seedPhotographer, seedRegisteredModel, seedSelectionGallery } from './seeds';

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
        {
            // Flow: an admin assigns the photographers of a delivery gallery to
            // an existing group of the organisation.
            name: 'gallery-photographer-team-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="Fotografen..."]', waitFor: 'role=dialog[name="Fotografen-Team"]' },
            ],
            note: 'The photographer team of one seeded delivery gallery as a dialog. A gallery has to exist for a dialog scoped to it, so this is the reference entry for the other gallery dialogs: everything else on this action row (Vorgaben, Zugriff, KI Beschriftung, Einladungslink, E-Mail senden) reuses this seed and this route unchanged. "Bewertungen..." is the one exception — it only renders for a selection gallery, so it needs a second seed. The action row is photographer-gated, which the admin login satisfies because the E2E admin carries every role.',
        },
        {
            // Flow: an admin grants a registered user access to the gallery
            // instead of publishing it.
            name: 'gallery-access-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="Zugriff..."]', waitFor: 'role=dialog[name="Nutzer-Zugriff verwalten"]' },
            ],
            note: 'The per-user access list of one seeded delivery gallery as a dialog. This trigger is NOT gallery-type-gated: it only needs `onOpenAccess`, which ManagementGalleryView passes whenever `isAdmin` is true — so the delivery seed of `gallery-photographer-team-dialog` is reused unchanged. Because the dialog lists the brand users with a search field, it is captured filled on its first render rather than in an empty state.',
        },
        {
            // Flow: an admin issues a client invite link for the gallery.
            name: 'gallery-invite-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="Einladungslink..."]', waitFor: 'role=dialog[name="Einladungen verwalten"]' },
            ],
            note: 'The invite management of one seeded delivery gallery as a dialog. This trigger is not gallery-type-gated either — it is the one action on the row that renders for every gallery type, so the delivery seed is reused unchanged. The dialog is captured before a link is generated, so its generated-link field and the invite list are still empty.',
        },
        {
            // Flow: an admin sets the metadata defaults that are applied to
            // every photo of the gallery.
            name: 'gallery-metadata-defaults-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="Vorgaben..."]', waitFor: 'role=dialog[name="Metadaten-Vorgaben"]' },
            ],
            note: 'The metadata defaults of one seeded delivery gallery as a dialog. This trigger IS gallery-type-gated: ManagementGalleryActions renders "Vorgaben..." only for `gallery.type === "delivery"`, which is why this entry reuses the delivery seed and would show no button at all on the selection gallery.',
        },
        {
            // Flow: a photographer checks which client rated which photo of a
            // selection gallery and blocks the gallery once the rating is done.
            name: 'gallery-rating-status-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                // The second seed — the ONLY trigger on the action row that a
                // delivery gallery cannot satisfy.
                filled: context => seedSelectionGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="Bewertungen..."]', waitFor: 'role=dialog[name="Bewertungen & Status"]' },
            ],
            note: 'The rating and blocking status of one seeded selection gallery as a dialog. This trigger is the inverse of "Vorgaben...": ManagementGalleryActions renders "Bewertungen..." only for `gallery.type === "selection"`, so it needs its own seed — on the delivery gallery of the entries above the button does not exist. The seeded selection gallery has no photos and no ratings, so the capture shows the empty ratings table; the blocking controls appear per rated photo.',
        },
        {
            // Flow: an admin writes a custom mail to everyone on the gallery
            // who opted in to notifications.
            name: 'gallery-email-composer-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                // The ONLY action on the row that is gated on data, not on a
                // role or a gallery type: `canSendMail = notified_count > 0`
                // (ManagementGalleryView.tsx:89), so the button renders
                // disabled until a recipient has opted in. The seed therefore
                // reuses the delivery gallery and ADDS an opted-in client.
                filled: context => seedNotifiedGallery(context.request),
            },
            nav: [
                { kind: 'click', target: 'role=button[name="E-Mail senden..."]', waitFor: 'role=dialog[name="Nachricht an Kunden senden"]' },
            ],
            note: 'The custom-mail composer of one seeded delivery gallery as a dialog. This is the one trigger on the action row whose enabled state depends on seeded DATA rather than on a role or a gallery type: it is disabled while `notified_count` is 0, which is why this entry needs `seedNotifiedGallery` (a client that opted in) and not the plain delivery seed — on that one the click would hang on a disabled button. The dialog is captured before sending, so it shows the default subject/body with the `{user_name}` / `{gallery_name}` / `{link}` variables unexpanded.',
        },
        {
            // Flow: an admin opens the gallery settings to change its type,
            // visibility, licensing mode or folder.
            name: 'gallery-edit-dialog',
            path: '/galleries/:slug',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                filled: context => seedGallery(context.request),
            },
            nav: [
                // The heading pencil is icon-only, so its accessible name comes
                // from the daisyUI `data-tip` — a role/name locator cannot match
                // it. The tooltip attribute is unique in the app (only
                // ManagementGalleryView.tsx:81 carries it).
                { kind: 'click', target: 'button[data-tip="Galerie bearbeiten"]', waitFor: 'role=dialog[name="Galerie bearbeiten"]' },
            ],
            note: 'The gallery settings form of one seeded delivery gallery as a dialog, opened from the pencil next to the gallery title. Not the same trigger as the action-row dialogs: it is the heading pencil, gated on `isPhotographer` (which the harness admin satisfies), and it mounts the SAME `GalleryModal` that the dashboard\'s "Neue Galerie" button opens — the dialog is named "Galerie bearbeiten" here and "Neue Galerie" there, because GalleryModal picks the title from `editingGallery` (GalleryModal.tsx:172). The dialog\'s accessible name therefore happens to equal the tooltip, which is why it is still read from the component rather than assumed.',
        },
        {
            // Flow: an admin opens the meta-gallery settings from the heading
            // pencil of the meta-gallery page.
            name: 'meta-gallery-edit-dialog',
            path: '/meta/:id',
            states: ['filled'],
            auth: 'admin',
            seeds: {
                // The gallery-group seed: the meta-gallery route is addressed by
                // the group's id, not by a gallery slug.
                filled: context => seedGalleryGroup(context.request),
            },
            nav: [
                { kind: 'click', target: 'button[data-tip="Meta-Galerie bearbeiten"]', waitFor: 'role=dialog[name="Meta-Galerie bearbeiten"]' },
            ],
            note: 'The meta-gallery (folder) settings form as a dialog on the meta-gallery page. This is the group-level twin of `gallery-edit-dialog` and it lives on a DIFFERENT route: `/meta/:id` (App.tsx:106), where the id is the gallery group\'s primary id — not a `/galleries/*` slug, which the gallery route reads out of a splat. The trigger is the same icon-only heading pencil, gated on `isAdmin`, and the route mounts `GalleryModals` with `editingGroup={group}` (ManagementMetaGalleryView.tsx:227), so `GalleryGroupModal` renders its editing title "Meta-Galerie bearbeiten" (GalleryGroupModal.tsx:131) and its delete button. The seeded group has no child galleries, so the capture shows an empty meta-gallery behind the dialog.',
        },
    ],
};

export const routes = uiReviewConfig.routes;
