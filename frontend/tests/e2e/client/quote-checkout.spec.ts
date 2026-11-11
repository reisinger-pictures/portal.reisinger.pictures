import { expect, test } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { FormHelper } from '../helpers/FormHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { PRICING_STRATEGY_SETTINGS_LOCK, withGlobalSettingsLock } from '../helpers/GlobalSettingsLock';
import { MOLLIE_PROVIDER_SKIP_REASON, mollieCheckoutConfigured } from '../helpers/MollieE2E';
import { ModalHelper } from '../helpers/ModalHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import {browserAuthHeaders} from '../helpers/BrowserAuthHeaders';

test.describe('Quote Checkout Workflow', () => {
    // A quote checkout is a new online payment and therefore ends on Mollie's
    // hosted page: the journey needs a configured test account, and the
    // fulfilment half (provider confirmation, invoice, paid order) is covered by
    // the backend fakes, not by a browser that cannot complete a hosted payment.
    test.describe.configure({ retries: 2 });

    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '', id: '' };
    let buyerUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        await helper.createIsolatedUser('super_admin');

        photogUser = await helper.createIsolatedUser('photographer');
        buyerUser = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Client submits a quote checkout to the hosted provider checkout', { tag: ['@feature:quote', '@regression'] }, async ({ page, request }) => {
        test.skip(!mollieCheckoutConfigured(), MOLLIE_PROVIDER_SKIP_REASON);
        test.setTimeout(120000);
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);
        const sidebar = new SidebarHelper(page);

        // --- 1. Fotograf erstellt Galerie + lädt Bild hoch ---
        await auth.login(photogUser.email, photogUser.password);

        const galleryName = `Quote Checkout ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await upload.uploadSampleImage();

        // Galerie-Id aus der URL extrahieren
        const galleryUrl = page.url();
        const gallerySlug = galleryUrl.split('/').pop();
        const validAdminToken = helper.getAdminToken();
        const galRes = await request.get(`/api/galleries/${gallerySlug}`, { headers: { 'Cookie': validAdminToken, ...browserAuthHeaders(validAdminToken)} });
        const galData = await galRes.json();
        const galId = galData.gallery?.id;
        expect(galId).toBeTruthy();

        // Galerie dem Buyer zuweisen (power_user-Rolle)
        const rolesRes = await request.get('/api/management/roles', { headers: { 'Cookie': validAdminToken, ...browserAuthHeaders(validAdminToken)} });
        expect(rolesRes.ok()).toBeTruthy();
        const rolesData = await rolesRes.json();
        const roles = Array.isArray(rolesData) ? rolesData : (rolesData.data || []);
        const powerUserRoleId = roles.find((r: { name: string }) => r.name === 'power_user')?.id;
        expect(powerUserRoleId).toBeTruthy();

        const assignRes = await request.put(`/api/management/users/${buyerUser.id}`, {
            data: { role_ids: [powerUserRoleId], gallery_ids: [galId], gallery_group_ids: [], can_edit_metadata: false, brand: 'rp' },
            headers: { 'Cookie': validAdminToken, ...browserAuthHeaders(validAdminToken), 'Accept': 'application/json', 'Content-Type': 'application/json' }
        });
        expect(assignRes.ok()).toBeTruthy();

        // Galerie-Photos aus der Gallery-Detail-Response abrufen
        const galDetailRes = await request.get(`/api/galleries/${gallerySlug}`, { headers: { 'Cookie': validAdminToken, ...browserAuthHeaders(validAdminToken)} });
        const galDetailData = await galDetailRes.json();
        const photos = Array.isArray(galDetailData.photos) ? galDetailData.photos : [];
        expect(photos.length).toBeGreaterThan(0);
        const photoId = photos[0].id;
        expect(photoId).toBeTruthy();

        await auth.logout();

        // --- 2. Admin generiert quote_token mit Festpreis (1500 € = 150000 Cent) ---
        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: { photo_ids: [photoId], custom_price: 150000, rights_text: 'Exklusivnutzung Print + Web, 2 Jahre, Österreich' },
            headers: { 'Cookie': helper.getAdminToken(), ...browserAuthHeaders(helper.getAdminToken()), 'Accept': 'application/json', 'Content-Type': 'application/json' }
        });
        expect(quoteRes.ok()).toBeTruthy();
        const quoteData = await quoteRes.json();
        const quoteToken = quoteData.link?.split('quote_token=')[1];
        expect(quoteToken).toBeTruthy();

        // --- 3. Buyer öffnet Link + sieht Festpreis ---
        // The gallery carries no own `licensing_mode`, so the brand default decides
        // how it is priced (scope licence vs. volume tier). The brand-settings spec
        // writes that mode, so this spec takes the same lock and cannot observe a
        // foreign one.
        await withGlobalSettingsLock(PRICING_STRATEGY_SETTINGS_LOCK, async () => {
            await auth.login(buyerUser.email, buyerUser.password);
            await sidebar.navigateTo('Warenkorb');
            await page.evaluate((t) => {
                const url = new URL(window.location.href);
                url.searchParams.set('quote_token', t);
                window.history.pushState({}, '', url.toString());
                window.dispatchEvent(new PopStateEvent('popstate'));
            }, quoteToken);

            // Warte auf Toast + korrekten Preis
            await expect(page.locator('.toast')).toContainText('Angebot aus Link wiederhergestellt.', { timeout: 10000 });
            const totalAmount = page.locator('.text-3xl.font-mono.text-primary');
            await expect(totalAmount).toHaveText('1.500,00 €');
        });

        // --- 4. Checkout-Formular ausfüllen ---
        await form.fillCheckoutForm({
            name: 'Quote Buyer',
            street: 'Quote Str 1',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true
        });

        await expect(page.getByRole('button', { name: 'Zahlungspflichtig bestellen' })).toBeEnabled({ timeout: 5000 });

        await page.route('https://www.mollie.com/**', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<!doctype html><html lang="de"><head><title>Mollie Test-Zahlungsseite</title></head><body><h1>Mollie Test-Zahlungsseite</h1></body></html>',
            });
        });

        const checkoutPromise = page.waitForResponse(res => res.url().includes('/api/orders/checkout') && res.request().method() === 'POST');
        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();

        const checkoutRes = await checkoutPromise;
        expect(checkoutRes.ok(), `Backend Error during checkout: ${await checkoutRes.text()}`).toBeTruthy();

        const checkoutData = await checkoutRes.json();
        const orderId: string = checkoutData.order_id;
        expect(orderId).toBeTruthy();

        // --- 5. Weiterleitung zur gehosteten Zahlungsseite -------------------
        // Ein Angebot mit Festpreis ist eine sofortige Online-Zahlung. Die
        // Zahlung findet ausschließlich auf der Seite des Zahlungsdienstleisters
        // statt; der Browser verlässt dafür die SPA (Top-Level-Navigation).
        expect(checkoutData.checkout_url).toMatch(/^https:\/\/www\.mollie\.com\//);
        await expect(page).toHaveURL(/^https:\/\/www\.mollie\.com\//, { timeout: 20000 });
    });
});
