import { APIRequestContext } from '@playwright/test';
import { MailpitHelper } from './MailpitHelper';

export class E2EUserHelper {
    static async createIsolatedUser(request: APIRequestContext, roleName: 'admin' | 'photographer' | 'client') {
        const uniqueId = Date.now() + '-' + Math.round(Math.random() * 10000);
        const email = `e2e-${roleName}-${uniqueId}@example.com`;
        const password = 'SecurePassword123!';
        
        const headers = { 'Accept': 'application/json' };

        // 1. Admin Login via API
        const loginRes = await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers
        });
        if (!loginRes.ok()) throw new Error("Admin login failed: " + await loginRes.text());

        // 2. Create User
        const createRes = await request.post('/api/management/users', {
            data: { name: `E2E ${roleName} ${uniqueId}`, email: email },
            headers
        });
        if (!createRes.ok()) throw new Error("User creation failed: " + await createRes.text());
        const createData = await createRes.json();
        const newUserId = createData.user?.id;

        if (!newUserId) throw new Error("Fehler beim Erstellen des Nutzers.");

        // 3. Assign Role
        const rolesRes = await request.get('/api/management/roles', { headers });
        if (!rolesRes.ok()) throw new Error("Roles fetch failed");
        const roles = await rolesRes.json();
        const targetRole = roles.find((r: any) => r.name === roleName);

        const putRes = await request.put(`/api/management/users/${newUserId}`, {
            data: { role_ids: [targetRole.id], gallery_group_ids: [], gallery_ids: [], can_edit_metadata: false },
            headers
        });
        if (!putRes.ok()) throw new Error("Role assignment failed: " + await putRes.text());

        // 4. Fetch token & set password via Mailpit
        const mailpit = new MailpitHelper(request);
        const token = await mailpit.extractLinkForEmail(email, /token=([a-zA-Z0-9]+)/);
        if (!token) throw new Error("Setup-Token nicht in Mailpit gefunden.");

        const resetRes = await request.post('/api/auth/reset-password', {
            data: { email, token, password },
            headers
        });
        if (!resetRes.ok()) throw new Error("Password reset failed: " + await resetRes.text());

        // 5. Logout Admin (um sauberen State für den Frontend-Test zu garantieren)
        await request.post('/api/auth/logout', { headers });

        return { email, password };
    }
}
