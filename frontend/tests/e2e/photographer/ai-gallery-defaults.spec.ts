import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

// NOTE: The AT-03-E4 test was removed because it mocks /api/ai/status,
// /api/auth/me, and /api/ai/generate-metadata-text — all internal endpoints
// whose mocking is forbidden by 04-testing-guidelines.md. The AI text
// generation flow cannot run against a real backend without a live AI service.
//
// Keep file scaffold for future real-backend AI tests.

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

    test('AT-03-E4: opens defaults modal and shows metadata form', { tags: ['@feature:photographer:ai'] }, async ({ page }) => {
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
});
