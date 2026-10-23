import { test, expect, type APIRequestContext } from '@playwright/test';
import { extractCookieHeader } from '../helpers/E2ECookieJar';
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
            data: { email: 'admin@example.com', password: 'admin' },
            headers: { 'Accept': 'application/json' }
        });
        if (!loginRes.ok()) throw new Error(`Admin login failed: ${await loginRes.text()}`);
        adminToken = extractCookieHeader(loginRes);
        if (!adminToken) throw new Error('Admin login response did not contain an auth cookie');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function createOrgAdmin(request: APIRequestContext, token: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': token };
        const orgName = `E2E Org ${Math.random().toString(36).substring(2, 10)}`;
        const tenantRes = await request.post('/api/management/orgs', {
            data: { name: orgName, invoice_frequency: 'immediate' },
            headers
        });
        if (!tenantRes.ok()) throw new Error(`Org creation failed: ${await tenantRes.text()}`);
        const tenantData = await tenantRes.json();
        const orgId = tenantData.org?.id;
        if (!orgId) throw new Error('Org ID missing');
        helper.trackOrg(orgId);

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
        if (!rolesRes.ok()) throw new Error(`Role lookup failed. Status: ${rolesRes.status()} Body: ${await rolesRes.text()}`);
        const roles = await rolesRes.json() as Array<{ name: string; id: string }>;
        const orgAdminRole = roles.find((role) => role.name === 'org_admin');
        if (!orgAdminRole) throw new Error('org_admin role not found');

        const orgUpdateRes = await request.put(`/api/management/orgs/${orgId}`, {
            data: { name: orgName, invoice_frequency: 'immediate', default_role_id: orgAdminRole.id },
            headers
        });
        if (!orgUpdateRes.ok()) throw new Error(`Failed to configure organization default role: ${await orgUpdateRes.text()}`);

        const updateUserRes = await request.put(`/api/management/users/${userId}`, {
            data: { role_ids: [orgAdminRole.id], gallery_ids: [], gallery_group_ids: [], can_edit_metadata: false, brand: 'rp' },
            headers
        });
        if (!updateUserRes.ok()) {
            throw new Error(`Failed to configure user ${email}. Status: ${updateUserRes.status()} Body: ${await updateUserRes.text()}`);
        }

        await request.put(`/api/management/orgs/${orgId}/users`, {
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

        return { email, password, orgName, orgId, userId };
    }

    test('Org-Admin lädt User ein, der die konfigurierte Organisationsrolle erhält', { tag: ['@feature:auth:invite', '@feature:org-default-role'] }, async ({ page, request }) => {
        const { email, password, orgId, orgName } = await createOrgAdmin(request, adminToken);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const mailpit = new MailpitHelper(request);
        const main = page.getByRole('main');

        await auth.login(email, password);
        await sidebar.navigateTo('Organisationen');

        const orgCard = main.locator('.card').filter({ hasText: orgName });
        await expect(orgCard).toBeVisible({ timeout: 10000 });
        await orgCard.click();

        const inviteBtn = main.getByRole('button', { name: '+ Einladen' });
        await expect(inviteBtn).toBeVisible({ timeout: 10000 });
        await inviteBtn.click();

        const guestEmail = `e2e-invited-${Math.random().toString(36).substring(2, 10)}@example.com`;
        const inviteModal = main.getByRole('dialog', { name: 'Nutzer in Organisation einladen' });
        await inviteModal.locator('input[type="email"]').fill(guestEmail);
        await inviteModal.getByRole('button', { name: 'Einladung Senden' }).click();
        await expect(inviteModal).toHaveCount(0);

        await auth.logout();

        const inviteToken = await mailpit.extractOrgInviteToken(guestEmail);
        expect(inviteToken).toBeTruthy();

        await page.goto(`/org-invite/${inviteToken}`);
        await expect(main.getByRole('heading', { name: 'Einladung zu Organisation' })).toBeVisible({ timeout: 10000 });
        await main.getByRole('button', { name: 'Beitreten & Fortfahren' }).click();
        await expect(main.getByRole('heading', { name: 'Account erstellen' })).toBeVisible();

        await main.getByPlaceholder('z.B. Maria Muster').fill('Invited Employee');
        await main.locator('input[type="password"]').fill('SecurePass123!');
        await main.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();

        const redeemResponsePromise = page.waitForResponse(
            (response) => response.url().includes('/api/org-invites/redeem') && response.request().method() === 'POST',
        );
        await main.getByRole('button', { name: 'Account aktivieren & Beitreten' }).click();
        const redeemResponse = await redeemResponsePromise;
        if (!redeemResponse.ok()) throw new Error(`Invite redeem failed: ${await redeemResponse.text()}`);

        // The invitee receives the configured org_admin role, which counts as
        // staff (`isStaff`), so `ProtectedDashboard` renders the management
        // dashboard — never the client dashboard. The redeem call and the
        // redirect to `/` are the registration-completion signals.
        //
        // Do not drive the mobile drawer here: the click used to race the route
        // transition, opening the drawer on the invite page while the link then
        // resolved in the freshly mounted dashboard whose drawer was still
        // closed (Playwright: "element is outside of the viewport"). The landing
        // assertion below instead proves the logged-in dashboard rendered the
        // org-admin management entry, which exists only for admins/org admins.
        await expect(page).toHaveURL(/\/$/);
        const dashboardSidebar = page.getByRole('complementary');
        await expect(dashboardSidebar.getByRole('link', { name: 'Mein Team' }))
            .toHaveAttribute('href', '/users', { timeout: 15000 });

        const invitedUserRes = await request.get('/api/management/users', {
            headers: { 'Accept': 'application/json', 'Cookie': adminToken }
        });
        if (!invitedUserRes.ok()) throw new Error(`User lookup failed. Status: ${invitedUserRes.status()} Body: ${await invitedUserRes.text()}`);
        type ManagedUser = { id: string; email: string; is_photographer: boolean; roles?: Array<{ name: string }> };
        const usersData = await invitedUserRes.json() as { data?: ManagedUser[] } | ManagedUser[];
        const usersList = Array.isArray(usersData) ? usersData : usersData.data ?? [];
        const invitedUser = usersList.find((user) => user.email === guestEmail);
        if (!invitedUser) throw new Error(`Invited user ${guestEmail} was not found after registration.`);
        helper.trackUser(invitedUser.id);

        expect(invitedUser.roles?.map((role) => role.name)).toContain('org_admin');
        expect(invitedUser.roles?.map((role) => role.name)).not.toContain('photographer');
        expect(invitedUser.is_photographer).toBe(false);

        const invitedLoginRes = await request.post('/api/auth/login', {
            data: { email: guestEmail, password: 'SecurePass123!' },
            headers: { 'Accept': 'application/json' }
        });
        if (!invitedLoginRes.ok()) throw new Error(`Invited user login failed: ${await invitedLoginRes.text()}`);
        const invitedCookie = extractCookieHeader(invitedLoginRes);
        if (!invitedCookie) throw new Error('Invited user login response did not contain an auth cookie');

        const ftpProcessRes = await request.post('/api/management/ftp/process', {
            headers: { 'Accept': 'application/json', 'Cookie': invitedCookie }
        });
        expect(ftpProcessRes.status()).toBe(403);

        const tenantRes = await request.get(`/api/management/orgs/${orgId}`, {
            headers: { 'Accept': 'application/json', 'Cookie': adminToken }
        });
        if (!tenantRes.ok()) throw new Error(`Organization lookup failed. Status: ${tenantRes.status()} Body: ${await tenantRes.text()}`);
        const tenantData = await tenantRes.json() as { users?: Array<{ id: string }> };
        const tenantUserIds = tenantData.users?.map((user) => user.id) ?? [];
        expect(tenantUserIds).toContain(invitedUser.id);
    });
});
