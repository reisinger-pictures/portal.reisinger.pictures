import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {FormHelper} from '../helpers/FormHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import type {TurnstileRenderOptions} from '../../../src/ui/client/components/TurnstileWidget';

interface GalleryPhoto {
    id: string;
}

interface GalleryDetailResponse {
    photos?: GalleryPhoto[];
}

interface LicenseUseCase {
    id: string;
    base_price: number;
    flatrate_tier: string;
}

interface LicenseCatalogResponse {
    use_cases?: LicenseUseCase[];
}

interface TurnstileInitScriptArgument {
    token: string;
    statusText: string;
}

const TURNSTILE_TEST_TOKEN = 'e2e-turnstile-test-token';
const TURNSTILE_TEST_STATUS = 'Sicherheitsprüfung abgeschlossen (Test)';

test.describe('Risk-triggered Turnstile checkout', () => {
    test.describe.configure({retries: 2});

    let helper: E2ESessionHelper;
    let photographer = {email: '', password: '', id: ''};
    let buyer = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photographer = await helper.createIsolatedUser('photographer');
        buyer = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('third checkout attempt renders Turnstile and submits its one-time token', {
        tag: ['@regression', '@feature:client:checkout', '@feature:card-testing']
    }, async ({page, request}) => {
        await page.addInitScript(({token, statusText}: TurnstileInitScriptArgument) => {
            const render = (container: HTMLElement | string, options: TurnstileRenderOptions): string => {
                const element = typeof container === 'string'
                    ? document.querySelector<HTMLElement>(container)
                    : container;
                if (!element) throw new Error('Turnstile E2E container was not found.');

                const status = document.createElement('p');
                status.setAttribute('role', 'status');
                status.textContent = statusText;
                element.replaceChildren(status);
                options.callback?.(token);
                return 'e2e-turnstile-widget';
            };

            window.turnstile = {
                render,
                remove: () => undefined,
                reset: () => undefined
            };
        }, {token: TURNSTILE_TEST_TOKEN, statusText: TURNSTILE_TEST_STATUS});

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const form = new FormHelper(page, new ModalHelper(page));
        const galleryName = `Turnstile Checkout ${crypto.randomUUID().slice(0, 8)}`;

        await auth.login(photographer.email, photographer.password);
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');
        await new UploadHelper(page).uploadSampleImage();

        const gallerySlug = new URL(page.url()).pathname.split('/').filter(Boolean).at(-1);
        expect(gallerySlug).toBeTruthy();

        const adminHeaders = {
            Accept: 'application/json',
            Cookie: helper.getAdminToken()
        };
        const galleryResponse = await request.get(`/api/galleries/${gallerySlug}`, {headers: adminHeaders});
        expect(galleryResponse.ok()).toBeTruthy();
        const galleryDetail = await galleryResponse.json() as GalleryDetailResponse;
        const photo = galleryDetail.photos?.[0];
        expect(photo?.id).toBeTruthy();
        if (!photo) throw new Error('Turnstile E2E gallery has no uploaded photo.');

        const catalogResponse = await request.get('/api/settings/license-catalog', {headers: adminHeaders});
        expect(catalogResponse.ok()).toBeTruthy();
        const catalog = await catalogResponse.json() as LicenseCatalogResponse;
        const useCase = catalog.use_cases?.find(candidate => candidate.base_price > 0);
        expect(useCase).toBeDefined();
        if (!useCase) throw new Error('Turnstile E2E license catalog has no paid use case.');

        await auth.logout();
        await auth.login(buyer.email, buyer.password);

        const checkoutPayload = {
            items: [{
                photoId: photo.id,
                tier: useCase.flatrate_tier,
                useCaseId: useCase.id,
                modifierIds: []
            }],
            quote_token: null,
            billing_name: 'Turnstile E2E',
            billing_company: '',
            billing_street: 'Teststraße 42',
            billing_zip: '1010',
            billing_city: 'Wien',
            payment_method: 'stripe',
            quote_message: '',
            withdrawal_waived: true,
            coupon_code: null
        };
        // Each priming request must be a distinct server fingerprint. Otherwise
        // same-fingerprint recovery returns the pending order before risk runs.
        for (let attempt = 1; attempt <= 2; attempt += 1) {
            const response = await page.request.post('/api/orders/checkout', {
                data: {...checkoutPayload, billing_name: `Turnstile Prime ${attempt}`},
                headers: {
                    Accept: 'application/json',
                    'Idempotency-Key': crypto.randomUUID()
                }
            });
            expect(response.status(), await response.text()).toBe(200);
        }

        const main = page.getByRole('main');
        await main.getByText(galleryName, {exact: true}).first().click();
        await main.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await main.getByRole('button', {name: 'In den Warenkorb'}).click();
        await sidebar.navigateTo('Warenkorb');
        // "Turnstile E2E" is intentionally distinct from both priming names,
        // making this the third paid fingerprint and therefore risk attempt.
        await form.fillCheckoutForm({
            name: 'Turnstile E2E',
            street: 'Teststraße 42',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true
        });

        const initialRequestPromise = page.waitForRequest(request => (
            request.url().includes('/api/orders/checkout') && request.method() === 'POST'
        ));
        const initialResponsePromise = page.waitForResponse(response => (
            response.url().includes('/api/orders/checkout') && response.request().method() === 'POST'
        ));
        const checkoutButton = main.getByRole('button', {name: 'Zahlungspflichtig bestellen'});
        await checkoutButton.click();

        const [initialRequest, initialResponse] = await Promise.all([
            initialRequestPromise,
            initialResponsePromise
        ]);
        expect(initialResponse.status()).toBe(403);
        expect(await initialResponse.json()).toEqual(expect.objectContaining({turnstile_required: true}));
        await expect(main.getByRole('heading', {name: 'Sicherheitsprüfung'})).toBeVisible({timeout: 15000});
        const turnstileSection = main.getByRole('region', {name: 'Sicherheitsprüfung'});
        const turnstileStatus = turnstileSection.getByRole('status');
        await expect(turnstileStatus).toBeVisible({timeout: 15000});
        await expect(turnstileStatus).toHaveText(TURNSTILE_TEST_STATUS);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});

        const verifiedRequestPromise = page.waitForRequest(request => (
            request.url().includes('/api/orders/checkout') && request.method() === 'POST'
        ));
        const verifiedResponsePromise = page.waitForResponse(response => (
            response.url().includes('/api/orders/checkout') && response.request().method() === 'POST'
        ));
        await checkoutButton.click();

        const [verifiedRequest, verifiedResponse] = await Promise.all([
            verifiedRequestPromise,
            verifiedResponsePromise
        ]);
        expect(verifiedResponse.status(), await verifiedResponse.text()).toBe(200);
        expect(verifiedRequest.headers()['idempotency-key']).toBe(initialRequest.headers()['idempotency-key']);
        const verifiedPayload = verifiedRequest.postDataJSON() as Record<string, unknown>;
        expect(verifiedPayload.turnstile_token).toBe(TURNSTILE_TEST_TOKEN);
        await expect(main.getByRole('heading', {name: 'Zahlung abschließen'})).toBeVisible({timeout: 15000});
        await expect(main.getByRole('heading', {name: 'Sicherheitsprüfung'})).toHaveCount(0);
    });
});
