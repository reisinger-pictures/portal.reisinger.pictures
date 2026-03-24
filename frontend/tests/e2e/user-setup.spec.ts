import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
import { MailpitHelper } from './helpers/MailpitHelper';

test.describe.serial('User Setup via Mailpit Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;
    let mailpit: MailpitHelper;

    const uniqueId = Date.now();
    const newUserEmail = `e2e-user-${uniqueId}@example.com`;
    let setupLink = '';

    test.beforeAll(async ({ request }) => {
        mailpit = new MailpitHelper(request);
        await mailpit.deleteAllMessages(); // Postfach leeren vor dem Test
    });

    test.beforeEach(async ({ page, request }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        mailpit = new MailpitHelper(request);
    });

    test('Admin invites a new user via UI', async ({ page }) => {
        await auth.login();
        await sidebar.navigateTo('Benutzer & Rechte');
        
        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        await modal.fillInputByLabel('Name', 'Test Mailpit User');
        await modal.fillInputByLabel('E-Mail Adresse', newUserEmail);
        await modal.clickButton('Nutzer anlegen & Einladen');

        await expect(page.locator('.toast')).toContainText('Nutzer angelegt', { timeout: 10000 });
    });

    test('New user extracts setup link from Mailpit and sets password', async ({ page }) => {
        // Extrahiere den kompletten Link aus dem href-Attribut
        const regex = /token=([a-zA-Z0-9]+)/;
        const token = await mailpit.extractLinkForEmail(newUserEmail, regex);
        
        expect(token).toBeTruthy();
        setupLink = `http://localhost:4321/reset-password?token=${token}&email=${encodeURIComponent(newUserEmail)}`;

        // Navigiere zum Setup-Link
        await page.goto(setupLink);
        
        await expect(page.locator('h2:has-text("Account Setup")')).toBeVisible();
        
        // Passwort setzen
        await page.fill('input[type="password"]', 'SecurePassword123!');
        await page.locator('input[type="password"]').nth(1).fill('SecurePassword123!'); // Confirm
        await page.getByRole('button', { name: 'Passwort speichern & Anmelden' }).click();

        // Prüfen, ob wir im Dashboard gelandet sind (erfolgreich eingeloggt)
        // Warten bis der globale Lade-Spinner verschwindet (SWR Login-Check fertig)
        await expect(page.locator('.loading-spinner')).toBeHidden({ timeout: 20000 });
        await expect(page.locator('text=Aktuell sind keine Galerien für dich freigeschaltet').first()).toBeVisible({ timeout: 10000 });
    });
});
