import {expect, Page, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {CreditCardHelper} from '../helpers/CreditCardHelper';
import {FormHelper} from '../helpers/FormHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {StripeHelper} from '../helpers/StripeHelper';
import {UploadHelper} from '../helpers/UploadHelper';

test.describe('Stripe Checkout Workflow', () => {
    // CI-Flakiness-Schutz: Stripe-API ist von CI-Runner-IPs zeitweise nicht
    // erreichbar (Rate-Limit / ApiConnection). Local passen diese Tests stabil.
    test.describe.configure({ retries: 2 });

    let helper: E2ESessionHelper;
    let photogUser = {email: '', password: '', id: ''};
    let buyerUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        await helper.createIsolatedUser('super_admin');

        photogUser = await helper.createIsolatedUser('photographer');
        buyerUser = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const navigateToCheckout = async (page: Page) => {
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const sidebar = new SidebarHelper(page);
        const upload = new UploadHelper(page);

        // 1. Fotograf erstellt öffentliche Galerie und lädt Bild hoch
        await auth.login(photogUser.email, photogUser.password);

        const galleryName = `Stripe Test ${Math.random().toString(36).substring(2, 10)}`;

        await sidebar.openNewGalleryModal();
        await form.fillGalleryModal({
            name: galleryName,
            type: 'Delivery (Downloads)',
            visibility: 'Öffentlich (Für alle sichtbar)'
        });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const galLink = page.locator('main').locator('a').filter({hasText: galleryName}).first();
        await expect(galLink).toBeVisible({timeout: 15000});
        await galLink.scrollIntoViewIfNeeded();
        await galLink.click();
        await expect(page.getByRole('heading', {name: galleryName})).toBeVisible();

        await upload.uploadSampleImage();

        await auth.logout();

        // 2. Kunde loggt sich ein und geht in die Galerie
        await auth.login(buyerUser.email, buyerUser.password);
        await page.locator('main').getByText(galleryName).first().click();

        const photoEl = page.locator('.pswp-item').first();
        await expect(photoEl).toBeVisible();

        // 3. Lizenzen wählen
        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();

        // Das neue UI wählt automatisch die erste Kategorie aus. Wir klicken nur noch auf "In den Warenkorb".
        await page.getByRole('button', {name: 'In den Warenkorb'}).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');

        // 4. Checkout
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        await form.fillCheckoutForm({
            name: 'E2E Stripe Tester',
            street: 'Teststraße 42',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true
        });

        // Anti-Flakiness: React Hook Form State-Sync abwarten
        await expect(page.getByRole('button', {name: 'Zahlungspflichtig bestellen'})).toBeEnabled({ timeout: 5000 });

        // API Response abfangen, um lautstark zu scheitern, falls das Backend einen Fehler wirft (z.B. fehlende Stripe-Keys)
        const checkoutPromise = page.waitForResponse(res => res.url().includes('/api/orders/checkout') && res.request().method() === 'POST');
        await page.getByRole('button', {name: 'Zahlungspflichtig bestellen'}).click();

        const checkoutRes = await checkoutPromise;
        expect(checkoutRes.ok(), `Backend Error during checkout: ${await checkoutRes.text()}`).toBeTruthy();

        const checkoutData = await checkoutRes.json();
        const orderId: string = checkoutData.order_id;

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Zahlung abschließen'})).toBeVisible({timeout: 15000});

        return {form, orderId};
    };

    const navigateToStripeIframe = async (page: Page) => {
        const {form, orderId} = await navigateToCheckout(page);
        const stripeFrames = await StripeHelper.resolveStripeIframes(page);

        return {stripeFrame: stripeFrames.stripeFrame, form, orderId};
    };

    test('Negative Flow: Handles generic decline and insufficient funds via inline alert', { tag: ['@feature:client:checkout'] }, async ({page}) => {
        test.setTimeout(120000); // Erhöhtes Timeout für Multi-User Flow (2 Zahlungsversuche)
        const {stripeFrame} = await navigateToStripeIframe(page);

        await StripeHelper.fillStripeForm(page, CreditCardHelper.genericDecline);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'})).toBeEnabled({ timeout: 10000 });

        // Button direkt über JavaScript anklicken (zuverlässiger bei Desktop Layout-Problemen)
        const payButton = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        const inlineAlert = stripeFrame.locator('[role="alert"]');
        const declineText = /(fehlgeschlagen|declined|invalid|abgelehnt|insufficient|deckung|incomplete|unvollst\u00E4ndig|guthaben|abgelaufen|falsch|g\u00FCltig|ung\u00FCltig)/i;
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(declineText);
        }).toPass({timeout: 15000});

        await page.locator('.toast button').click().catch(() => {
        });

        await StripeHelper.fillStripeForm(page, CreditCardHelper.insufficientFunds);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'}).first()).toBeEnabled({ timeout: 10000 });
        const payButton2 = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton2.evaluate(el => (el as HTMLButtonElement).click());
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(declineText);
        }).toPass({timeout: 15000});
    });

    test('Stripe loader exhaustion exposes retry and invoice fallback', { tag: ['@regression', '@feature:client:checkout'] }, async ({page}) => {
        test.setTimeout(120000);
        let stripeScriptRequests = 0;
        await page.route('https://js.stripe.com/**', async route => {
            stripeScriptRequests += 1;
            await route.abort('failed');
        });

        const {orderId} = await navigateToCheckout(page);
        const main = page.getByRole('main');
        const loaderAlert = main.getByRole('alert').filter({
            hasText: 'Sicherer Zahlungsdienst nicht verfügbar'
        });
        const retryButton = main.getByRole('button', {name: 'Erneut versuchen'});

        await expect(loaderAlert).toBeVisible();
        await expect(main.getByRole('link', {name: 'Rechnung als PDF öffnen'}))
            .toHaveAttribute('href', `/api/orders/${orderId}/invoice`);
        expect(stripeScriptRequests).toBeGreaterThanOrEqual(2);

        const attemptsBeforeRetry = stripeScriptRequests;
        await retryButton.click();
        await expect.poll(() => stripeScriptRequests).toBeGreaterThan(attemptsBeforeRetry);
        await expect(loaderAlert).toBeVisible();
    });

    test('Positive Flow: completes after successful payment and real order polling', { tag: ['@smoke', '@feature:client:checkout'] }, async ({page, request}) => {
        test.setTimeout(120000); // Erhöhtes Timeout für Multi-User Flow
        const {orderId} = await navigateToStripeIframe(page);

        await StripeHelper.fillStripeForm(page, CreditCardHelper.successVisa);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'})).toBeEnabled({ timeout: 10000 });
        const payButton = page.getByRole('button', {name: 'Jetzt bezahlen'});
        const paidOrderResponsePromise = page.waitForResponse(async response => {
            const responseUrl = new URL(response.url());
            if (responseUrl.pathname !== `/api/orders/${orderId}` || response.request().method() !== 'GET') {
                return false;
            }
            if (!response.ok()) return false;

            const order: unknown = await response.json();
            return typeof order === 'object'
                && order !== null
                && (order as { status?: unknown }).status === 'paid';
        }, { timeout: 60000 });
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        // The disposable E2E stack does not guarantee webhook delivery. Move
        // the real order through the management API so the browser still polls
        // the actual backend order resource; polling/refresh itself is covered
        // by the StripeCheckoutForm Vitest regression.
        const paidResponse = await request.put(`/api/management/orders/${orderId}/status`, {
            data: {status: 'paid'},
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Cookie: helper.getAdminToken(),
            },
        });
        expect(paidResponse.ok(), await paidResponse.text()).toBeTruthy();

        // Stripe confirmation precedes the server-authoritative paid state.
        // Wait for the authenticated status poll before checking the success toast.
        await paidOrderResponsePromise;
        await expect(page.getByRole('alert').filter({hasText: /Zahlung erfolgreich/i})).toBeVisible({timeout: 15000});

        await expect(page).toHaveURL(/.*\/orders/, {timeout: 15000});
        await expect(page.getByRole('main').getByRole('heading', {name: 'Meine Einkäufe & Lizenzen'})).toBeVisible();
    });
});
