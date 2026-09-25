import {test, expect} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

type CheckoutPayload = {
    quote_token?: unknown;
    payment_method?: unknown;
    items?: Array<{photoId?: unknown; price?: unknown}>;
};

test.describe('Cart Persistence', () => {
    let helper: E2ESessionHelper;
    let testUser = {email: '', password: '', id: ''};
    let photographerUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('power_user');
        photographerUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    // User-Flow statt localStorage-Injektion (frontend/AGENTS.md, "localStorage Injection"):
    // Der Warenkorb wird API-basiert über den echten Admin-Quote-Link befüllt. Die zwei
    // Ziel-Fotos stammen aus einem echten, öffentlichen Galerie-/Upload-Fixture; es werden
    // keine synthetischen Photo-Ids an den signierenden Endpoint gesendet.
    test('Quote token and offer prices survive navigation, reload, and checkout', {tag: ['@feature:client:cart', '@regression']}, async ({page, request}) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);

        await auth.login(photographerUser.email, photographerUser.password);
        const galleryName = `Cart Persistence ${Math.random().toString(36).substring(2, 10)}`;
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(
            galleryName,
            'Öffentlich (Für alle sichtbar)',
        );
        if (!galleryId) throw new Error('Gallery fixture was created without an id');
        await upload.uploadSampleImage();
        await upload.uploadSampleImage();
        const gallerySlug = new URL(page.url()).pathname.split('/').filter(Boolean).pop();
        if (!gallerySlug) throw new Error('Gallery fixture has no slug');
        await auth.logout();

        const detailRes = await request.get(`/api/galleries/${gallerySlug}`, {
            headers: {Cookie: helper.getAdminToken(), Accept: 'application/json'},
        });
        expect(detailRes.ok(), await detailRes.text()).toBeTruthy();
        const detail = await detailRes.json() as {photos?: Array<{id?: string}>};
        const photoIds = (detail.photos ?? []).map(photo => photo.id).filter((id): id is string => Boolean(id));
        expect(photoIds.length).toBeGreaterThanOrEqual(2);

        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: {photo_ids: photoIds.slice(0, 2), custom_price: 150000},
            headers: {Cookie: helper.getAdminToken(), Accept: 'application/json', 'Content-Type': 'application/json'},
        });
        expect(quoteRes.ok(), await quoteRes.text()).toBeTruthy();
        const quoteData = await quoteRes.json() as {link?: string};
        const quoteToken = quoteData.link
            ? new URL(quoteData.link, 'http://localhost:4321').searchParams.get('quote_token')
            : null;
        if (!quoteToken) throw new Error('Quote link did not contain a token');

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Warenkorb');
        await page.evaluate((token) => {
            const url = new URL(window.location.href);
            url.searchParams.set('quote_token', token);
            window.history.pushState({}, '', url.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }, quoteToken);

        await expect(page.locator('.toast')).toContainText('Angebot aus Link wiederhergestellt.');
        await expect(page.getByRole('main').getByRole('button', {name: 'Entfernen'})).toHaveCount(2);
        await expect(page.getByRole('main').getByTestId('cart-total')).toHaveText('1500.00 €');

        // Provider-State bleibt beim SPA-Navigieren erhalten.
        await sidebar.navigateTo('Einkäufe & Anfragen');
        await expect(page).toHaveURL(/.*\/orders/);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.getByRole('main').getByRole('button', {name: 'Entfernen'})).toHaveCount(2);
        await expect(page.getByRole('main').getByTestId('cart-total')).toHaveText('1500.00 €');

        // Vollständiger Reload: Der URL-Token ist bereinigt, der persistierte
        // Cart-Context muss Token und Originalpreise trotzdem wiederherstellen.
        await page.reload();
        await expect(page).toHaveURL(/\/cart$/);
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();
        await expect(main.getByRole('button', {name: 'Entfernen'})).toHaveCount(2);
        await expect(page.getByRole('main').getByTestId('cart-total')).toHaveText('1500.00 €');

        await expect(main.getByLabel('Vor- & Nachname')).toHaveAttribute('required');
        await expect(main.getByRole('checkbox', {name: /allgemeinen geschäftsbedingungen/i})).toHaveAttribute('required');
        await main.getByRole('radio', {name: 'Kauf auf Rechnung'}).check();
        await main.getByLabel('Vor- & Nachname').fill('Reload Quote Buyer');
        await main.getByLabel('Straße & Hausnummer').fill('Reloadstraße 1');
        await main.getByLabel('PLZ').fill('1010');
        // The bare label also matches the withdrawal consent checkbox.
        const cityInput = main.getByRole('textbox', {name: 'Ort *', exact: true});
        await expect(cityInput).toHaveCount(1);
        await cityInput.fill('Wien');
        await main.getByRole('checkbox', {name: /allgemeinen geschäftsbedingungen/i}).check();
        await main.getByRole('checkbox', {name: /widerrufsrecht/i}).check();

        const checkoutRequestPromise = page.waitForRequest(req => {
            const url = new URL(req.url());
            return url.pathname === '/api/orders/checkout' && req.method() === 'POST';
        });
        const checkoutResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/orders/checkout' && response.request().method() === 'POST';
        });
        await main.getByRole('button', {name: 'Zahlungspflichtig bestellen'}).click();
        const [checkoutRequest, checkoutResponse] = await Promise.all([
            checkoutRequestPromise,
            checkoutResponsePromise,
        ]);
        const checkoutPayload = checkoutRequest.postDataJSON() as CheckoutPayload;
        expect(checkoutPayload.quote_token).toBe(quoteToken);
        expect(checkoutPayload.payment_method).toBe('invoice');
        expect(checkoutPayload.items).toHaveLength(2);
        expect(checkoutPayload.items?.map(item => item.price)).toEqual([75000, 75000]);

        expect(checkoutResponse.ok()).toBeTruthy();
        const checkoutData = await checkoutResponse.json() as {success?: boolean; order_id?: string};
        expect(checkoutData).toMatchObject({success: true});
        if (!checkoutData.order_id) throw new Error('Checkout response did not contain an order id');
        const orderResponse = await page.request.get(`/api/orders/${checkoutData.order_id}`, {
            headers: {Accept: 'application/json'},
        });
        expect(orderResponse.ok(), await orderResponse.text()).toBeTruthy();
        const order = await orderResponse.json() as {status?: string; order_id?: string};
        expect(order).toMatchObject({order_id: checkoutData.order_id, status: 'invoice_created'});
        await expect(page).toHaveURL(/\/orders/);
    });
});
