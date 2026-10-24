import { test, expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { NetworkHelper } from '../helpers/NetworkHelper';
import { ToastHelper } from '../helpers/ToastHelper';
import {
    createDeliveryGallery,
    createGalleryGroupFixture,
    createOrgFixture,
    setUserGalleryGroups,
    syncOrgMembers,
    STUDIO_ADMIN,
    type FixtureGallery,
    type FixtureUser,
} from '../helpers/AccessScopeFixture';
import {browserAuthHeaders} from '../helpers/BrowserAuthHeaders';

/**
 * A notification opt-in is a **subscription**, never a grant (owner decisions
 * D-45 of 2026-10-20 and D-46 of 2026-10-21).
 *
 * Before those decisions the opt-in was written into the grant pivots
 * (`user_galleries` / `user_gallery_groups`): a gallery opt-in became a client
 * grant and a group opt-in became a grant over the whole subtree of the group.
 * A subscription therefore outlived the membership that had justified it and
 * kept the gallery reachable — and, because the subscription *was* the grant,
 * it also died with it, so the user was left with a row nobody could see or
 * revoke.
 *
 * These specs walk the real client flow (login → gallery → notification
 * toggle → notification preferences) and pin both halves of the contract:
 *
 *  - the opt-in never creates an assignment, and
 *  - the subscription outlives the membership that justified it and can be
 *    revoked again.
 */

const TAG = ['@regression', '@feature:client:notifications'];

/** The gallery view's opt-in toggle ("E-Mail Updates"). */
function galleryOptInToggle(page: Page): Locator {
    return page.getByRole('main')
        .locator('label')
        .filter({ hasText: 'E-Mail Updates' })
        .locator('input[type="checkbox"]');
}

/**
 * Wait until the gallery is findable in the search index.
 *
 * The client reaches its gallery through the search of the dashboard, and the
 * search reads the Scout index (Meilisearch). The index is written when the
 * gallery is created, but that write is asynchronous — the spec waits for the
 * index instead of racing it.
 */
async function waitForSearchIndex(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    gallery: FixtureGallery,
    client: FixtureUser,
): Promise<void> {
    const cookie = await helper.loginAs(client.email, client.password);
    await expect(async () => {
        const response = await request.get(`/api/search?q=${encodeURIComponent(gallery.name)}`, {
            headers: { Accept: 'application/json', Cookie: cookie, ...browserAuthHeaders(cookie)},
        });
        if (!response.ok()) {
            throw new Error(`The search request failed (HTTP ${response.status()})`);
        }
        const body = await response.json() as { galleries?: Array<{ id: string }> };
        const found = (body.galleries ?? []).some((candidate) => candidate.id === gallery.id);
        expect(found, `The gallery "${gallery.name}" is not in the search index yet`).toBe(true);
    }).toPass({ timeout: 30000, intervals: [500, 1000, 2000] });
}

/**
 * Open a gallery from the client dashboard, through the search.
 *
 * The gallery of an organisation member is not in the client's own gallery
 * list (`my_galleries` is the direct grant), so the search is the real path
 * into it — the same path a client uses to find a gallery of their company.
 */
async function openGalleryViaSearch(page: Page, gallery: FixtureGallery): Promise<void> {
    const main = page.getByRole('main');
    const search = main.locator('input[placeholder="Suche in allen Galerien..."]').first();
    await expect(search).toBeVisible({ timeout: 15000 });
    await search.fill(gallery.name);

    const suggestion = main.getByRole('link', { name: gallery.name, exact: true }).first();
    await expect(suggestion).toBeVisible({ timeout: 20000 });
    await suggestion.click();

    await expect(main.getByRole('heading', { name: gallery.name, exact: true })).toBeVisible({ timeout: 20000 });
}

/** The message the client sees when the gallery is out of reach. */
function accessDenied(main: Locator): Locator {
    return main.getByText('Galerie nicht gefunden oder Zugriff verweigert.');
}

test.describe('Notification subscriptions are not assignments (D-45 / D-46)', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    /**
     * The gallery case of D-45: the org membership justifies the access, the
     * opt-in must not become a second justification.
     */
    test('a gallery opt-in does not keep the gallery reachable after the org membership is revoked', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const client = await helper.createIsolatedUser('client');
        const org = await createOrgFixture(request, helper, `E2E OptIn Org ${suffix}`);
        const gallery = await createDeliveryGallery(request, helper, STUDIO_ADMIN, {
            name: `E2E OptIn Galerie ${suffix}`,
            orgIds: [org.id],
        });
        await syncOrgMembers(request, helper, org.id, [client.id]);

        await waitForSearchIndex(request, helper, gallery, client);

        const auth = new AuthHelper(page);
        await auth.login(client.email, client.password);
        await openGalleryViaSearch(page, gallery);

        // The client subscribes to the gallery — through the real toggle.
        // The precondition of this test is the opt-in itself (the request is
        // answered with 200); the *state* the opt-in leaves behind is the
        // subject of the sibling test "a gallery subscription outlives the
        // access that justified it and can be revoked again".
        const toggle = galleryOptInToggle(page);
        await expect(toggle).not.toBeChecked();
        const optIn = new NetworkHelper(page).waitForOptIn();
        await toggle.click();
        const optInResponse = await optIn;
        expect(optInResponse.ok()).toBeTruthy();

        // The membership that justified the access is revoked.
        await syncOrgMembers(request, helper, org.id, []);

        // The subscription must not have become an assignment: the gallery is
        // out of reach for the client.
        await page.reload();
        await expect(accessDenied(page.getByRole('main'))).toBeVisible({ timeout: 20000 });
    });

    /**
     * The other half of D-45: the subscription is the client's own state. It
     * outlives the access that justified it, it stays visible (so it can be
     * revoked) and it can be switched off again.
     */
    test('a gallery subscription outlives the access that justified it and can be revoked again', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const client = await helper.createIsolatedUser('client');
        const org = await createOrgFixture(request, helper, `E2E OptIn Org ${suffix}`);
        const gallery = await createDeliveryGallery(request, helper, STUDIO_ADMIN, {
            name: `E2E OptIn Galerie ${suffix}`,
            orgIds: [org.id],
        });
        await syncOrgMembers(request, helper, org.id, [client.id]);

        await waitForSearchIndex(request, helper, gallery, client);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(client.email, client.password);
        await openGalleryViaSearch(page, gallery);

        const toggle = galleryOptInToggle(page);
        const optIn = new NetworkHelper(page).waitForOptIn();
        await toggle.click();
        await optIn;
        await expect(toggle).toBeChecked();

        // The membership is revoked — the access is gone.
        await syncOrgMembers(request, helper, org.id, []);

        // The subscription stays visible in the preferences: it is the client's
        // own state and has to be revocable.
        await sidebar.navigateTo('Benachrichtigungen');
        const main = page.getByRole('main');
        const preferencesToggle = main.locator(`#notification-gallery-${gallery.id}`);
        await expect(preferencesToggle).toBeChecked({ timeout: 20000 });

        // The name of a gallery the client can no longer reach is not disclosed.
        await expect(main.getByText('Nicht zugängliche Galerie')).toBeVisible();

        // And the subscription can be switched off again.
        const revoke = new NetworkHelper(page).waitForOptIn();
        await preferencesToggle.click();
        const revokeResponse = await revoke;
        expect(revokeResponse.ok(), 'The revoke of an own subscription must be answered with 200').toBeTruthy();
        await new ToastHelper(page).expectNoToast('Fehler beim Speichern der Einstellung.');
        await expect(main.locator(`#notification-gallery-${gallery.id}`)).toHaveCount(0, { timeout: 20000 });
    });

    /**
     * The group case of D-46: the group assignment justifies the access, and a
     * group opt-in must not become a second justification — not even over the
     * whole subtree of the group.
     */
    test('a group opt-in does not keep the group assignment alive', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const client = await helper.createIsolatedUser('client');
        const group = await createGalleryGroupFixture(request, helper, STUDIO_ADMIN, `E2E OptIn Ordner ${suffix}`);
        const gallery = await createDeliveryGallery(request, helper, STUDIO_ADMIN, {
            name: `E2E OptIn Galerie ${suffix}`,
            groupId: group.id,
        });
        await setUserGalleryGroups(request, helper, client.id, [group.id]);

        await waitForSearchIndex(request, helper, gallery, client);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(client.email, client.password);

        // The group is listed in the preferences — the client is a member of it.
        await sidebar.navigateTo('Benachrichtigungen');
        const main = page.getByRole('main');
        const groupToggle = main.locator(`#notification-group-${group.id}`);
        await expect(groupToggle).not.toBeChecked({ timeout: 20000 });

        // The client subscribes to the group — through the real toggle.
        // The precondition of this test is the opt-in itself (the request is
        // answered with 200); the *state* the opt-in leaves behind is the
        // subject of the sibling test "a group subscription outlives the group
        // assignment and can be revoked again".
        const optIn = new NetworkHelper(page).waitForOptIn();
        await groupToggle.click();
        const optInResponse = await optIn;
        expect(optInResponse.ok()).toBeTruthy();

        // The group assignment grants the subtree — the client reaches the gallery.
        await openGalleryViaSearch(page, gallery);

        // The group assignment is revoked.
        await setUserGalleryGroups(request, helper, client.id, []);

        // The subscription must not have kept the assignment alive.
        await page.reload();
        await expect(accessDenied(page.getByRole('main'))).toBeVisible({ timeout: 20000 });
    });

    /**
     * The other half of D-46: the group subscription is not part of the grant.
     * It outlives the assignment, it stays visible (so it can be revoked) and
     * it can be switched off again.
     */
    test('a group subscription outlives the group assignment and can be revoked again', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const client = await helper.createIsolatedUser('client');
        const group = await createGalleryGroupFixture(request, helper, STUDIO_ADMIN, `E2E OptIn Ordner ${suffix}`);
        await setUserGalleryGroups(request, helper, client.id, [group.id]);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(client.email, client.password);

        await sidebar.navigateTo('Benachrichtigungen');
        const main = page.getByRole('main');
        const groupToggle = main.locator(`#notification-group-${group.id}`);
        await expect(groupToggle).not.toBeChecked({ timeout: 20000 });

        const optIn = new NetworkHelper(page).waitForOptIn();
        await groupToggle.click();
        await optIn;
        await expect(groupToggle).toBeChecked();

        // The group assignment is revoked — the access is gone.
        await setUserGalleryGroups(request, helper, client.id, []);

        // The subscription is still the client's own state and stays visible.
        await page.reload();
        const groupToggleAfter = main.locator(`#notification-group-${group.id}`);
        await expect(groupToggleAfter).toBeChecked({ timeout: 20000 });

        // The name of a group the client can no longer reach is not disclosed.
        await expect(main.getByText('Nicht zugänglicher Ordner')).toBeVisible();

        // And the subscription can be switched off again.
        const revoke = new NetworkHelper(page).waitForOptIn();
        await groupToggleAfter.click();
        const revokeResponse = await revoke;
        expect(revokeResponse.ok(), 'The revoke of an own subscription must be answered with 200').toBeTruthy();
        await new ToastHelper(page).expectNoToast('Fehler beim Speichern der Einstellung.');
        await expect(main.locator(`#notification-group-${group.id}`)).toHaveCount(0, { timeout: 20000 });
    });
});
