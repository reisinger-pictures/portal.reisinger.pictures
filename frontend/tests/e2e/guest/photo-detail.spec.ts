import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Guest Photo Detail (G4)', () => {
    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Guest can view public photo details', { tags: ['@smoke', '@feature:guest'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);

        await auth.login(photogUser.email, photogUser.password);

        const galleryName = `Public Gallery ${Math.random().toString(36).substring(2, 10)}`;
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({
            name: galleryName,
            type: 'Delivery (Downloads)',
            visibility: 'Öffentlich (Für alle sichtbar)',
        });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.click();

        await expect(page.getByRole('heading', { name: galleryName })).toBeVisible({ timeout: 10000 });

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        const galleryUrl = page.url();

        await auth.logout();

        await page.goto(galleryUrl);

        await expect(page.locator('main')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('h1:has-text("' + galleryName + '")').first()).toBeVisible({ timeout: 10000 });
        await expect(page.locator('a.pswp-item').first()).toBeVisible({ timeout: 15000 });
    });
});
