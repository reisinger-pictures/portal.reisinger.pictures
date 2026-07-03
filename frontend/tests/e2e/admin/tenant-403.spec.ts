import { test, expect, type APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.describe('E3: Org-Admin sieht keine fremden Orgs', () => {
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

    async function createTenant(request: APIRequestContext, token: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': token };
        const name = `E2E Tenant ${Math.random().toString(36).substring(2, 10)}`;
        const res = await request.post('/api/management/tenants', {
            data: { name, invoice_frequency: 'immediate' },
            headers
        });
        if (!res.ok()) throw new Error(`Tenant creation failed: ${await res.text()}`);
        const data = await res.json();
        helper.trackTenant(data.tenant.id);
        return data.tenant;
    }

    async function createOrgAdminForTenant(request: APIRequestContext, token: string, tenantId: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': token };
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

        return { email, password, userId };
    }

    test('Org-Admin kann nicht auf fremde Org zugreifen', async ({ page, request }) => {
        const tenantA = await createTenant(request, adminToken);
        const tenantB = await createTenant(request, adminToken);

        const orgAdmin = await createOrgAdminForTenant(request, adminToken, tenantA.id);
        const auth = new AuthHelper(page);

        await auth.login(orgAdmin.email, orgAdmin.password);

        await page.goto(`/tenants/${tenantB.id}`);

        const hasError = page.locator('.alert').getByText('Forbidden').first();
        const has404 = page.locator('.alert').getByText('nicht gefunden').first();
        const isRedirected = page.locator('h1:has-text("Organisationen")').first();

        await expect(
            hasError.or(has404).or(isRedirected)
        ).toBeVisible({ timeout: 15000 });
    });
});
