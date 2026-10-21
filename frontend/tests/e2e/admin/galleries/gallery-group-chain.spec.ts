import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ToastHelper } from '../../helpers/ToastHelper';
import {
    assignPhotographerToGroup,
    createDeliveryGallery,
    createGalleryGroupFixture,
    STUDIO_ADMIN,
} from '../../helpers/AccessScopeFixture';

/**
 * The management path of a gallery group: rename and delete.
 *
 * `AuthorizationService::canManageGalleryGroup()` decides who may rename,
 * reparent or delete a group. It resolves the chain of the group and the chain
 * of every gallery of its subtree **set-based** (owner decision: the read path
 * and the management path resolve once, features/auth/01-roles-and-access.md
 * §6). The optimisation must not change a single decision.
 *
 * The spec pins that decision on the shape the owner checks: a group that
 * **holds galleries** and a photographer who is **assigned** to it.
 *
 *  - renaming the group keeps the access — the assignment is made on the id of
 *    the group, not on its name, and the decision stays "allowed";
 *  - deleting the group removes the access — the gallery of the group falls
 *    out of the photographer's subtree, and the photographer may not manage
 *    what no longer exists.
 */

const TAG = ['@regression', '@feature:admin:galleries'];

test.describe('The group chain of the management path', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('renaming a group keeps the access of the assigned photographer, deleting it removes the access', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const photographer = await helper.createIsolatedUser('photographer');

        const group = await createGalleryGroupFixture(request, helper, STUDIO_ADMIN, `E2E Kette ${suffix}`);
        // The group holds a **restricted** gallery: the photographer reaches it
        // through the assignment of the group and through nothing else.
        const gallery = await createDeliveryGallery(request, helper, STUDIO_ADMIN, {
            name: `E2E Kette Galerie ${suffix}`,
            groupId: group.id,
            restricted: true,
        });
        await assignPhotographerToGroup(request, helper, group.id, photographer.id);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(photographer.email, photographer.password);
        await sidebar.navigateTo('Galerien & Ordner');

        const main = page.getByRole('main');
        const groupSummary = main.locator('summary').filter({ hasText: group.name });
        await expect(groupSummary).toBeVisible({ timeout: 20000 });

        // --- The rename: the decision stays "allowed" and the access stays. ---
        await groupSummary.locator('button[data-tip="Ordner bearbeiten"]').click();
        const renameDialog = main.getByRole('dialog', { name: 'Meta-Galerie bearbeiten' });
        await expect(renameDialog).toBeVisible({ timeout: 20000 });

        const renamedName = `${group.name} Umbenannt`;
        await renameDialog.locator('input[name="name"]').fill(renamedName);
        const renameResponse = page.waitForResponse((response) =>
            response.request().method() === 'PUT' && response.url().includes(`/api/management/gallery-groups/${group.id}`),
        );
        await renameDialog.getByRole('button', { name: 'Speichern' }).click();
        expect((await renameResponse).ok(), 'The assigned photographer must be allowed to rename the group').toBeTruthy();
        await new ToastHelper(page).expectToast('Ordner erfolgreich aktualisiert.');
        await expect(main.locator('summary').filter({ hasText: renamedName })).toBeVisible({ timeout: 20000 });

        // The access is kept: the photographer still reaches the gallery of the
        // renamed group.
        await main.getByRole('button', { name: 'Alle ausklappen' }).click();
        const galleryLink = main.locator('a').filter({ hasText: gallery.name }).first();
        await expect(galleryLink).toBeVisible({ timeout: 20000 });
        await galleryLink.click();
        // The management heading carries the pencil button, so the accessible name
        // is longer than the gallery name — the match is a substring.
        await expect(main.getByRole('heading', { name: gallery.name }).first()).toBeVisible({ timeout: 20000 });

        // --- The delete: the group goes, and with it the access. ---
        await sidebar.navigateTo('Galerien & Ordner');
        const renamedSummary = main.locator('summary').filter({ hasText: renamedName });
        await expect(renamedSummary).toBeVisible({ timeout: 20000 });
        await renamedSummary.locator('button[data-tip="Ordner bearbeiten"]').click();
        const deleteDialog = main.getByRole('dialog', { name: 'Meta-Galerie bearbeiten' });
        await expect(deleteDialog).toBeVisible({ timeout: 20000 });
        await deleteDialog.getByRole('button', { name: 'Löschen', exact: true }).click();

        // The confirmation is the global confirm dialog, which lives outside the
        // `<main>` landmark by design (the repo convention for it is the
        // `.modal-global` scope).
        const confirmDialog = page.locator('.modal-global');
        await expect(confirmDialog).toBeVisible({ timeout: 20000 });
        const deleteResponse = page.waitForResponse((response) =>
            response.request().method() === 'DELETE' && response.url().includes(`/api/management/gallery-groups/${group.id}`),
        );
        await confirmDialog.getByRole('button', { name: 'Löschen', exact: true }).click();
        expect((await deleteResponse).ok(), 'The assigned photographer must be allowed to delete the group').toBeTruthy();
        await new ToastHelper(page).expectToast('Ordner erfolgreich gelöscht.');

        // The group is gone from the tree...
        await expect(main.locator('summary').filter({ hasText: renamedName })).toHaveCount(0, { timeout: 20000 });
        // ...and with it the gallery: the tree only offers what the actor may
        // reach, and the assignment covered the subtree of the deleted group.
        await expect(main.locator('a').filter({ hasText: gallery.name })).toHaveCount(0, { timeout: 20000 });

        // The access itself is gone: the gallery endpoint refuses the assigned
        // photographer. (The tree offers no link to the gallery any more, so
        // the access cannot be shown by a click — the endpoint is the contract
        // the button and the download would have used.)
        const cookie = await helper.loginAs(photographer.email, photographer.password);
        const galleryResponse = await request.get(`/api/galleries/${gallery.slug}`, {
            headers: { Accept: 'application/json', Cookie: cookie },
        });
        expect(
            galleryResponse.status(),
            'The gallery of a deleted group must not be reachable for the assigned photographer',
        ).toBe(403);
    });
});
