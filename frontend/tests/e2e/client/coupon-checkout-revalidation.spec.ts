import {test, expect, type Page} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {FormHelper} from '../helpers/FormHelper';

test.describe('Coupon Checkout Re-validation', () => {
    let helper: E2ESessionHelper;
    let photogUser: {email: string; password: string; id: string};
    let buyerUser: {email: string; password: string; id: string};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer', {brand: 'rp'});
        buyerUser = await helper.createIsolatedUser('power_user', {brand: 'rp'});
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setupGalleryWithPhoto(page: Page): Promise<{galleryName: string}> {
        const suffix = Math.random().toString(36).substring(2, 10);
        const preset = await helper.createVolumePreset({
            name: `Coupon Checkout Preset ${suffix}`,
            tiers: [{min_quantity: 0, price_cents: 4000}],
        });
        helper.trackPreset(String(preset.id));

        const auth = new AuthHelper(page);
        const upload = new UploadHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        await auth.login(photogUser.email, photogUser.password, 'http://localhost:4321/');

        const galleryName = `Coupon Chk ${suffix}`;
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');
        if (!galleryId) throw new Error('Gallery ID not available');
        await helper.updateGalleryLicensing(galleryId, 'volume_licensing', preset.id);

        await upload.uploadSampleImage();
        await auth.logout('http://localhost:4321/');

        return {galleryName};
    }

    async function addItemToCart(page: Page, galleryName: string) {
        const main = page.getByRole('main');
        await main.getByText(galleryName).first().click();
        await expect(main.locator('a.pswp-item img').first()).toBeVisible({timeout: 15000});
        await main.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await expect(page).toHaveURL(/\/photos\//, {timeout: 15000});
        await main.getByRole('button', {name: 'In den Warenkorb'}).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
    }

    async function createCouponFixture(
        code: string,
        type: 'fixed' | 'percentage',
        value: number,
        expiresAt?: string,
    ): Promise<string> {
        const response = await helper.createCoupon({
            code,
            type,
            value,
            scope_type: 'global',
            active: true,
            ...(expiresAt ? {expires_at: expiresAt} : {}),
        });
        const id = response.coupon?.id;
        if (!id) throw new Error(`Coupon ${code} was created without an id`);
        helper.trackCoupon(String(id));
        return String(id);
    }

    test('Invalid coupon shows error at checkout', {tag: ['@feature:client:coupon']}, async ({page}) => {
        const {galleryName} = await setupGalleryWithPhoto(page);

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();

        const couponInput = main.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({timeout: 5000});
        await couponInput.fill(`INVALID${Math.random().toString(36).substring(2, 8).toUpperCase()}`);
        await main.getByRole('button', {name: 'Anwenden'}).click();

        await expect(main.getByRole('alert')).toContainText(/Coupon code not found|nicht gefunden/i);
    });

    test('Expired coupon shows error at checkout', {tag: ['@feature:client:coupon']}, async ({page}) => {
        const {galleryName} = await setupGalleryWithPhoto(page);
        const code = `EXPIRED${Math.random().toString(36).substring(2, 8).toUpperCase()}`;
        const couponId = await createCouponFixture(
            code,
            'fixed',
            10,
            new Date(Date.now() + 60 * 60 * 1000).toISOString(),
        );

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();

        const couponInput = main.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({timeout: 5000});
        await couponInput.fill(code);
        await main.getByRole('button', {name: 'Anwenden'}).click();
        await expect(main.getByTestId('coupon-input')).toContainText(code);

        // Revalidation is performed by the real checkout endpoint after the
        // preview succeeded. Expiring the persisted coupon makes the second
        // request fail at the server's locked revalidation checkpoint.
        await helper.updateCoupon(couponId, {expires_at: new Date(Date.now() - 60 * 1000).toISOString()});

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

        await expect(main.getByRole('button', {name: 'Zahlungspflichtig bestellen'})).toBeEnabled({timeout: 5000});
        const checkoutResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/orders/checkout' && response.request().method() === 'POST';
        });
        await main.getByRole('button', {name: 'Zahlungspflichtig bestellen'}).click();
        const checkoutResponse = await checkoutResponsePromise;
        expect(checkoutResponse.status()).toBe(422);
        const checkoutError = await checkoutResponse.json() as {error?: string};
        expect(checkoutError.error).toBe('Der Rabattcode ist nicht mehr gültig.');
        await expect(page.getByRole('alert').filter({hasText: 'Der Rabattcode ist nicht mehr gültig.'})).toBeVisible();
    });

    test('Valid coupon applies discount', {tag: ['@feature:client:coupon']}, async ({page}) => {
        const {galleryName} = await setupGalleryWithPhoto(page);
        const code = `DISCOUNT${Math.random().toString(36).substring(2, 8).toUpperCase()}`;
        await createCouponFixture(code, 'fixed', 10);

        const auth = new AuthHelper(page);
        await auth.login(buyerUser.email, buyerUser.password, 'http://localhost:4321/');

        await addItemToCart(page, galleryName);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();

        const couponInput = main.getByLabel('Rabattcode');
        await expect(couponInput).toBeVisible({timeout: 5000});
        await couponInput.fill(code);
        await main.getByRole('button', {name: 'Anwenden'}).click();

        await expect(main.getByTestId('cart-discount')).toContainText('10.00 €');
        await expect(main.getByTestId('coupon-input')).toContainText(code);
    });
});
