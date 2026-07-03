import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';

test.describe('Order Status Management (G7)', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };
    let photogUser = { email: '', password: '', id: '' };
    let powerUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
        photogUser = await helper.createIsolatedUser('photographer');
        powerUser = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can change order status', async ({ page }) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        const galleryName = `Order Test ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(photogUser.email, photogUser.password);
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();
        await auth.logout();

        await auth.login(adminUser.email, adminUser.password);

        await sidebar.navigateTo('Benutzer & Rechte');
        await page.fill('input[placeholder="Nutzer suchen (Name oder E-Mail)..."]', powerUser.email);
        await page.locator('tr').filter({ hasText: powerUser.email }).locator('button', { hasText: 'Bearbeiten' }).click();
        await modal.activeModal.locator('.label').filter({ hasText: galleryName }).locator('input[type="checkbox"]').check();
        await modal.submitModal('Speichern');

        await auth.logout();

        await auth.login(powerUser.email, powerUser.password);
        await page.locator('main').getByText(galleryName).first().click();

        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();

        await page.locator('label').filter({ hasText: 'Web & Social Media' }).click();
        await page.getByRole('button', { name: 'In den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('In den Warenkorb gelegt');

        await sidebar.navigateTo('Warenkorb');

        await form.fillCheckoutForm({
            name: 'Order Status Tester',
            street: 'Teststr. 1',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true,
            waiveWithdrawal: true,
        });

        await page.locator('input[name="payment_method"][value="invoice"]').click();
        await page.getByRole('button', { name: 'Zahlungspflichtig bestellen' }).click();
        await expect(page.locator('.toast')).toContainText('Bestellung erfolgreich!');
        await auth.logout();

        await auth.login(adminUser.email, adminUser.password);
        await page.waitForLoadState('networkidle');
        await expect(page.locator('main').first()).toBeVisible({ timeout: 15000 });
        await sidebar.navigateTo('Shop-Bestellungen');
        await expect(page).toHaveURL(/\/admin-orders/, { timeout: 15000 });

        await expect(page.locator('h1:has-text("Bestellungen & Anfragen")')).toBeVisible({ timeout: 10000 });

        await expect(page.locator('td', { hasText: powerUser.email }).first()).toBeVisible({ timeout: 10000 });

        const orderRow = page.locator('main table tr').filter({ hasText: powerUser.email }).first();
        const statusSelect = orderRow.locator('select');
        if (await statusSelect.isVisible()) {
            await statusSelect.selectOption({ label: 'Bezahlt' });
            await expect(page.locator('.toast')).toContainText('Status aktualisiert');
        }
    });
});
