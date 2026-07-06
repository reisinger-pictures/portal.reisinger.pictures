import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('Coupon Checkout Re-validation', () => {
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

    async function setupGalleryWithPhoto(page: Page): Promise<{ galleryUrl: string; galleryName: string }> {
        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        await auth.login(photogUser.email, photogUser.password, 'http://buy.localhost:4321/');

        const galleryName = `Coupon Chk ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');

        await upload.uploadSampleImage();
        const galleryUrl = page.url();
        await auth.logout('http://buy.localhost:4321/');

        return { galleryUrl, galleryName };
    }

    async function addItemToCart(page: Page, galleryName: string) {
        await page.locator('main').getByText(galleryName).first().click();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await expect(page).toHaveURL(/\/photos\//, { timeout: 15000 });
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
    }

    test('Invalid coupon shows error at checkout', { tags: ['@feature:client:coupon'] }, async ({ page }) => {
        const { galleryName } = await setupGalleryWithPhoto(page);

        await page.route('**/api/coupons/validate', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    valid: false,
                    error: 'Der Rabattcode ist nicht mehr gültig.',
                }),
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('INVALID');
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByRole('alert').first()).toContainText('Der Rabattcode ist nicht mehr gültig.');
    });

    test('Expired coupon shows error at checkout', { tags: ['@feature:client:coupon'] }, async ({ page }) => {
        const { galleryName } = await setupGalleryWithPhoto(page);

        await page.route('**/api/coupons/validate', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    valid: true,
                    coupon: { id: 1, code: 'EXPIRED', type: 'fixed', value: 10, scope_type: 'global' },
                    discount_cents: 1000,
                }),
            });
        });

        await page.route('**/api/orders/checkout', async route => {
            await route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({
                    error: 'Der Rabattcode ist nicht mehr gültig.',
                }),
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('EXPIRED');
        await page.getByRole('button', { name: 'Anwenden' }).click();
        await expect(page.getByText('EXPIRED')).toBeVisible({ timeout: 5000 });

        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        await form.fillCheckoutForm({
            name: 'Expired Coupon Tester',
            street: 'Teststr. 1',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true,
        });

        await expect(page.getByRole('button', { name: 'Zahlungspflichtig bestellen' })).toBeEnabled({ timeout: 5000 });
        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();
        await expect(page.locator('.toast')).toContainText('Der Rabattcode ist nicht mehr gültig.');
    });

    test('Valid coupon applies discount', { tags: ['@feature:client:coupon'] }, async ({ page }) => {
        const { galleryName } = await setupGalleryWithPhoto(page);

        await page.route('**/api/coupons/validate', async route => {
            const body = JSON.parse(route.request().postData() || '{}');
            if (body.code === 'DISCOUNT10') {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        valid: true,
                        coupon: { id: 1, code: 'DISCOUNT10', type: 'fixed', value: 10, scope_type: 'global' },
                        discount_cents: 1000,
                    }),
                });
            } else {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ valid: false, error: 'Rabattcode nicht gefunden.' }),
                });
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('DISCOUNT10');
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByText(/−/)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText('DISCOUNT10')).toBeVisible();
    });
});
