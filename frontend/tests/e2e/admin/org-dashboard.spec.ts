import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import { FormHelper } from '../helpers/FormHelper';
import { UserDetailed } from '../../../src/logic/useUsers';

test.describe('Org Management & Invoicing Workflow', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Flow AB & AE: Org Invite and Collective Invoice View', { tag: ['@feature:admin:Org'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const mailpit = new MailpitHelper(request);
        
        const orgName = `Org ${Math.random().toString(36).substring(2, 10)}`;
        const guestEmail = `Org-guest-${Math.random().toString(36).substring(2, 10)}@example.com`;

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Organisationen');

        await page.getByRole('button', { name: '+ Neue Organisation' }).click();
        const form = new FormHelper(page, modal);
        await form.fillOrgModal({ name: orgName });
        const resData = await modal.submitModal('Speichern');
        if (resData?.org?.id) helper.trackOrg(resData.org.id);

        await page.locator('.card-title', { hasText: orgName }).click();
        await expect(page.locator(`h1:has-text("${orgName}")`)).toBeVisible();

        await page.getByRole('button', { name: '+ Einladen' }).click();
        await modal.activeModal.locator('input[type="email"]').fill(guestEmail);
        await modal.submitModal('Einladung Senden');
        await expect(page.locator('.toast')).toContainText('Einladung erfolgreich versendet');
        
        const token = await mailpit.extractOrgInviteToken(guestEmail);
        expect(token).toBeTruthy();
        
        await auth.logout();

        const inviteLink = `/org-invite/${token}`;
        await page.goto(inviteLink);
        await expect(page.locator('h2:has-text("Einladung zu Organisation")')).toBeVisible();
        await page.getByRole('button', { name: 'Beitreten & Fortfahren' }).click();
        await expect(page.locator('h2:has-text("Account erstellen")')).toBeVisible();
        await page.getByPlaceholder('z.B. Maria Muster').fill('Org Angestellter');
        await page.locator('.card-body').locator('input[type="password"]').fill('SecurePass123!');
        await page.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();
        await page.getByRole('button', { name: 'Account aktivieren & Beitreten' }).click();
        
        await expect(page.locator('h1:has-text("Willkommen zurück")')).toBeVisible({ timeout: 15000 });
        await auth.logout();

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Organisationen');
        await page.locator('.card-title', { hasText: orgName }).click();
        
        const invBtn = page.getByRole('button', { name: 'Sammelrechnung erstellen' });
        await expect(invBtn).toBeVisible();

        const usersRes = await request.get('/api/management/users', { headers: { 'Cookie': helper.getAdminToken() } });
        const usersData = await usersRes.json();
        const guestUser = usersData.data?.find((u: UserDetailed) => u.email === guestEmail);
        if (guestUser) {
            await request.delete(`/api/test/cleanup-user/${guestUser.id}`, { headers: { 'Cookie': helper.getAdminToken() } });
        }
    });
});