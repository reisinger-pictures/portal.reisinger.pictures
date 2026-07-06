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

    test('Gallery-scoped coupon applies to matching gallery', { tag: ['@feature:client:coupon'] }, async ({ page, request }) => {
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
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });
        const couponCode = `GAL-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        const createRes = await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 10, scope_type: 'gallery', scope_id: galleryId, active: true },
            headers: couHeaders,
        });
        if (!createRes.ok()) throw new Error(`Coupon creation failed: ${await createRes.text()}`);
        const createResJson = await createRes.json();
        if (createResJson?.coupon?.id) helper.trackCoupon(createResJson.coupon.id);
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

    test('Gallery-scoped coupon rejected for non-matching gallery', { tag: ['@feature:client:coupon'] }, async ({ page, request }) => {
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
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'srp' });
        const couponCode = `GAL-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://buy.localhost:4321/' };
        const createRes2 = await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 10, scope_type: 'gallery', scope_id: galleryAId, active: true },
            headers: couHeaders,
        });
        const createRes2Json = await createRes2.json();
        if (createRes2Json?.coupon?.id) helper.trackCoupon(createRes2Json.coupon.id);
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
