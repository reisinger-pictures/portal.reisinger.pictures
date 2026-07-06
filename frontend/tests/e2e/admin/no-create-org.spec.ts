import { test, expect, type APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.describe('E6: Kein Organisation anlegen Button für Org-Admin', () => {
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

    test('Org-Admin sieht keinen Organisation anlegen Button', { tag: ['@feature:admin:tenant'] }, async ({ page, request }) => {
        const { email, password } = await createOrgAdmin(request, adminToken);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(email, password);
        await sidebar.navigateTo('Organisationen');

        await expect(page.locator('main')).toBeVisible({ timeout: 10000 });

        const createBtn = page.locator('main').getByRole('button', { name: '+ Neue Organisation' });
        await expect(createBtn).toHaveCount(0);

        const createBtnAlt = page.locator('main').getByRole('button', { name: /Neue Organisation/i });
        await expect(createBtnAlt).toHaveCount(0);
    });
});
