import { test, expect, Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { CreditCardHelper } from '../helpers/CreditCardHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Stripe Checkout Workflow', () => {
    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '', id: '' };
    let buyerUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        buyerUser = await helper.createIsolatedUser('client');
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
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)', visibility: 'Öffentlich (Für alle sichtbar)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(async () => {
            if (!(await galLink.isVisible())) await page.reload();
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            await galLink.click();
        }).toPass({ timeout: 15000 });
        await expect(page.getByRole('heading', { name: galleryName })).toBeVisible();

        await upload.uploadSampleImage();
        const galleryUrl = page.url();

        await auth.logout();

        // 2. Kunde loggt sich ein und geht in die Galerie
        await auth.login(buyerUser.email, buyerUser.password);
        await page.goto(galleryUrl);

        const photoEl = page.locator('.pswp-item').first();
        await expect(photoEl).toBeVisible();

        // 3. Lizenzen wählen
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();

        await page.getByRole('button', { name: 'Kommerziell' }).click();
        await page.getByRole('button', { name: 'Unbegrenzt' }).click();

        await page.locator('.rounded-xl').filter({ hasText: 'Original' }).getByRole('button', { name: /€/ }).click();
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

        // Anti-Flakiness: Kurz warten, bis React Hook Form den State intern synchronisiert hat
        await page.waitForTimeout(500);

        // API Response abfangen, um lautstark zu scheitern, falls das Backend einen Fehler wirft (z.B. fehlende Stripe-Keys)
        const checkoutPromise = page.waitForResponse(res => res.url().includes('/api/orders/checkout') && res.request().method() === 'POST');
        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();
        
        const checkoutRes = await checkoutPromise;
        expect(checkoutRes.ok(), `Backend Error during checkout: ${await checkoutRes.text()}`).toBeTruthy();

        await expect(page.locator('h2:has-text("Zahlung abschließen")')).toBeVisible({ timeout: 15000 });

        const stripeFrame = page.frameLocator('iframe').first();
        
        // Sicherstellen, dass der "Card" Tab ausgewählt ist. 
        // Stripe wählt bei EU-Adressen oft lokale Bezahlmethoden (wie Klarna oder EPS) als Default-Tab aus.
        const cardTab = stripeFrame.getByRole('button', { name: /Card|Kreditkarte/i }).first();
        await expect(cardTab).toBeVisible({ timeout: 15000 });
        await cardTab.click();

        await expect(stripeFrame.locator('input[autocomplete="cc-number"]')).toBeVisible({ timeout: 15000 });

        return { stripeFrame, form };
    };

    test('Negative Flow: Handles generic decline and insufficient funds via inline alert', async ({ page }) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        const { stripeFrame, form } = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.genericDecline);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();

        const inlineAlert = stripeFrame.locator('[role="alert"]');
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(/(fehlgeschlagen|declined|invalid|abgelehnt)/i);
        }).toPass({ timeout: 15000 });

        await page.locator('.toast button').click().catch(() => {});

        await form.fillStripeForm(stripeFrame, CreditCardHelper.insufficientFunds);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();
        await expect(async () => {
            const toastText = await page.locator('.toast').textContent().catch(() => '');
            const alertText = await inlineAlert.textContent().catch(() => '');
            expect(toastText + ' ' + alertText).toMatch(/(fehlgeschlagen|declined|invalid|abgelehnt)/i);
        }).toPass({ timeout: 15000 });
    });

    test('Positive Flow: Handles successful payment via Visa', async ({ page }) => {
        test.setTimeout(60000); // Erhöhtes Timeout für Multi-User Flow
        const { stripeFrame, form } = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.successVisa);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();

        await expect(page).toHaveURL(/.*\/orders/, { timeout: 15000 });
        await expect(page.locator('h1:has-text("Meine Einkäufe & Lizenzen")')).toBeVisible();
    });
});
