import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

test.describe('Gallery access modal', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        // Two roles are required to reach the button: the management gallery
        // action row only renders for a photographer, and "Zugriff..." exists
        // only when `onOpenAccess` is passed, i.e. for an admin. A single-role
        // user never sees the control.
        testUser = await helper.createIsolatedUser('photographer', { additionalRoles: ['admin'] });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('opens the user-access dialog from a gallery', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `Access Modal ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const accessButton = page.getByRole('button', { name: 'Zugriff...' });
        await expect(accessButton).toBeVisible({ timeout: 15000 });
        await accessButton.click();

        // The dialog is rendered only once /api/management/users has resolved,
        // so this wait is also the loading boundary.
        const dialog = page.getByRole('dialog', { name: 'Nutzer-Zugriff verwalten' });
        await expect(dialog).toBeVisible({ timeout: 15000 });
        // The dialog names the gallery it manages...
        await expect(dialog.getByText(galleryName, { exact: true })).toBeVisible();
        // ...and offers the user search field that scopes the list.
        await expect(dialog.getByRole('textbox')).toBeVisible();
    });
});
