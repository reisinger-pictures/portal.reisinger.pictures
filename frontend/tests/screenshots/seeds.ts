// Per-state seed helpers for the UI-review screenshot manifest.
//
// Seeds resolve dynamic ids/credentials from data created at runtime (invite
// tokens, a registered model) so the manifest never hard-codes anything. They
// are per-worker cached: each Playwright worker gets its own module instance,
// so one worker performs one API login/invite instead of one per screenshot
// test — the backend throttles logins per IP.
//
// The screenshot harness runs against the LOCAL DEV backend (portal.test via
// the :4322 Vite proxy). QUEUE_CONNECTION=sync there, so invite mails reach
// Mailpit immediately; MailpitHelper polls up to 10 s for the message.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import type { APIRequestContext } from '@playwright/test';
import { E2ESessionHelper } from '../e2e/helpers/E2ESessionHelper';
import { MailpitHelper } from '../e2e/helpers/MailpitHelper';

/** Short random suffix so repeated runs never collide on unique constraints. */
function uniqueId(): string {
    return Math.random().toString(36).slice(2, 10);
}

interface OpenInvite {
    token: string;
    email: string;
}

interface RegisteredModel {
    /** Search term typed into the Models "Suche" field so the seeded row shows. */
    query: string;
}

/**
 * A type alias, not an `interface`, on purpose: `UiReviewSeed` returns
 * `Record<string, unknown>`, and only object literal *types* get an implicit
 * index signature. An `interface` with the same members is not assignable to
 * `Record<string, unknown>` and fails `tsc -b` (the seed's return type would
 * otherwise never satisfy its manifest slot).
 */
type PhotographerCredentials = {
    email: string;
    password: string;
};

/**
 * A `type` alias for the same reason as `PhotographerCredentials` above — and
 * it MUST stay one: every gallery seed (`seedGallery`,
 * `seedSelectionGallery`, `seedNotifiedGallery`) returns this type into the
 * manifest's `Record<string, unknown>` seed slot, and converting it to an
 * `interface` breaks `tsc -b` on `tests/`.
 *
 * `id` is the numeric primary key of the created gallery, normalised to a
 * string. It is not a route param — the public route is addressed by slug — but
 * the notification opt-in endpoint is addressed by id
 * (`POST /api/galleries/:id/opt-in`), so a seed that has to produce a
 * recipient needs it.
 */
type SeededGallery = {
    id: string;
    slug: string;
};

/**
 * A `type` alias for the same reason as `SeededGallery` above — the group seed
 * returns into the same `Record<string, unknown>` slot, where only an object
 * literal type gets an implicit index signature.
 */
type SeededGalleryGroup = {
    id: string;
};

/** Admin login of the harness, same defaults as E2ESessionHelper and the spec. */
const ADMIN_EMAIL = process.env.ADMIN_EMAIL ?? 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD ?? 'admin';

let openInviteCache: OpenInvite | null = null;
let inviteListCache: Record<string, unknown> | null = null;
let registeredModelCache: RegisteredModel | null = null;
let photographerCache: PhotographerCredentials | null = null;
let galleryCache: SeededGallery | null = null;
let selectionGalleryCache: SeededGallery | null = null;
let notifiedGalleryCache: SeededGallery | null = null;
let galleryGroupCache: SeededGalleryGroup | null = null;

/**
 * Create one admin invite and resolve its magic-link token from Mailpit. The
 * invite API response intentionally does not leak the token, so the mail is the
 * only source (mirrors tests/e2e/crm/model-registration.spec.ts).
 */
async function createInviteToken(request: APIRequestContext, prefix: string): Promise<OpenInvite> {
    const helper = new E2ESessionHelper(request);
    const email = `ui-review-${prefix}-${uniqueId()}@example.com`;
    await helper.createModelInvite(email);

    const mailpit = new MailpitHelper(request);
    const token = await mailpit.extractModelRegistrationToken(email);
    if (!token) {
        throw new Error(`UI-review seed: no invite token was mailed to ${email}. Is Mailpit up and the queue worker running?`);
    }

    return { token, email };
}

/**
 * Filled state of the public registration page: an open (unused, unexpired)
 * invite token.
 */
export async function seedOpenModelInvite(request: APIRequestContext): Promise<Record<string, unknown>> {
    openInviteCache ??= await createInviteToken(request, 'open');
    return { token: openInviteCache.token };
}

/**
 * Filled state of the admin invite list: guarantee at least one invite exists
 * in the brand. Returns the seeded e-mail for reference (not used by the spec).
 */
export async function seedModelInviteList(request: APIRequestContext): Promise<Record<string, unknown>> {
    if (!inviteListCache) {
        const invite = await createInviteToken(request, 'list');
        inviteListCache = { email: invite.email };
    }
    return inviteListCache;
}

/**
 * Filled state of the admin Models search: register one person through the
 * public API using the current single-v1 catalogue (enriched with willingness,
 * stock, photo consent and a mandatory age proof). The returned `q` is the
 * spec's search term so the seeded row is guaranteed to be visible.
 */
export async function seedRegisteredModel(request: APIRequestContext): Promise<Record<string, unknown>> {
    if (!registeredModelCache) {
        const invite = await createInviteToken(request, 'model');
        const firstName = `UIReview${uniqueId()}`;
        const email = `ui-review-model-${uniqueId()}@example.com`;
        const ageProof = readFileSync(path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'));

        // Willingness is required for every category (and stock) in the current
        // catalogue; `experience_*` stays optional. Portrait is the showcased
        // category, hence "gerne".
        const willingness = [
            'willingness_portrait',
            'willingness_fashion',
            'willingness_business',
            'willingness_boudoir',
            'willingness_bikini',
            'willingness_akt',
            'willingness_sport',
            'willingness_couple_family',
        ];

        const fields: Record<string, string> = {
            'persons[0][answers][first_name]': firstName,
            'persons[0][answers][last_name]': 'Modell',
            'persons[0][answers][birthdate]': '1995-05-05',
            'persons[0][answers][gender]': 'weiblich',
            'persons[0][answers][email]': email,
            'persons[0][answers][phone]': '+43 660 1234567',
            'persons[0][answers][street]': 'Teststraße 1',
            'persons[0][answers][zip]': '4020',
            'persons[0][answers][city]': 'Linz',
            'persons[0][answers][country]': 'Österreich',
            // Experience > "keine" marks the category as in use.
            'persons[0][answers][experience_portrait]': '0',
            'persons[0][answers][consent_privacy]': '1',
            'persons[0][answers][consent_accuracy]': '1',
            'persons[0][answers][consent_contact]': '1',
            'persons[0][answers][consent_photos]': '1',
            'persons[0][answers][consent_all_persons]': '1',
            'persons[0][create_account]': '0',
            'manager_index': '0',
        };
        for (const key of willingness) {
            fields[`persons[0][answers][${key}]`] = key === 'willingness_portrait' ? 'gerne' : 'nein';
        }
        fields['persons[0][answers][willingness_stock]'] = 'nein';

        const response = await request.post(`/api/model-registration/${invite.token}`, {
            headers: { Accept: 'application/json' },
            multipart: {
                ...fields,
                // `age_proof` is mandatory for every person in the current catalogue.
                'persons[0][age_proof]': { name: 'sample.jpg', mimeType: 'image/jpeg', buffer: ageProof },
            },
        });

        if (!response.ok()) {
            throw new Error(`UI-review seed: model registration failed (${response.status()}): ${await response.text()}`);
        }

        registeredModelCache = { query: firstName };
    }

    return { q: registeredModelCache.query };
}

/**
 * Filled state of the photographer dashboard: a photographer who owns a camera
 * account. The FTP inbox is photographer-only, so the admin login cannot reach
 * it — this seed creates the role the inbox renders for. The credentials are
 * returned so the spec can log in through the real sidebar form.
 */
export async function seedPhotographer(request: APIRequestContext): Promise<PhotographerCredentials> {
    if (photographerCache) return photographerCache;

    const helper = new E2ESessionHelper(request);
    photographerCache = await helper.createIsolatedUser('photographer');

    return photographerCache;
}

/**
 * The ONE gallery seed the whole gallery family hangs on.
 *
 * Every gallery-scoped dialog entry (Fotografen-Team, Zugriff, Einladungslink,
 * Bewertungen, Metadaten-Vorgaben, E-Mail, Galerie bearbeiten, Meta-Galerie
 * bearbeiten) is created through this one function; the exported seeds below
 * differ only in the definition they pass. That is deliberate: the family's
 * determinism is a property of the single create-and-resolve contract, so it is
 * fixed in one place instead of copied three times.
 *
 * Two server-side facts this contract has to respect, both found by reading
 * rather than assuming:
 *
 * - `is_public` is sent but not trusted for a selection gallery:
 *   `GalleryService::storeGallery` forces `is_public` (and `is_free_download`)
 *   to `false` (GalleryService.php:118 and the re-assert at 138-143), and the
 *   route stays loadable through the super-admin bypass instead — see
 *   `seedSelectionGallery` below.
 * - The slug is read from the RESPONSE, never echoed from the request:
 *   `SlugService::makeUnique` appends a counter on a collision, and a stale
 *   slug would 404 the route.
 */
async function createGallerySeed(
    request: APIRequestContext,
    definition: { name: string; slug: string; type: 'delivery' | 'selection'; isPublic: boolean; label: string },
): Promise<SeededGallery> {
    const helper = new E2ESessionHelper(request);
    const cookie = await helper.loginAs(ADMIN_EMAIL, ADMIN_PASSWORD);

    const response = await request.post('/api/management/galleries', {
        data: {
            name: definition.name,
            slug: definition.slug,
            type: definition.type,
            is_public: definition.isPublic,
        },
        headers: { 'Accept': 'application/json', 'Cookie': cookie },
    });

    if (!response.ok()) {
        throw new Error(`UI-review seed: ${definition.label} creation failed (${response.status()}): ${await response.text()}`);
    }

    const body = (await response.json()) as { gallery?: { id?: string | number; slug?: string } };
    const slug = body.gallery?.slug;
    const id = body.gallery?.id;
    if (!slug || id === undefined || id === null) {
        throw new Error(`UI-review seed: ${definition.label} creation returned no id/slug: ${JSON.stringify(body)}`);
    }

    // `GalleryResource` passes the raw primary key through, so the JSON type is
    // not guaranteed — normalise it to the string every seed type declares.
    return { id: String(id), slug };
}

/**
 * Filled state of a management gallery: one delivery gallery created through
 * the management API, so a gallery-scoped dialog has a gallery to hang on.
 *
 * The admin account is used for the create call because the harness route logs
 * in as admin: GalleryService::storeGallery attaches a creating *photographer*
 * to the new gallery, and `E2ETestUserSeeder` gives admin every role, so the
 * same session that creates the gallery can also open its dialogs.
 *
 * `is_public: true` is what makes the gallery loadable at all:
 * GalleryFrontendController::show only serves a private gallery to a user with
 * gallery access, and the public entry point is a plain slug lookup.
 *
 * The returned slug is the one from the RESPONSE, not the one sent — the
 * service runs it through SlugService::makeUnique, which appends a counter on
 * a collision, and a stale slug would 404 the route.
 */
export async function seedGallery(request: APIRequestContext): Promise<SeededGallery> {
    if (galleryCache) return galleryCache;

    const suffix = uniqueId();
    galleryCache = await createGallerySeed(request, {
        name: `UI Review Galerie ${suffix}`,
        slug: `ui-review-galerie-${suffix}`,
        type: 'delivery',
        isPublic: true,
        label: 'gallery',
    });
    return galleryCache;
}

/**
 * Filled state of a management *selection* gallery — the second gallery the
 * action-row dialogs need, because "Bewertungen..." is the one trigger on
 * that row that only renders for `gallery.type === 'selection'`
 * (ManagementGalleryActions.tsx:25).
 *
 * Same create-and-resolve contract as `seedGallery` above (`createGallerySeed`),
 * and deliberately the same payload shape, so the two seeds stay comparable
 * side by side. Two things differ:
 *
 * 1. Only the definition differs — type, name and slug. The two seeds carried a
 *    copied ~20-line create-and-resolve body until `createGallerySeed` above
 *    was extracted: the payload is identical apart from the definition, so one
 *    function now takes the definition and returns the resolved gallery.
 * 2. `is_public: true` is sent for symmetry, but the backend deliberately
 *    OVERRIDES it: `GalleryService::storeGallery` forces `is_public` (and
 *    `is_free_download`) to `false` for every selection gallery — a
 *    free-download group must not turn a selection gallery into an
 *    unrestricted original-download surface (GalleryService.php:118 and the
 *    re-assert at 138-143). A selection gallery is therefore never public.
 *
 * The route stays loadable anyway, via the super-admin bypass rather than via
 * `is_public`: `GalleryFrontendController::show` lets a non-public gallery
 * through when `AuthorizationService::canAccessGallery()` is true, and that
 * returns `true` immediately for a cross-brand super admin
 * (AuthorizationService.php:868-870). The harness admin qualifies — it is
 * created with `brand => null` and every `UserRole` including
 * `UserRole::SUPER_ADMIN` (E2ETestUserSeeder.php:20-29). Without that bypass
 * the SPA route would render "Galerie nicht gefunden." for a logged-out reader
 * and "Kein Zugriff auf diese Galerie." for a non-admin one.
 */
export async function seedSelectionGallery(request: APIRequestContext): Promise<SeededGallery> {
    if (selectionGalleryCache) return selectionGalleryCache;

    const suffix = uniqueId();
    selectionGalleryCache = await createGallerySeed(request, {
        name: `UI Review Auswahlgalerie ${suffix}`,
        slug: `ui-review-auswahlgalerie-${suffix}`,
        type: 'selection',
        isPublic: true,
        label: 'selection gallery',
    });
    return selectionGalleryCache;
}

/**
 * Filled state of the delivery gallery's "E-Mail senden..." dialog: the
 * delivery gallery of `seedGallery`, plus one recipient that has opted in to
 * notifications.
 *
 * The opt-in is the load-bearing part of this seed, and it cannot be skipped:
 * `ManagementGalleryActions` renders the trigger unconditionally
 * (ManagementGalleryActions.tsx:47) but disables it unless `canSendMail`, and
 * `ManagementGalleryView` computes that as `(notified_count || 0) > 0`
 * (ManagementGalleryView.tsx:89). `notified_count` is the number of
 * `user_galleries` rows for that gallery with `wants_notifications = true`
 * (GalleryFrontendController::show, GalleryFrontendController.php:124) — a
 * gallery with no recipient therefore renders a disabled button and the
 * manifest's `click` step would sit in Playwright's actionability wait until
 * the test times out, with a timeout as the only symptom.
 *
 * The recipient is produced by `E2ESessionHelper::createIsolatedUser` with
 * `assignGalleryId` + `wantsNotifications`, which runs the whole chain: create a
 * `client` user, assign it the gallery, then POST
 * `/api/galleries/:id/opt-in` with `{ wants_notifications: true }`
 * (E2ESessionHelper.ts:182-187). Both options are required together — the
 * opt-in endpoint is IDOR-guarded by `canAccessGallery()`
 * (NotificationController.php:63), so the user needs the gallery assignment
 * before the opt-in can be stored.
 *
 * The gallery itself is the CACHED one from `seedGallery` on purpose: this
 * seed adds a recipient, not a second gallery, so the dialog entries keep
 * sharing one gallery per worker.
 */
export async function seedNotifiedGallery(request: APIRequestContext): Promise<SeededGallery> {
    if (notifiedGalleryCache) return notifiedGalleryCache;

    const gallery = await seedGallery(request);

    const helper = new E2ESessionHelper(request);
    await helper.createIsolatedUser('client', {
        assignGalleryId: gallery.id,
        wantsNotifications: true,
    });

    // `createIsolatedUser` does not assert the opt-in response (E2ESessionHelper.ts:183),
    // so a 403 from the IDOR guard would stay silent and surface as a click
    // timeout on a disabled button instead. One public read of the same payload
    // the page renders turns that into a legible seed error. `GET
    // /api/galleries/{slug}` is outside the auth:api group
    // (routes/api.php:132) and the gallery is public, so no extra login is
    // needed for this check.
    const check = await request.get(`/api/galleries/${gallery.slug}`, {
        headers: { 'Accept': 'application/json' },
    });
    if (!check.ok()) {
        throw new Error(`UI-review seed: notified_count check failed (${check.status()}): ${await check.text()}`);
    }
    const payload = (await check.json()) as { notified_count?: number };
    if (!payload.notified_count) {
        throw new Error(
            `UI-review seed: gallery ${gallery.slug} still has notified_count=0 after the opt-in, `
            + 'so "E-Mail senden..." would render disabled.',
        );
    }

    notifiedGalleryCache = gallery;
    return notifiedGalleryCache;
}

/**
 * Filled state of a meta-gallery (gallery group): one group created through the
 * management API, so the `/meta/:id` route has a group to load and the
 * `GalleryGroupModal` mounted on that route has a group to edit.
 *
 * The create call is the proven one from
 * tests/e2e/admin/galleries/gallery-modals.spec.ts:44-54 — same endpoint, same
 * `adminHeaders` (the harness admin session), same
 * `{ group: { id } }` response shape. `is_public: true` is chosen over the
 * spec's `null` so the dialog's "Sichtbarkeits-Vorgabe" select shows a
 * concrete policy ("Öffentlich erzwingen") instead of the neutral
 * "Keine Vorgabe" — the group only has to exist, and a definite value is the
 * more informative capture.
 *
 * No slug is needed: the route param is the group's numeric id
 * (`/meta/:id` in App.tsx:106, read by `useMetaGallery` which fetches
 * `/api/management/gallery-groups/{id}`).
 */
export async function seedGalleryGroup(request: APIRequestContext): Promise<SeededGalleryGroup> {
    if (galleryGroupCache) return galleryGroupCache;

    const helper = new E2ESessionHelper(request);
    const cookie = await helper.loginAs(ADMIN_EMAIL, ADMIN_PASSWORD);

    const response = await request.post('/api/management/gallery-groups', {
        data: {
            name: `UI Review Meta-Galerie ${uniqueId()}`,
            is_public: true,
        },
        headers: { 'Accept': 'application/json', 'Cookie': cookie },
    });

    if (!response.ok()) {
        throw new Error(`UI-review seed: gallery group creation failed (${response.status()}): ${await response.text()}`);
    }

    const body = (await response.json()) as { group?: { id?: string | number } };
    const id = body.group?.id;
    if (id === undefined || id === null) {
        throw new Error(`UI-review seed: gallery group creation returned no id: ${JSON.stringify(body)}`);
    }

    galleryGroupCache = { id: String(id) };
    return galleryGroupCache;
}
