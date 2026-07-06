import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import { FormHelper } from '../helpers/FormHelper';



test.describe('User Setup via Mailpit Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
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

    test('Admin invites a new user and user completes setup', { tag: ['@feature:auth:setup'] }, async ({ page, request }) => {
        // --- Phase 1: Admin lädt ein ---
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Benutzer & Rechte');

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        const form = new FormHelper(page, modal);
        await form.fillUserModal({ name: 'Test Mailpit User', email: newUserEmail });
        await modal.submitModal('Nutzer anlegen & Einladen');
        await expect(page.locator('.toast')).toContainText('Nutzer angelegt');

        // Track user for cleanup
        const usersRes = await request.get('/api/management/users').catch(() => null);
        if (usersRes && usersRes.ok()) {
            const usersData = await usersRes.json();
            const usersList = Array.isArray(usersData) ? usersData : usersData.data;
            const createdUser = usersList?.find((u: { email: string }) => u.email === newUserEmail);
            if (createdUser) helper.trackUser(createdUser.id);
        }

        // --- Phase 2: User setzt Passwort ---
        const token = await mailpit.extractPasswordResetToken(newUserEmail);

        expect(token).toBeTruthy();
        const setupLink = `http://localhost:4321/reset-password?token=${token}&email=${encodeURIComponent(newUserEmail)}`;

        await page.goto(setupLink);
        await expect(page.locator('h2:has-text("Konto einrichten")')).toBeVisible();

        await page.fill('input[type="password"]', 'SecurePassword123!');
        await page.locator('input[type="password"]').nth(1).fill('SecurePassword123!'); 
        await page.getByRole('button', { name: 'Passwort speichern & Anmelden' }).click();

        await expect(page.locator('.loading-spinner')).toBeHidden();
        await expect(page.locator('text=Aktuell sind keine privaten Galerien für dich freigeschaltet').first()).toBeVisible();
    });
});
