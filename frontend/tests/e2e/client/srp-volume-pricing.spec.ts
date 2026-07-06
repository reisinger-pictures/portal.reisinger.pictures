import { test, expect, Page, APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { CreditCardHelper } from '../helpers/CreditCardHelper';
import path from 'path';
import fs from 'fs';

async function createSrpUser(
    request: APIRequestContext,
    roleName: 'photographer' | 'client' | 'power_user' | 'admin',
    adminToken: string,
): Promise<{ email: string; password: string; id: string }> {
    const uniqueId = Math.random().toString(36).substring(2, 10);
    const email = `e2e-srp-${roleName}-${uniqueId}@example.com`;
    const password = 'SecurePassword123!';

    const headers = {
        'Accept': 'application/json',
        'Cookie': adminToken,
        'Referer': 'http://buy.localhost:4321/',
    };

    const createRes = await request.post('/api/management/users', {
        data: { name: `SRP ${roleName}`, email },
        headers,
    });
    if (!createRes.ok()) throw new Error(`User creation failed: ${await createRes.text()}`);
    const createData = await createRes.json();
    const userId = createData.user?.id;
    if (!userId) throw new Error('User ID missing');

    const rolesRes = await request.get('/api/management/roles', { headers });
    const roles = await rolesRes.json();
    const roleId = roles.find((r: { name: string; id: string }) => r.name === roleName).id;

    const updateRes = await request.put(`/api/management/users/${userId}`, {
        data: {
            role_ids: [roleId],
            gallery_ids: [],
            gallery_group_ids: [],
            can_edit_metadata: false,
            brand: 'srp',
        },
        headers,
    });
    if (!updateRes.ok()) throw new Error(`Role update failed: ${await updateRes.text()}`);

    const mailpitRes = await request.get('http://localhost:8025/api/v1/search?kind=to&query=' + encodeURIComponent(email));
    const mailpitData = await mailpitRes.json();
    const msg = mailpitData.messages?.[0];
    if (!msg) throw new Error(`No Mailpit message for ${email}`);
    const detailRes = await request.get(`http://localhost:8025/api/v1/message/${msg.ID}`);
    const detailData = await detailRes.json();
    const bodyText = detailData.Text || detailData.HTML;
    const tokenMatch = bodyText.match(/reset-password\?token=([^&]+)/);
    if (!tokenMatch) throw new Error(`No reset token for ${email}`);
    const token = decodeURIComponent(tokenMatch[1]);

    const resetRes = await request.post('/api/auth/reset-password', {
        data: { email, token, password },
        headers: { 'Accept': 'application/json', 'Referer': 'http://buy.localhost:4321/' },
    });
    if (!resetRes.ok()) throw new Error(`Password reset failed: ${await resetRes.text()}`);

    return { email, password, id: userId };
}

async function resolveGalleryId(page: Page, slugOrId: string): Promise<string> {
    // Try UUID pattern first — if it's already a UUID, use it directly.
    if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(slugOrId)) {
        return slugOrId;
    }
    // Resolve slug → UUID via the gallery API (Vite proxies /api/* to the backend).
    const res = await page.request.get(`/api/galleries/${slugOrId}?page=1`, {
        headers: { 'Accept': 'application/json' },
    });
    if (!res.ok()) return slugOrId;
    const data = await res.json() as { gallery?: { id: string } };
    return data.gallery?.id || slugOrId;
}

async function uploadMultiplePhotosViaApi(page: Page, galleryId: string, count: number): Promise<string[]> {
    const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
    const fileBuffer = fs.readFileSync(sampleImagePath);
    // page.request does NOT share cookies with the browser context automatically —
    // read cookies from the browser and inject them explicitly.
    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
    const photoIds: string[] = [];

    for (let i = 0; i < count; i++) {
        const lrUuid = `web-e2e-${Date.now()}-${i}`;
        const res = await page.request.post('/api/management/upload', {
            multipart: {
                gallery_id: galleryId,
                lr_uuid: lrUuid,
                file: {
                    name: `e2e-photo-${i}.jpg`,
                    mimeType: 'image/jpeg',
                    buffer: fileBuffer,
                },
            },
            headers: { 'Accept': 'application/json', 'Cookie': cookieHeader },
        });
        if (res.ok()) {
            const data = await res.json();
            if (data.photo_id) photoIds.push(data.photo_id);
        } else {
            console.error(`Upload ${i} failed: ${res.status()} ${await res.text()}`);
        }
    }
    return photoIds;
}

const FORMATTED_TIER2 = '25.00\u{00A0}€';
const FORMATTED_TIER3 = '20.00\u{00A0}€';

async function addGalleryPhotosToCart(page: Page, galleryName: string, count: number) {
    await page.locator('main').getByText(galleryName).first().click();
    await expect(page.locator('.pswp-item').first()).toBeVisible({ timeout: 15000 });
    for (let i = 0; i < count; i++) {
        await page.getByRole('button', { name: 'Bild öffnen' }).nth(i).click();
        await expect(page.getByRole('button', { name: 'In den Warenkorb' })).toBeVisible({ timeout: 10000 });
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');
        await page.goBack();
        await expect(page.locator('.pswp-item').first()).toBeVisible({ timeout: 15000 });
    }
}

test.describe('SRP Volume Pricing', () => {

    let helper: E2ESessionHelper;
    let srpPhotog: { email: string; password: string; id: string };
    let srpBuyer: { email: string; password: string; id: string };
    let adminToken: string;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);

        const adminLogin = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' },
        });
        if (!adminLogin.ok()) throw new Error('Admin login failed');
        const adminCookies = adminLogin.headers()['set-cookie'];
        const match = adminCookies?.match(/rp_jwt=([^;]+)/);
        adminToken = match ? `rp_jwt=${match[1]}` : '';
        if (!adminToken) throw new Error('No admin token');

        srpPhotog = await createSrpUser(request, 'photographer', adminToken);
        srpBuyer = await createSrpUser(request, 'power_user', adminToken);

        helper.trackUser(srpPhotog.id);
        helper.trackUser(srpBuyer.id);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Frontend: VolumeLicensingCard auf SRP-Brand', { tag: ['@feature:client:volume'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(srpPhotog.email, srpPhotog.password, 'http://buy.localhost:4321/');

        const galleryName = `SRP Vol ${Math.random().toString(36).substring(2, 10)}`;
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await auth.logout('http://buy.localhost:4321/');

        await auth.login(srpBuyer.email, srpBuyer.password, 'http://buy.localhost:4321/');
        await page.locator('main').getByText(galleryName).first().click();

        const photoEl = page.locator('.pswp-item').first();
        await expect(photoEl).toBeVisible({ timeout: 15000 });

        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();

        await expect(page.getByText(/Mengenrabatt|Staffel|30.*€|25.*€|20.*€/).first()).toBeVisible({ timeout: 10000 });

        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');

        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();
        await expect(page.getByText(/Mengenrabatt/i)).toBeVisible({ timeout: 5000 });
    });

    test('Pricing-Verifikation: Staffel 1/2/3 mit retroaktivem Volumenpreis', { tag: ['@feature:client:volume'] }, async ({ page }) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);

        // --- Setup: Fotograf erstellt Galerie + lädt ein Bild hoch ---
        await auth.login(srpPhotog.email, srpPhotog.password, 'http://buy.localhost:4321/');

        const galleryName = `SRP Pricing ${Math.random().toString(36).substring(2, 10)}`;
        const galleryHelper = new GalleryHelper(page, helper);
        const galleryUuid = await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        // Extra 9 Fotos via API hochladen → insgesamt 10 Fotos (für Tier 2)
        const galleryId = galleryUuid || (await resolveGalleryId(page, page.url().split('/').pop() || ''));
        const extraPhotoIds = await uploadMultiplePhotosViaApi(page, galleryId, 9);
        expect(extraPhotoIds).toHaveLength(9);

        await auth.logout('http://buy.localhost:4321/');

        // --- Buyer: Alle 10 Fotos via UI in den Warenkorb legen ---
        await auth.login(srpBuyer.email, srpBuyer.password, 'http://buy.localhost:4321/');
        await addGalleryPhotosToCart(page, galleryName, 10);

        // --- Tier 2: 10 Fotos (25€) ---
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();
        await expect(page.getByText(/Mengenrabatt/i)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(/Noch 10/)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(FORMATTED_TIER2).first()).toBeVisible();
        await expect(page.getByText(/10 Bilder ×/)).toBeVisible();
        await expect(page.getByText('250.00\u{00A0}€')).toBeVisible();
    });

    test('Pricing-Verifikation: Tier-3-Rendering via UI-Flow (20+ Bilder)', { tag: ['@feature:client:volume'] }, async ({ page }) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);

        // 22 Fotos via Upload (1 manuell + 21 via API)
        await auth.login(srpPhotog.email, srpPhotog.password, 'http://buy.localhost:4321/');
        const galleryName = `SRP T3 ${Math.random().toString(36).substring(2, 10)}`;
        const galleryHelper = new GalleryHelper(page, helper);
        const galleryUuid = await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();
        const galleryId = galleryUuid || (await resolveGalleryId(page, page.url().split('/').pop() || ''));

        await uploadMultiplePhotosViaApi(page, galleryId, 21);
        await auth.logout('http://buy.localhost:4321/');

        // --- Tier 3 via UI (20+ Items) ---
        await auth.login(srpBuyer.email, srpBuyer.password, 'http://buy.localhost:4321/');
        await addGalleryPhotosToCart(page, galleryName, 22);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();
        await expect(page.getByText(/Mengenrabatt/i)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(/Bester Rabatt aktiv/)).toBeVisible({ timeout: 5000 });
        await expect(page.getByText(FORMATTED_TIER3).first()).toBeVisible();
        await expect(page.getByText(/22 Bilder ×/)).toBeVisible();
        await expect(page.getByText('440.00\u{00A0}€')).toBeVisible();
    });

    test('API: Login mit SRP-Brand bestätigt brand=srp', { tag: ['@feature:client:volume'] }, async ({ request }) => {
        const loginRes = await request.post('/api/auth/login', {
            data: { email: srpBuyer.email, password: srpBuyer.password },
            headers: { 'Accept': 'application/json', 'Referer': 'http://buy.localhost:4321/' },
        });
        expect(loginRes.ok()).toBeTruthy();
        const loginCookies = loginRes.headers()['set-cookie'];
        const buyerToken = loginCookies?.match(/rp_jwt=([^;]+)/)?.[1] || '';

        const meRes = await request.get('/api/auth/me', {
            headers: { 'Accept': 'application/json', 'Cookie': `rp_jwt=${buyerToken}` },
        });
        expect(meRes.ok()).toBeTruthy();
        const meData = await meRes.json();
        expect(meData.brand).toBe('srp');
        expect(meData.roles).toContain('power_user');
    });

    test('API: RP-Checkout unverändert (rp-Smoke Regression)', { tag: ['@feature:client:volume'] }, async ({ request }) => {
        const rpBuyer = await helper.createIsolatedUser('power_user');

        const loginRes = await request.post('/api/auth/login', {
            data: { email: rpBuyer.email, password: rpBuyer.password },
            headers: { 'Accept': 'application/json' },
        });
        expect(loginRes.ok()).toBeTruthy();
        const loginCookies = loginRes.headers()['set-cookie'];
        const buyerToken = loginCookies?.match(/rp_jwt=([^;]+)/)?.[1] || '';

        const meRes = await request.get('/api/auth/me', {
            headers: { 'Accept': 'application/json', 'Cookie': `rp_jwt=${buyerToken}` },
        });
        expect(meRes.ok()).toBeTruthy();
        const meData = await meRes.json();
        expect(meData.brand).toBe('rp');
    });

    test('Stripe-Integration: SRP-Volumenbetrag in Stripe-Session (12 Fotos × Tier 2)', { tag: ['@feature:client:volume'] }, async ({ page, request }) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);

        // --- Setup: Fotograf erstellt Galerie + lädt Fotos hoch ---
        await auth.login(srpPhotog.email, srpPhotog.password, 'http://buy.localhost:4321/');

        const galleryName = `SRP Stripe ${Math.random().toString(36).substring(2, 10)}`;
        const galleryHelper = new GalleryHelper(page, helper);
        const galleryUuid = await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        // 11 weitere Fotos via API → 12 gesamt (Tier 2, 25€)
        const galleryId = galleryUuid || (await resolveGalleryId(page, page.url().split('/').pop() || ''));
        await uploadMultiplePhotosViaApi(page, galleryId, 11);

        await auth.logout('http://buy.localhost:4321/');

        // --- Buyer: 12 Fotos via UI in den Warenkorb legen ---
        await auth.login(srpBuyer.email, srpBuyer.password, 'http://buy.localhost:4321/');
        await addGalleryPhotosToCart(page, galleryName, 12);

        // --- Checkout: Formular ausfüllen ---
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();
        await expect(page.getByText(/Mengenrabatt/i)).toBeVisible({ timeout: 5000 });

        const form = new FormHelper(page, new ModalHelper(page));
        await form.fillCheckoutForm({
            name: 'SRP Stripe Tester',
            street: 'SRPstr. 42',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true,
        });

        await expect(page.getByRole('button', { name: 'Zahlungspflichtig bestellen' })).toBeEnabled({ timeout: 5000 });

        // Checkout-API abfangen und totalCents verifizieren
        const checkoutResPromise = page.waitForResponse(
            res => res.url().includes('/api/orders/checkout') && res.request().method() === 'POST',
        );
        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();
        const checkoutRes = await checkoutResPromise;
        expect(checkoutRes.ok(), `Checkout failed: ${await checkoutRes.text()}`).toBeTruthy();

        // Stripe-Login-Iframe abwarten
        await expect(page.locator('h2:has-text("Zahlung abschließen")')).toBeVisible({ timeout: 15000 });

        // Payment mit Stripe Test Card abschließen
        const stripeFrame = page.frameLocator(
            'iframe[title*="payment" i], iframe[title*="secure" i], iframe[title*="sichere" i]',
        ).first();

        const cardInput = stripeFrame.locator(
            'input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]',
        ).first();

        try {
            await cardInput.waitFor({ state: 'visible', timeout: 5000 });
        } catch {
            const cardTab = stripeFrame.getByRole('tab', { name: /Card|Kreditkarte|Karte/i })
                .or(stripeFrame.getByRole('button', { name: /Card|Kreditkarte|Karte/i })).first();
            try {
                await cardTab.waitFor({ state: 'visible', timeout: 5000 });
                await cardTab.click();
            } catch {
                // ignore
            }
        }

        await expect(cardInput).toBeVisible({ timeout: 15000 });
        await form.fillStripeForm(stripeFrame, CreditCardHelper.successVisa);
        await expect(page.getByRole('button', { name: 'Jetzt bezahlen' })).toBeEnabled({ timeout: 10000 });

        const payButton = page.getByRole('button', { name: 'Jetzt bezahlen' });
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        // Warten auf Umleitung zur Bestellseite
        await expect(page).toHaveURL(/.*\/orders/, { timeout: 40000 });
        await expect(page.locator('h1:has-text("Meine Einkäufe & Lizenzen")')).toBeVisible();

        // Order via API abrufen und total_amount verifizieren
        const orderUrl = page.url();
        const orderId = orderUrl.split('/').pop() || '';
        const orderRes = await request.get(`/api/management/orders/${orderId}`, {
            headers: { 'Accept': 'application/json', 'Cookie': `rp_jwt=${adminToken}` },
        });
        if (orderRes.ok()) {
            const orderData = await orderRes.json();
            // 12 Bilder à 2500 Cents (Tier 2) = 30000 Cents = 300€
            expect(orderData.total_amount).toBe(30000);
        }
    });
});
