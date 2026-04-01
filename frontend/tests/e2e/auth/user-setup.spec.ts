import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupE2EData(request);
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

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const newUserEmail = `e2e-user-${uniqueId()}@example.com`;

    test.beforeEach(async ({ page, request }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        mailpit = new MailpitHelper(request);
    });

    test('Admin invites a new user and user completes setup', async ({ page }) => {
        // --- Phase 1: Admin lädt ein ---
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Benutzer & Rechte');

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        await modal.fillInputByLabel('Name', 'Test Mailpit User');
        await modal.fillInputByLabel('E-Mail Adresse', newUserEmail);
        await modal.clickButton('Nutzer anlegen & Einladen');

        await expect(page.locator('.toast')).toContainText('Nutzer angelegt', { timeout: 15000 });

        // --- Phase 2: User setzt Passwort ---
        const regex = /token=([a-zA-Z0-9]+)/;
        const token = await mailpit.extractLinkForEmail(newUserEmail, regex);

        expect(token).toBeTruthy();
        const setupLink = `http://localhost:4321/reset-password?token=${token}&email=${encodeURIComponent(newUserEmail)}`;

        await page.goto(setupLink);
        await expect(page.locator('h2:has-text("Account Setup")')).toBeVisible();

        await page.fill('input[type="password"]', 'SecurePassword123!');
        await page.locator('input[type="password"]').nth(1).fill('SecurePassword123!'); 
        await page.getByRole('button', { name: 'Passwort speichern & Anmelden' }).click();

        await expect(page.locator('.loading-spinner')).toBeHidden();
        await expect(page.locator('text=Aktuell sind keine Galerien für dich freigeschaltet').first()).toBeVisible();
    });
});
