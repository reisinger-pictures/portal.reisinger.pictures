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

        const stripeFrames = await StripeHelper.resolveStripeIframes(page);

        return {stripeFrame: stripeFrames.stripeFrame, form, orderId};
    };

    test('Negative Flow: Handles generic decline and insufficient funds via inline alert', { tag: ['@feature:client:checkout'] }, async ({page}) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        const {stripeFrame} = await navigateToStripeIframe(page);

        await StripeHelper.fillStripeForm(page, CreditCardHelper.genericDecline);
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

        await StripeHelper.fillStripeForm(page, CreditCardHelper.insufficientFunds);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'}).first()).toBeEnabled({ timeout: 10000 });
        const payButton2 = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton2.evaluate(el => (el as HTMLButtonElement).click());
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(/(fehlgeschlagen|declined|invalid|abgelehnt|insufficient|deckung|incomplete|unvollst\u00E4ndig)/i);
        }).toPass({timeout: 15000});
    });

    test('Positive Flow: Handles successful payment via Visa', { tag: ['@smoke', '@feature:client:checkout'] }, async ({page}) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        await navigateToStripeIframe(page);

        await StripeHelper.fillStripeForm(page, CreditCardHelper.successVisa);
        await expect(page.getByRole('button', {name: 'Jetzt bezahlen'})).toBeEnabled({ timeout: 10000 });
        const payButton = page.getByRole('button', {name: 'Jetzt bezahlen'});
        await payButton.evaluate(el => (el as HTMLButtonElement).click());

        // Stripe confirmPayment benoetigt externe Verarbeitung - Toast signalisiert Erfolg
        await expect(page.locator('.toast')).toContainText(/Zahlung erfolgreich/i, { timeout: 15000 });

        // Simuliere den Stripe-Return nach erfolgreichem Payment:
        // Stripe leitet den User auf die return_url (/cart?redirect_status=succeeded) zurück.
        // Der neue useEffect in ClientCartView erkennt redirect_status, cleart den Cart,
        // und navigiert den User zu /orders.
        await page.goto('/cart?redirect_status=succeeded');

        await expect(page).toHaveURL(/.*\/orders/, {timeout: 15000});
        await expect(page.locator('h1:has-text("Meine Einkäufe & Lizenzen")')).toBeVisible();
    });
});
