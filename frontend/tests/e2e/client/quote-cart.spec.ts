import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Custom Quotes Full Workflow', () => {
    let helper: E2ESessionHelper;
    let clientUser: { email: string; password: string; id: string };
    let photogUser: { email: string; password: string; id: string };
    let adminUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        clientUser = await helper.createIsolatedUser('client');
        photogUser = await helper.createIsolatedUser('photographer');
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Client requests quote, Admin answers via Mail, Client accepts', async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);
        const mailpit = new MailpitHelper(request);
        const sidebar = new SidebarHelper(page);

        await mailpit.deleteAllMessages();

        // --- 1. SETUP: Fotograf erstellt Galerie & Bild ---
        await auth.login(photogUser.email, photogUser.password);
        const galleryName = `Quote Test ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await upload.uploadSampleImage();

        const validAdminToken = helper.getAdminToken();
        const rolesRes = await page.request.get('/api/management/roles', { headers: { 'Cookie': validAdminToken } });
        const rolesData = await rolesRes.json();
        const roles = Array.isArray(rolesData) ? rolesData : (rolesData.data || []);
        const clientRoleId = roles.find((r: any) => r.name === 'client')?.id;

        const galleryUrl = page.url();
        const gallerySlug = galleryUrl.split('/').pop();
        const galRes = await page.request.get(`/api/galleries/${gallerySlug}`, { headers: { 'Cookie': validAdminToken } });
        const galData = await galRes.json();
        const galId = galData.gallery?.id;

        // Wir weisen dem Client die Galerie zu
        if (clientRoleId && galId) {
            await page.request.put(`/api/management/users/${clientUser.id}`, {
                data: { role_ids: [clientRoleId], gallery_ids: [galId], gallery_group_ids: [], can_edit_metadata: false },
                headers: { 'Cookie': validAdminToken, 'Accept': 'application/json', 'Content-Type': 'application/json' }
            });
        }
        await auth.logout();

        // --- 2. CLIENT: Fragt Angebot an ---
        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').getByText(galleryName).first().click();
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        
        await page.fill('textarea[placeholder*="Besondere Anforderungen"]', 'Exklusiv für Kampagne.');
        await page.getByRole('button', { name: 'Als Angebot in den Warenkorb' }).click();
        await expect(page.locator('.toast')).toContainText('Angebot zum Warenkorb hinzugefügt');

        await page.goto('/cart');
        await expect(page.locator('.text-3xl.font-mono.text-primary')).toHaveText('--- €');
        
        await form.fillCheckoutForm({
            name: 'Client Quote Tester',
            street: 'Client Street 1',
            zip: '1010',
            city: 'Wien',
            acceptAgb: true
        });
        await page.fill('textarea[name="quote_message"]', 'Das ist die globale Nachricht.');

        await page.getByRole('button', { name: 'Unverbindlich anfragen' }).click();
        await expect(page.locator('.toast')).toContainText('Angebot erfolgreich angefragt!');
        await auth.logout();

        // --- 3. ADMIN: Kalkuliert & sendet Mail ---
        await auth.login(adminUser.email, adminUser.password);
        
        await sidebar.navigateTo('Bestellungen & Anfragen');
        
        const firstRow = page.locator('tbody tr').first();
        await expect(firstRow).toContainText('Angebot');
        await expect(firstRow).toContainText('Auf Anfrage');
        
        await firstRow.getByRole('button', { name: 'Kalkulieren & Antworten' }).click();
        
        const quoteModal = page.locator('.modal-open');
        await expect(quoteModal).toBeVisible();
        await expect(quoteModal).toContainText('Das ist die globale Nachricht.');
        await expect(quoteModal).toContainText('Exklusiv für Kampagne.');
        
        await quoteModal.locator('input[type="number"]').fill('1500');
        await quoteModal.locator('textarea').fill('Hallo, hier ist mein Angebot: 1500 Euro.');
        await quoteModal.getByRole('button', { name: 'Kalkulieren & E-Mail senden' }).click();
        
        await expect(page.locator('.toast')).toContainText('Angebot per E-Mail gesendet!');
        await auth.logout();

        // --- 4. CLIENT: Öffnet Link aus Mail ---
        const token = await mailpit.extractLinkForEmail(clientUser.email, /quote_token=([a-zA-Z0-9\.\-_]+)/);
        expect(token).toBeTruthy();

        await auth.login(clientUser.email, clientUser.password);
        await page.goto('/cart?quote_token=' + token);
        
        await expect(page.locator('.toast')).toContainText('Angebot aus Link wiederhergestellt.');
        await expect(page.locator('.text-3xl.font-mono.text-primary')).toHaveText('1500.00 €');
        await expect(page.getByRole('button', { name: 'Zahlungspflichtig bestellen' })).toBeVisible();
    });
});
