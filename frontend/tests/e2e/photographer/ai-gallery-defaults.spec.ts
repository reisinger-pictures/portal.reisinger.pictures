import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

// AT-03-E4 is present below and mocks nothing: it creates a real delivery
// gallery through the UI and opens the metadata-defaults dialog against the
// live API. The
// earlier note claiming this test had been removed because it stubbed
// /api/ai/status, /api/auth/me and /api/ai/generate-metadata-text was wrong on
// both counts — those three paths occurred only inside the note, and no
// `page.route`/`route.fulfill` exists in this file. It is legitimate coverage
// of the parent dialog.
//
// The one boundary this file cannot cross is the generation itself: the nested
// AIGalleryDefaultsModal ("KI generieren") calls `useAI().isAvailable` and then
// POSTs to the AI service. Neither is available locally, and stubbing an
// internal /api/ai/* route is forbidden by 04-testing-guidelines.md. AT-03-E5
// therefore opens the nested dialog and asserts its state, without triggering
// a generation.

test.describe('AI Gallery Defaults — Generate & Preview', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('AT-03-E4: opens defaults modal and shows metadata form', { tag: ['@feature:photographer:ai'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Defaults ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const metadataButton = page.getByRole('button', { name: 'Vorgaben...' });
        await expect(metadataButton).toBeVisible({ timeout: 10000 });
        await metadataButton.click();

        await expect(page.getByRole('heading', { name: 'Metadaten-Vorgaben' })).toBeVisible({ timeout: 5000 });
    });

    test('AT-03-E5: opens the nested AI suggestion modal from the metadata defaults', { tag: ['@feature:photographer:ai'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Defaults ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const metadataButton = page.getByRole('button', { name: 'Vorgaben...' });
        await expect(metadataButton).toBeVisible({ timeout: 10000 });
        await metadataButton.click();

        const defaultsDialog = page.getByRole('dialog', { name: 'Metadaten-Vorgaben' });
        await expect(defaultsDialog).toBeVisible({ timeout: 5000 });

        // The parent footer's "KI generieren" opens the nested dialog. Scope to
        // the parent here: once the child is open, both dialogs expose a button
        // of the same name.
        await defaultsDialog.getByRole('button', { name: 'KI generieren' }).click();

        // The nested dialog is rendered inside the parent's box, so both stay
        // open at the same time.
        const aiDialog = page.getByRole('dialog', { name: 'KI-Vorschlag für Vorgaben' });
        await expect(aiDialog).toBeVisible({ timeout: 5000 });
        await expect(defaultsDialog).toBeVisible();

        // Content of the nested dialog. The generation button is the live-service
        // boundary: it is only asserted as rendered, never clicked, because a
        // real generation needs a live AI service and stubbing /api/ai/* is
        // forbidden (04-testing-guidelines.md).
        await expect(aiDialog.getByRole('textbox')).toBeVisible();
        await expect(aiDialog.getByRole('button', { name: 'KI generieren' })).toBeVisible();
    });
});
