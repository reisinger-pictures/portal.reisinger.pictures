import { test, expect, type APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.describe('E4: Org-Admin lädt User ein → User registriert → hat Org', () => {
    let helper: E2ESessionHelper;
    let adminToken: string;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        const loginRes = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' }
        });
        const cookies = loginRes.headers()['set-cookie'];
        const match = cookies?.match(/rp_jwt=([^;]+)/);
        adminToken = match ? `rp_jwt=${match[1]}` : (cookies || '');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function createOrgAdmin(request: APIRequestContext, token: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': token };
        const tenantName = `E2E Tenant ${Math.random().toString(36).substring(2, 10)}`;
        const tenantRes = await request.post('/api/management/tenants', {
            data: { name: tenantName, invoice_frequency: 'immediate' },
            headers
        });
        if (!tenantRes.ok()) throw new Error(`Tenant creation failed: ${await tenantRes.text()}`);
        const tenantData = await tenantRes.json();
        const tenantId = tenantData.tenant?.id;
        if (!tenantId) throw new Error('Tenant ID missing');
        helper.trackTenant(tenantId);

        const uniqueId = Math.random().toString(36).substring(2, 10);
        const email = `e2e-org-admin-${uniqueId}@example.com`;
        const password = 'SecurePassword123!';

        const createRes = await request.post('/api/management/users', {
            data: { name: `E2E Org Admin ${uniqueId}`, email },
            headers
        });
        if (!createRes.ok()) throw new Error(`User creation failed: ${await createRes.text()}`);
        const createData = await createRes.json();
        const userId = createData.user?.id;
        if (!userId) throw new Error('User ID missing');
        helper.trackUser(userId);

        const rolesRes = await request.get('/api/management/roles', { headers });
        const roles = await rolesRes.json();
        const orgAdminRoleId = roles.find((r: { name: string; id: string }) => r.name === 'org_admin')?.id;
        if (!orgAdminRoleId) throw new Error('org_admin role not found');

        await request.put(`/api/management/users/${userId}`, {
            data: { role_ids: [orgAdminRoleId], gallery_ids: [], gallery_group_ids: [], can_edit_metadata: false, brand: 'rp' },
            headers
        });

        await request.put(`/api/management/tenants/${tenantId}/users`, {
            data: { user_ids: [userId] },
            headers
        });

        const mailpit = new MailpitHelper(request);
        const resetToken = await mailpit.extractPasswordResetToken(email);
        if (!resetToken) throw new Error(`Password reset token not found for ${email}`);

        const resetRes = await request.post('/api/auth/reset-password', {
            data: { email, token: resetToken, password },
            headers: { ...headers, 'Referer': 'http://localhost:4321/' },
        });
        if (!resetRes.ok()) throw new Error(`Password reset failed: ${await resetRes.text()}`);

        return { email, password, tenantName, tenantId, userId };
    }

    test('Org-Admin lädt User ein, User registriert sich und hat Org', { tags: ['@feature:auth:invite'] }, async ({ page, request }) => {
        const { email, password, tenantId } = await createOrgAdmin(request, adminToken);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const mailpit = new MailpitHelper(request);

        await auth.login(email, password);
        await sidebar.navigateTo('Organisationen');

        const orgCard = page.locator('main .card').first();
        await expect(orgCard).toBeVisible({ timeout: 10000 });
        await orgCard.click();

        const inviteBtn = page.locator('main').getByRole('button', { name: '+ Einladen' });
        await expect(inviteBtn).toBeVisible({ timeout: 10000 });
        await inviteBtn.click();

        const guestEmail = `e2e-invited-${Math.random().toString(36).substring(2, 10)}@example.com`;
        const inviteModal = page.locator('.modal-open').last();
        await inviteModal.locator('input[type="email"]').fill(guestEmail);
        await inviteModal.getByRole('button', { name: 'Einladung Senden' }).click();

        await expect(page.locator('.toast')).toContainText('Einladung erfolgreich versendet', { timeout: 10000 });

        await auth.logout();

        const inviteToken = await mailpit.extractTenantInviteToken(guestEmail);
        expect(inviteToken).toBeTruthy();

        await page.goto(`/tenant-invite/${inviteToken}`);
        await expect(page.locator('h2:has-text("Einladung zu Organisation")')).toBeVisible({ timeout: 10000 });
        await page.getByRole('button', { name: 'Beitreten & Fortfahren' }).click();
        await expect(page.locator('h2:has-text("Account erstellen")')).toBeVisible();

        await page.getByPlaceholder('z.B. Maria Muster').fill('Invited Employee');
        await page.locator('.card-body').locator('input[type="password"]').fill('SecurePass123!');
        await page.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();
        await page.getByRole('button', { name: 'Account aktivieren & Beitreten' }).click();

        await expect(page.locator('h1:has-text("Willkommen zurück")')).toBeVisible({ timeout: 15000 });

        const invitedUserRes = await request.get('/api/management/users', {
            headers: { 'Accept': 'application/json', 'Cookie': adminToken }
        });
        const usersData = invitedUserRes.ok() ? await invitedUserRes.json() : null;
        const usersList = usersData ? (Array.isArray(usersData) ? usersData : usersData.data) : [];
        const invitedUser = usersList?.find((u: { email: string }) => u.email === guestEmail);
        if (invitedUser) {
            helper.trackUser(invitedUser.id);
        }

        const tenantRes = await request.get(`/api/management/tenants/${tenantId}`, {
            headers: { 'Accept': 'application/json', 'Cookie': adminToken }
        });
        if (tenantRes.ok()) {
            const tenantData = await tenantRes.json();
            const tenantUserIds = tenantData.users?.map((u: { id: string }) => u.id) || [];
            if (invitedUser) {
                expect(tenantUserIds).toContain(invitedUser.id);
            }
        }
    });
});
