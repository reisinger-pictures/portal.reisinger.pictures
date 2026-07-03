import { test, expect } from '@playwright/test';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.describe('E7: Brand-Konflikt Route Guard', () => {
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

    test('User mit Brand rp und SRP-Tenant kann sich nicht auf SRP-Portal anmelden', async ({ page, request }) => {
        const headers = { 'Accept': 'application/json', 'Cookie': adminToken };

        const tenantRes = await request.post('/api/management/tenants', {
            data: { name: `SRP Tenant ${Math.random().toString(36).substring(2, 10)}`, invoice_frequency: 'immediate', brand: 'srp' },
            headers
        });
        if (!tenantRes.ok()) throw new Error(`Tenant creation failed: ${await tenantRes.text()}`);
        const tenantData = await tenantRes.json();
        const tenantId = tenantData.tenant?.id;
        if (!tenantId) throw new Error('Tenant ID missing');
        helper.trackTenant(tenantId);

        const uniqueId = Math.random().toString(36).substring(2, 10);
        const email = `e2e-brand-conflict-${uniqueId}@example.com`;
        const password = 'SecurePassword123!';

        const createRes = await request.post('/api/management/users', {
            data: { name: `E2E Brand Conflict ${uniqueId}`, email },
            headers
        });
        if (!createRes.ok()) throw new Error(`User creation failed: ${await createRes.text()}`);
        const createData = await createRes.json();
        const userId = createData.user?.id;
        if (!userId) throw new Error('User ID missing');
        helper.trackUser(userId);

        const rolesRes = await request.get('/api/management/roles', { headers });
        const roles = await rolesRes.json();
        const clientRoleId = roles.find((r: { name: string; id: string }) => r.name === 'client')?.id;
        if (!clientRoleId) throw new Error('client role not found');

        await request.put(`/api/management/users/${userId}`, {
            data: { role_ids: [clientRoleId], gallery_ids: [], gallery_group_ids: [], can_edit_metadata: false, brand: 'rp' },
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

        await page.goto('http://buy.localhost:4321/');
        await expect(page.getByTestId('app-loader').first()).toBeHidden({ timeout: 5000 });
        await expect(page.locator('main').first()).toBeVisible({ timeout: 5000 });

        const menuBtn = page.locator('header button').filter({ has: page.locator('svg') }).first();
        const backdrop = page.locator('div.fixed.inset-0').first();

        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
        }

        await page.fill('input[placeholder="E-Mail Adresse"]', email);
        await page.fill('input[placeholder="Passwort"]', password);

        const networkPromise = page.waitForResponse(res =>
            res.url().includes('/api/auth/login') && res.request().method() === 'POST'
        , { timeout: 30000 });

        await page.getByRole('button', { name: 'Login' }).first().scrollIntoViewIfNeeded();
        await page.keyboard.press('Enter');

        const loginResponse = await networkPromise;
        expect(loginResponse.status()).toBe(403);

        const body = await loginResponse.json();
        expect(body.error).toContain('anderes Portal');
    });
});
