import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Per-Sub-Gallery Coupons', () => {
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

    async function setupGalleryWithPhotos(page: Page, count: number): Promise<string> {
        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        await auth.login(photogUser.email, photogUser.password, 'http://buy.localhost:4321/');

        const galleryName = `SubGal ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        for (let i = 0; i < count; i++) {
            await upload.uploadSampleImage();
        }

        const galleryUrl = page.url();
        await auth.logout('http://buy.localhost:4321/');

        return galleryUrl;
    }

    async function addItemsToCart(page: Page, galleryUrl: string, count: number) {
        await page.goto(galleryUrl);
        await expect(page.locator('a.pswp-item').first()).toBeVisible({ timeout: 15000 });

        for (let i = 0; i < count; i++) {
            await page.locator('a.pswp-item').nth(i).click();
            await page.getByRole('button', { name: 'In den Warenkorb' }).click();
            await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
        }
    }

    test('Per-sub-gallery free items per gallery', async ({ page }) => {
        const galleryUrl = await setupGalleryWithPhotos(page, 3);

        await page.route('**/api/coupons/validate', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    valid: true,
                    coupon: {
                        id: 1,
                        code: 'SUBGAL3',
                        type: 'free_items',
                        value: 3,
                        scope_type: 'meta_gallery',
                        per_sub_gallery: true,
                    },
                    discount_cents: 1500,
                }),
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://buy.localhost:4321/');

        await addItemsToCart(page, galleryUrl, 2);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        const couponInput = page.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({ timeout: 5000 });
        await couponInput.fill('SUBGAL3');
        await page.getByRole('button', { name: 'Anwenden' }).click();

        await expect(page.getByText('SUBGAL3')).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(/−/)).toBeVisible();
    });
});
