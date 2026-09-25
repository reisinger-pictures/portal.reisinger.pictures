import {expect, test, type APIRequestContext, type Page} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {FormHelper} from '../helpers/FormHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

type User = {email: string; password: string; id: string};
type GalleryFixture = {id: string; name: string; slug: string};
type GalleryDefinition = {
    name: string;
    licensingMode: 'scope_licensing' | 'volume_licensing';
    volumePresetId?: string | number | null;
};
type LicenseCatalog = {
    use_cases: Array<{
        id: string;
        name: string;
        base_price: number | string;
    }>;
};

const money = (cents: number) => `${(cents / 100).toFixed(2)} €`;

async function createGalleryFixtures(
    page: Page,
    helper: E2ESessionHelper,
    photographer: User,
    definitions: GalleryDefinition[],
): Promise<GalleryFixture[]> {
    const auth = new AuthHelper(page);
    const galleries = new GalleryHelper(page, helper);
    const upload = new UploadHelper(page);
    const fixtures: GalleryFixture[] = [];

    await auth.login(photographer.email, photographer.password);
    for (const definition of definitions) {
        const id = await galleries.createAndOpenDeliveryGallery(
            definition.name,
            'Öffentlich (Für alle sichtbar)',
        );
        if (!id) throw new Error(`Gallery ${definition.name} was created without an id`);
        await upload.uploadSampleImage();
        await helper.updateGalleryLicensing(
            id,
            definition.licensingMode,
            definition.volumePresetId ?? null,
        );
        const slug = new URL(page.url()).pathname.split('/').filter(Boolean).pop();
        if (!slug) throw new Error(`Gallery ${definition.name} has no slug`);
        fixtures.push({id, name: definition.name, slug});
    }
    await auth.logout();
    return fixtures;
}

async function addFirstPhotoToCart(page: Page, galleryName: string, useCaseName?: string) {
    const main = page.getByRole('main');
    await main.getByText(galleryName).first().click();
    await expect(main.locator('a.pswp-item img').first()).toBeVisible({timeout: 15000});
    await main.getByRole('button', {name: 'Bild öffnen'}).first().click();
    await expect(page).toHaveURL(/\/photos\//, {timeout: 15000});
    if (useCaseName) {
        await main.getByText(useCaseName, {exact: true}).first().click();
    }
    await main.getByRole('button', {name: 'In den Warenkorb'}).click();
    await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
}

async function getCheapestCatalogUseCase(request: APIRequestContext): Promise<{name: string; priceCents: number}> {
    const response = await request.get('/api/settings/license-catalog', {
        headers: {Accept: 'application/json'},
    });
    if (!response.ok()) {
        throw new Error(`License catalog request failed: ${await response.text()}`);
    }

    const catalog = await response.json() as LicenseCatalog;
    const candidates = catalog.use_cases
        .map(useCase => ({name: useCase.name, priceCents: Number(useCase.base_price)}))
        .filter(useCase => Number.isFinite(useCase.priceCents) && useCase.priceCents > 0)
        .sort((left, right) => left.priceCents - right.priceCents);
    const selected = candidates[0];
    if (!selected) throw new Error('License catalog has no positive-price use case');
    return selected;
}

async function openCart(page: Page, sidebar: SidebarHelper) {
    await sidebar.navigateTo('Warenkorb');
    await expect(page.getByRole('main').getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();
}

test.describe('Cart pricing and coupon integration', () => {
    let helper: E2ESessionHelper;
    let photographer: User;
    let buyer: User;

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photographer = await helper.createIsolatedUser('photographer', {brand: 'rp'});
        buyer = await helper.createIsolatedUser('power_user', {brand: 'rp'});
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('mixed scope and two volume presets are totaled per effective group', {tag: ['@feature:client:cart', '@feature:client:volume', '@regression']}, async ({page, request}) => {
        test.setTimeout(120000);
        const suffix = Math.random().toString(36).substring(2, 8);
        const presetA = await helper.createVolumePreset({
            name: `E2E Preset A ${suffix}`,
            tiers: [{min_quantity: 0, price_cents: 4000}],
        });
        const presetB = await helper.createVolumePreset({
            name: `E2E Preset B ${suffix}`,
            tiers: [{min_quantity: 0, price_cents: 6000}],
        });
        helper.trackPreset(String(presetA.id));
        helper.trackPreset(String(presetB.id));

        const fixtures = await createGalleryFixtures(page, helper, photographer, [
            {name: `Pricing A ${suffix}`, licensingMode: 'volume_licensing', volumePresetId: presetA.id},
            {name: `Pricing B ${suffix}`, licensingMode: 'volume_licensing', volumePresetId: presetB.id},
            {name: `Pricing Scope ${suffix}`, licensingMode: 'scope_licensing'},
        ]);
        const scopeUseCase = await getCheapestCatalogUseCase(request);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(buyer.email, buyer.password);
        await sidebar.navigateToClientGalleries();
        for (const [index, fixture] of fixtures.entries()) {
            await addFirstPhotoToCart(
                page,
                fixture.name,
                index === fixtures.length - 1 ? scopeUseCase.name : undefined,
            );
            await sidebar.navigateToClientGalleries();
        }
        await openCart(page, sidebar);

        const main = page.getByRole('main');
        const groupA = main.getByTestId(`volume-pricing-group-${presetA.id}`);
        const groupB = main.getByTestId(`volume-pricing-group-${presetB.id}`);
        await expect(groupA).toContainText(presetA.name);
        await expect(groupB).toContainText(presetB.name);
        await expect(groupA).toContainText(money(4000));
        await expect(groupB).toContainText(money(6000));
        await expect(main.getByTestId('cart-total')).toHaveText(money(4000 + 6000 + scopeUseCase.priceCents));
    });

    test('fixed, percentage, and 100% coupons show server-priced net totals; 100% skips Stripe', {tag: ['@feature:client:coupon', '@feature:client:checkout', '@regression']}, async ({page}) => {
        test.setTimeout(120000);
        const suffix = Math.random().toString(36).substring(2, 8).toUpperCase();
        const preset = await helper.createVolumePreset({
            name: `E2E Coupon Preset ${suffix}`,
            tiers: [{min_quantity: 0, price_cents: 4000}],
        });
        helper.trackPreset(String(preset.id));

        const createCoupon = async (code: string, type: 'fixed' | 'percentage', value: number) => {
            const response = await helper.createCoupon({
                code,
                type,
                value,
                scope_type: 'global',
                active: true,
            });
            if (!response.coupon?.id) throw new Error(`Coupon ${code} was created without an id`);
            helper.trackCoupon(String(response.coupon.id));
        };
        const fixedCode = `FIXED${suffix}`;
        const percentageCode = `PERCENT${suffix}`;
        const freeCode = `FREE${suffix}`;
        await createCoupon(fixedCode, 'fixed', 10);
        await createCoupon(percentageCode, 'percentage', 50);
        await createCoupon(freeCode, 'percentage', 100);

        const fixtures = await createGalleryFixtures(page, helper, photographer, [{
            name: `Coupon Pricing ${suffix}`,
            licensingMode: 'volume_licensing',
            volumePresetId: preset.id,
        }]);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(buyer.email, buyer.password);
        await sidebar.navigateToClientGalleries();
        await addFirstPhotoToCart(page, fixtures[0].name);
        await openCart(page, sidebar);

        const main = page.getByRole('main');
        const apply = async (code: string) => {
            await main.getByLabel('Rabattcode').fill(code);
            await main.getByRole('button', {name: 'Anwenden'}).click();
            await expect(main.getByTestId('coupon-input')).toContainText(code);
        };
        const remove = async () => {
            await main.getByRole('button', {name: 'Rabattcode entfernen'}).click();
            await expect(main.getByLabel('Rabattcode')).toBeVisible();
        };

        await apply(fixedCode);
        await expect(main.getByTestId('cart-discount')).toContainText(money(1000));
        await expect(main.getByTestId('cart-total')).toHaveText(money(3000));
        await remove();

        await apply(percentageCode);
        await expect(main.getByTestId('cart-discount')).toContainText(money(2000));
        await expect(main.getByTestId('cart-total')).toHaveText(money(2000));
        await remove();

        await apply(freeCode);
        await expect(main.getByTestId('cart-total')).toHaveText(money(0));
        const form = new FormHelper(page, new ModalHelper(page));
        await form.fillCheckoutForm({
            name: 'Coupon Buyer',
            street: 'Teststraße 1',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true,
        });
        await expect(main.getByRole('button', {name: 'Kostenlos bestellen'})).toBeEnabled();

        const checkoutRequestPromise = page.waitForRequest(request => {
            const url = new URL(request.url());
            return url.pathname === '/api/orders/checkout' && request.method() === 'POST';
        });
        const checkoutResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/orders/checkout' && response.request().method() === 'POST';
        });
        await main.getByRole('button', {name: 'Kostenlos bestellen'}).click();
        const [checkoutRequest, checkoutResponse] = await Promise.all([
            checkoutRequestPromise,
            checkoutResponsePromise,
        ]);
        const checkoutPayload = checkoutRequest.postDataJSON() as Record<string, unknown>;
        expect(checkoutPayload.coupon_code).toBe(freeCode);
        expect(checkoutResponse.ok()).toBeTruthy();
        const checkoutData = await checkoutResponse.json() as {
            success?: boolean;
            order_id?: string;
            client_secret?: string;
        };
        expect(checkoutData).toMatchObject({success: true});
        expect(checkoutData.order_id).toBeTruthy();
        expect(checkoutData.client_secret).toBeUndefined();
        await expect(page).toHaveURL(/\/orders/);
        await expect(page.getByTestId('stripe-checkout-form')).toHaveCount(0);
    });

    test('a signed quote excludes the volume coupon input and payload', {tag: ['@feature:client:quote', '@feature:client:coupon', '@regression']}, async ({page, request}) => {
        test.setTimeout(120000);
        const suffix = Math.random().toString(36).substring(2, 8);
        const fixtures = await createGalleryFixtures(page, helper, photographer, [{
            name: `Quote Exclusion ${suffix}`,
            licensingMode: 'scope_licensing',
        }]);
        const galleryRes = await request.get(`/api/galleries/${fixtures[0].slug}`, {
            headers: {Cookie: helper.getAdminToken(), Accept: 'application/json'},
        });
        expect(galleryRes.ok(), await galleryRes.text()).toBeTruthy();
        const galleryData = await galleryRes.json() as {photos?: Array<{id?: string}>};
        const photoId = galleryData.photos?.[0]?.id;
        expect(photoId).toBeTruthy();

        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: {photo_ids: [photoId], custom_price: 1500},
            headers: {Cookie: helper.getAdminToken(), Accept: 'application/json', 'Content-Type': 'application/json'},
        });
        expect(quoteRes.ok(), await quoteRes.text()).toBeTruthy();
        const quoteData = await quoteRes.json() as {link?: string};
        const quoteToken = quoteData.link
            ? new URL(quoteData.link, 'http://localhost:4321').searchParams.get('quote_token')
            : null;
        expect(quoteToken).toBeTruthy();

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(buyer.email, buyer.password);
        await sidebar.navigateTo('Warenkorb');
        await page.evaluate((token) => {
            const url = new URL(window.location.href);
            url.searchParams.set('quote_token', token as string);
            window.history.pushState({}, '', url.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }, quoteToken);
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();
        await expect(page.locator('.toast')).toContainText('Angebot aus Link wiederhergestellt.');
        await expect(main.getByRole('button', {name: 'Entfernen'})).toHaveCount(1);
        await expect(main.getByTestId('coupon-input')).toHaveCount(0);
    });
});
