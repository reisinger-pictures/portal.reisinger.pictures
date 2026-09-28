import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

test.describe('Rating status modal', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('opens the ratings & status dialog for a selection gallery', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `Ratings Modal ${Math.random().toString(36).substring(2, 8)}`;
        // "Bewertungen..." only renders for `type === 'selection'`, so the
        // gallery must be created as a selection gallery through the real modal.
        await galleryHelper.createAndOpenSelectionGallery(galleryName);

        const ratingsButton = page.getByRole('button', { name: 'Bewertungen...' });
        await expect(ratingsButton).toBeVisible({ timeout: 15000 });
        await ratingsButton.click();

        const dialog = page.getByRole('dialog', { name: 'Bewertungen & Status' });
        await expect(dialog).toBeVisible({ timeout: 5000 });
        // Both sections are fed by the real rating endpoints for an empty
        // gallery, so their headings are the proof that the modal rendered its
        // content rather than only its shell.
        await expect(dialog.getByRole('heading', { name: 'Beteiligte Personen' })).toBeVisible({ timeout: 15000 });
        await expect(dialog.getByRole('heading', { name: 'Detaillierte Auswertungen (Bild-Bewertungen)' })).toBeVisible();
    });
});
