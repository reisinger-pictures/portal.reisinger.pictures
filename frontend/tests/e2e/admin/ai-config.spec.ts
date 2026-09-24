import { expect, test } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { UploadHelper } from '../helpers/UploadHelper';

// The controlled disabled/unconfigured/available states are covered by the
// useAI and ManagementDashboard Vitest regressions. They cannot be selected
// reliably through the production backend without changing the deployed AI
// configuration, so they are not E2E route fixtures.
test.describe('AI Configuration Page & Generate Button', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('AI configuration page loads for super_admin', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        const user = await helper.createIsolatedUser('super_admin');
        const auth = new AuthHelper(page);
        await auth.login(user.email, user.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Einstellungen');

        const main = page.getByRole('main');
        await expect(main).toBeVisible({ timeout: 10000 });
        // Exakter H1-Name statt Regex /Einstellungen/i: seit der Brand-Settings-
        // Card ("Markeneinstellungen", h2) matcht die Regex 2 Headings
        // (strict mode violation).
        await expect(main.getByRole('heading', { name: 'System-Einstellungen' })).toBeVisible({ timeout: 5000 });
    });

    test('Photographer sees the AI generate control in a real delivery photo detail flow', { tag: ['@feature:admin:ai'] }, async ({ page }) => {
        const user = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        await auth.login(user.email, user.password);

        const galleryName = `AI Detail ${Math.random().toString(36).substring(2, 10)}`;
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await new UploadHelper(page).uploadSampleImage();

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: galleryName })).toBeVisible({ timeout: 15000 });
        await main.getByRole('button', { name: 'Details & Metadaten' }).click();
        await expect(main.getByRole('heading', { name: 'IPTC Metadaten' })).toBeVisible();
        await expect(main.getByRole('button', { name: 'KI generieren' })).toBeVisible();
    });
});
