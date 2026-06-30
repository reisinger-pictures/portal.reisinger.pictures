import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import { FormHelper } from '../helpers/FormHelper';
import { UserDetailed } from '../../../src/logic/useUsers';

test.describe('Tenant Management & Invoicing Workflow', () => {
    let helper: E2ESessionHelper;
    let adminUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Flow AB & AE: Tenant Invite and Collective Invoice View', async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const mailpit = new MailpitHelper(request);
        
        const tenantName = `Tenant ${Math.random().toString(36).substring(2, 10)}`;
        const guestEmail = `tenant-guest-${Math.random().toString(36).substring(2, 10)}@example.com`;

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Mandanten');

        await page.getByRole('button', { name: '+ Neue Organisation' }).click();
        const form = new FormHelper(page, modal);
        await form.fillTenantModal({ name: tenantName });
        const resData = await modal.submitModal('Speichern');
        if (resData?.tenant?.id) helper.trackTenant(resData.tenant.id);

        await page.locator('.card-title', { hasText: tenantName }).click();
        await expect(page.locator(`h1:has-text("${tenantName}")`)).toBeVisible();

        await page.getByRole('button', { name: '+ Einladen' }).click();
        await modal.activeModal.locator('input[type="email"]').fill(guestEmail);
        await modal.submitModal('Einladung Senden');
        await expect(page.locator('.toast')).toContainText('Einladung erfolgreich versendet');
        
        const token = await mailpit.extractTenantInviteToken(guestEmail);
        expect(token).toBeTruthy();
        
        await auth.logout();

        const inviteLink = `/tenant-invite/${token}`;
        await page.goto(inviteLink);
        await expect(page.locator('h2:has-text("Unternehmens-Account")')).toBeVisible();
        await page.getByPlaceholder('z.B. Maria Muster').fill('Tenant Angestellter');
        await page.locator('.card-body').locator('input[type="password"]').fill('SecurePass123!');
        await page.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();
        await page.getByRole('button', { name: 'Account aktivieren & Anmelden' }).click();
        
        await expect(page.locator('h1:has-text("Willkommen zurück")')).toBeVisible({ timeout: 15000 });
        await auth.logout();

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Mandanten');
        await page.locator('.card-title', { hasText: tenantName }).click();
        
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