import { test, expect, Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { CreditCardHelper } from '../helpers/CreditCardHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('Stripe Checkout Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');

        const loginRes = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' }
        });
        const cookies = loginRes.headers()['set-cookie'];
        const match = cookies?.match(/rp_jwt=([^;]+)/);
        const adminToken = match ? `rp_jwt=${match[1]}` : (cookies || '');

        const rolesRes = await request.get('/api/management/roles', { 
            headers: { 'Cookie': adminToken as string } 
        });
        const roles = await rolesRes.json();
        const photogRoleId = roles.find((r: { id: string; name: string }) => r.name === 'photographer').id;
        const powerRoleId = roles.find((r: { id: string; name: string }) => r.name === 'power_user').id;

        await request.put(`/api/management/users/${testUser.id}`, {
            data: {
                role_ids: [photogRoleId, powerRoleId],
                gallery_ids: [],
                gallery_group_ids: [],
                can_edit_metadata: false
            },
            headers: { 'Cookie': adminToken as string, 'Accept': 'application/json', 'Content-Type': 'application/json' }
        });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const navigateToStripeIframe = async (page: Page) => {
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);

        await auth.login(testUser.email, testUser.password);

        const galleryName = `Stripe Test ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await upload.uploadSampleImage();

        const photoEl = page.locator('.pswp-item').first();
        await expect(photoEl).toBeVisible();
        const photoId = await photoEl.getAttribute('data-photo-id');
        expect(photoId).toBeTruthy();

        await page.evaluate((id) => {
            localStorage.setItem('rp_cart', JSON.stringify([{
                photoId: id,
                filename: 'Stripe_Masterfile.jpg',
                tier: 'original',
                usage: 'commercial',
                duration: 'unlimited',
                price: 150.00,
                isQuote: false
            }]));
        }, photoId);

        await page.goto('/cart');
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();

        await form.fillCheckoutForm({
            name: 'E2E Stripe Tester',
            street: 'Teststraße 42',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true
        });

        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();
        await expect(page.locator('h2:has-text("Zahlung abschließen")')).toBeVisible({ timeout: 15000 });
        
        const stripeFrame = page.frameLocator('.StripeElement iframe');
        await expect(stripeFrame.locator('input[autocomplete="cc-number"]')).toBeVisible({ timeout: 15000 });
        
        return { stripeFrame, form };
    };

    test('Negative Flow: Handles generic decline and insufficient funds via inline alert', async ({ page }) => {
        const { stripeFrame, form } = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.genericDecline);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();
        await expect(page.locator('.toast')).toContainText(/(fehlgeschlagen|declined|invalid|abgelehnt)/i, { timeout: 15000 });

        await page.locator('.toast button').click().catch(() => {});

        await form.fillStripeForm(stripeFrame, CreditCardHelper.insufficientFunds);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();
        await expect(page.locator('.toast')).toContainText(/(fehlgeschlagen|declined|invalid|abgelehnt)/i, { timeout: 15000 });
    });

    test('Positive Flow: Handles successful payment via Visa', async ({ page }) => {
        const { stripeFrame, form } = await navigateToStripeIframe(page);

        await form.fillStripeForm(stripeFrame, CreditCardHelper.successVisa);
        await page.getByRole('button', { name: 'Jetzt bezahlen' }).click();

        await expect(page).toHaveURL(/.*\/orders/, { timeout: 15000 });
        await expect(page.locator('h1:has-text("Meine Einkäufe & Lizenzen")')).toBeVisible();
    });
});