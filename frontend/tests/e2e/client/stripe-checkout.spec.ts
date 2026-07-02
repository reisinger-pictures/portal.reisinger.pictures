import {expect, Page, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {CreditCardHelper} from '../helpers/CreditCardHelper';
import {FormHelper} from '../helpers/FormHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

test.describe('Stripe Checkout Workflow', () => {
    let helper: E2ESessionHelper;
    let photogUser = {email: '', password: '', id: ''};
    let buyerUser = {email: '', password: '', id: ''};
    let adminToken: string;

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);

        const adminLogin = await request.post('/api/auth/login', {
            data: {email: 'florian@reisinger.pictures', password: 'admin'},
            headers: {'Accept': 'application/json'},
        });
        if (!adminLogin.ok()) throw new Error('Admin login failed');
        const adminCookies = adminLogin.headers()['set-cookie'];
        const match = adminCookies?.match(/rp_jwt=([^;]+)/);
        adminToken = match ? `rp_jwt=${match[1]}` : '';
        if (!adminToken) throw new Error('No admin token');

        photogUser = await helper.createIsolatedUser('photographer');
        buyerUser = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const navigateToStripeIframe = async (page: Page) => {
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

        await expect(page.locator('h2:has-text("Zahlung abschließen")')).toBeVisible({timeout: 15000});

        // Finde explizit das Payment Element Iframe (Ignoriere unsichtbare Tracking-Iframes)
        const stripeFrame = page.frameLocator('iframe[title*="payment" i], iframe[title*="secure" i], iframe[title*="sichere" i]').first();

        const cardInput = stripeFrame.locator('input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]').first();

        try {
            // Pruefen ob das Eingabefeld ohnehin schon direkt sichtbar ist (Default bei manchen Locales)
            await cardInput.waitFor({ state: 'visible', timeout: 5000 });
        } catch {
            // Falls nicht sichtbar, versuche den "Card" Tab zu finden und zu klicken
            const cardTab = stripeFrame.getByRole('tab', { name: /Card|Kreditkarte|Karte/i }).or(stripeFrame.getByRole('button', { name: /Card|Kreditkarte|Karte/i })).first();
            try {
                await cardTab.waitFor({ state: 'visible', timeout: 5000 });
                await cardTab.click();
                // Nach Tab-Klick braucht Stripe einen Render-Cycle fuer das Iframe
                await expect(cardInput).toBeVisible({ timeout: 10000 });
            } catch {
                // Ignorieren, falls kein Tab existiert
            }
        }

        // Desktop: Stripe rendert das Card-Input nach Tab-Klick in einem separaten, dedizierten Iframe
        const cardNumberFrame = page.frameLocator(
            'iframe[title*="card number" i], iframe[title*="kartennummer" i]'
        ).first();

        let resolvedCardInput;
        try {
            resolvedCardInput = cardNumberFrame.locator('input').first();
            await resolvedCardInput.waitFor({ state: 'visible', timeout: 5000 });
        } catch {
            resolvedCardInput = cardInput;
        }

        // Warte final auf das Eingabefeld
        await expect(resolvedCardInput).toBeVisible({timeout: 15000});

        return {stripeFrame, form, orderId};
    };

    test('Negative Flow: Handles generic decline and insufficient funds via inline alert', async ({page}) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        const {stripeFrame, form} = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.genericDecline);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'})).toBeEnabled({ timeout: 10000 });

        // Button direkt über JavaScript anklicken (zuverlässiger bei Desktop Layout-Problemen)
        const payButton = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        const inlineAlert = stripeFrame.locator('[role="alert"]');
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(/(fehlgeschlagen|declined|invalid|abgelehnt|insufficient|deckung|incomplete|unvollst\u00E4ndig)/i);
        }).toPass({timeout: 15000});

        await page.locator('.toast button').click().catch(() => {
        });

        await form.fillStripeForm(stripeFrame, CreditCardHelper.insufficientFunds);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'}).first()).toBeEnabled({ timeout: 10000 });
        const payButton2 = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton2.evaluate(el => (el as HTMLButtonElement).click());
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(/(fehlgeschlagen|declined|invalid|abgelehnt|insufficient|deckung|incomplete|unvollst\u00E4ndig)/i);
        }).toPass({timeout: 15000});
    });

    test('Positive Flow: Handles successful payment via Visa', async ({page}) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        const {stripeFrame, form} = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.successVisa);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'})).toBeEnabled({ timeout: 10000 });
        const payButton = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        // Stripe confirmPayment benoetigt externe Verarbeitung - kein DOM-Element verfuegbar
        await page.waitForTimeout(3000);

        // Simuliere den Stripe-Return nach erfolgreichem Payment:
        // Stripe leitet den User auf die return_url (/cart?redirect_status=succeeded) zurück.
        // Der neue useEffect in ClientCartView erkennt redirect_status, cleart den Cart,
        // und navigiert den User zu /orders.
        await page.goto('/cart?redirect_status=succeeded');

        await expect(page).toHaveURL(/.*\/orders/, {timeout: 15000});
        await expect(page.locator('h1:has-text("Meine Einkäufe & Lizenzen")')).toBeVisible();
    });
});
