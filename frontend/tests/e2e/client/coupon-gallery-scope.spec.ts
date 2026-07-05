import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Gallery-Scoped Coupons (SRP)', () => {
    let helper: E2ESessionHelper;
    let photogUser: { email: string; password: string; id: string };
    let srpAdmin: { email: string; password: string; id: string };
    let buyerUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer', { brand: 'srp' });
        srpAdmin = await helper.createIsolatedUser('admin', { brand: 'srp' });
        buyerUser = await helper.createIsolatedUser('power_user', { brand: 'srp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Gallery-scoped coupon applies to matching gallery', async ({ page }) => {
        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password, 'http://buy.localhost:4321/');
        const galleryName = `Gallery Coupon ${Math.random().toString(36).substring(2, 10)}`;
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');
        if (!galleryId) throw new Error('Gallery ID not available');
        await upload.uploadSampleImage();
        await auth.logout('http://buy.localhost:4321/');

        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const couponCode = `GAL-${Math.random().toString(36).substring(2, 8)}`;
        const createRes = await page.evaluate(async ({ code, galleryId }: { code: string; galleryId: string }) => {
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'fixed',
                    value: 10,
                    scope_type: 'gallery',
                    scope_id: galleryId,
                    active: true,
                }),
            });
            return res.json();
        }, { code: couponCode, galleryId });
        if (!createRes?.coupon?.id) throw new Error(`Coupon creation failed: ${JSON.stringify(createRes)}`);
        helper.trackCoupon(createRes.coupon.id);
        await auth.logout('http://buy.localhost:4321/');

        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');
        await page.locator('main').getByText(galleryName).first().click();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');

        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        await page.getByLabel('Rabattcode').fill(couponCode);
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByText(couponCode)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(/−/)).toBeVisible();
        await auth.logout('http://buy.localhost:4321/');
    });

    test('Gallery-scoped coupon rejected for non-matching gallery', async ({ page }) => {
        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password, 'http://buy.localhost:4321/');
        const galleryAName = `Gallery A ${Math.random().toString(36).substring(2, 10)}`;
        const galleryAId = await galleryHelper.createAndOpenDeliveryGallery(galleryAName, 'Öffentlich (Für alle sichtbar)');
        if (!galleryAId) throw new Error('Gallery A ID not available');
        await upload.uploadSampleImage();

        const galleryBName = `Gallery B ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryBName, 'Öffentlich (Für alle sichtbar)');
        await upload.uploadSampleImage();
        await auth.logout('http://buy.localhost:4321/');

        await auth.login(srpAdmin.email, srpAdmin.password, 'http://buy.localhost:4321/');
        const couponCode = `GAL-${Math.random().toString(36).substring(2, 8)}`;
        const createRes = await page.evaluate(async ({ code, galleryId }: { code: string; galleryId: string }) => {
            const res = await fetch('/api/management/coupons', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    code,
                    type: 'fixed',
                    value: 10,
                    scope_type: 'gallery',
                    scope_id: galleryId,
                    active: true,
                }),
            });
            return res.json();
        }, { code: couponCode, galleryId: galleryAId });
        if (createRes?.coupon?.id) helper.trackCoupon(createRes.coupon.id);
        await auth.logout('http://buy.localhost:4321/');

        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');
        await page.locator('main').getByText(galleryBName).first().click();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');

        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        await page.getByLabel('Rabattcode').fill(couponCode);
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByRole('alert').first()).toContainText(/nicht gültig|not valid/, { timeout: 5000 });
        await auth.logout('http://buy.localhost:4321/');
    });
});
