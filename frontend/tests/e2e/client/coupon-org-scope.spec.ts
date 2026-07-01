import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Organisation Scope Coupons', () => {
    let helper: E2ESessionHelper;
    let photogUser: { email: string; password: string; id: string };
    let buyerUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer', { brand: 'srp' });
        buyerUser = await helper.createIsolatedUser('power_user', { brand: 'srp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setupGalleryWithPhoto(page: Page): Promise<string> {
        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        await auth.login(photogUser.email, photogUser.password, 'http://buy.localhost:4321/');

        const galleryName = `OrgScope ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        await upload.uploadSampleImage();
        const galleryUrl = page.url();
        await auth.logout('http://buy.localhost:4321/');

        return galleryUrl;
    }

    async function addItemToCart(page: Page, galleryUrl: string) {
        await page.goto(galleryUrl);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
    }

    test('User with matching tenant validates successfully', async ({ page }) => {
        const galleryUrl = await setupGalleryWithPhoto(page);

        await page.route('**/api/coupons/validate', async route => {
            const body = JSON.parse(route.request().postData() || '{}');
            if (body.code === 'ORG10') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        valid: true,
                        coupon: { id: 1, code: 'ORG10', type: 'fixed', value: 10, scope_type: 'organisation', scope_id: 'tenant-1' },
                        discount_cents: 1000,
                    }),
                });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemToCart(page, galleryUrl);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('ORG10');
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByText('ORG10')).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(/−/)).toBeVisible();
    });

    test('User without matching tenant fails validation', async ({ page }) => {
        const galleryUrl = await setupGalleryWithPhoto(page);

        await page.route('**/api/coupons/validate', async route => {
            const body = JSON.parse(route.request().postData() || '{}');
            if (body.code === 'ORG10') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        valid: false,
                        error: 'Dieser Rabattcode gilt nur für berechtigte Organisationen.',
                    }),
                });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemToCart(page, galleryUrl);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('ORG10');
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByRole('alert')).toContainText('Dieser Rabattcode gilt nur für berechtigte Organisationen.');
    });
});
