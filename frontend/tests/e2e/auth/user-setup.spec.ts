import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('User Setup via Mailpit Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'admin');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;
    let mailpit: MailpitHelper;

    const uniqueId = () => Date.now() + Math.floor(Math.random() * 1000);
    const newUserEmail = `e2e-user-${uniqueId()}@example.com`;
    let setupLink = '';
    let isUserInvited = false;

    test.beforeEach(async ({ page, request }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        mailpit = new MailpitHelper(request);
    });

    test('Admin invites a new user via UI', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Benutzer & Rechte');

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        await modal.fillInputByLabel('Name', 'Test Mailpit User');
        await modal.fillInputByLabel('E-Mail Adresse', newUserEmail);
        await modal.clickButton('Nutzer anlegen & Einladen');

        await expect(page.locator('.toast')).toContainText('Nutzer angelegt');
        isUserInvited = true;
    });

    test('New user extracts setup link from Mailpit and sets password', async ({ page }) => {
        test.skip(!isUserInvited, 'Test requires user to be invited in previous step');

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
        await expect(page.locator('.loading-spinner')).toBeHidden();
        await expect(page.locator('text=Aktuell sind keine Galerien für dich freigeschaltet').first()).toBeVisible();
    });
});