import { APIRequestContext } from '@playwright/test';
import { MailpitHelper } from './MailpitHelper';

export class E2ESessionHelper {
    private createdUserIds: string[] = [];
    private createdGalleryIds: string[] = [];
    private createdGroupIds: string[] = [];
    private createdTenantIds: string[] = [];
    private adminToken: string | null = null;

    constructor(private request: APIRequestContext) {}

    private async ensureAdminLogin() {
        if (this.adminToken) return;
        const loginRes = await this.request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' },
            headers: { 'Accept': 'application/json' }
        });
        if (!loginRes.ok()) throw new Error('Admin login failed: ' + await loginRes.text());
        const cookies = loginRes.headers()['set-cookie'];
        const match = cookies?.match(/rp_jwt=([^;]+)/);
        this.adminToken = match ? `rp_jwt=${match[1]}` : (cookies || '');
    }

    getAdminToken() {
        return this.adminToken || '';
    }

    async createIsolatedUser(roleName: 'admin' | 'photographer' | 'client' | 'power_user' | 'customer_manager' | 'super_admin', options?: { assignGalleryId?: string, wantsNotifications?: boolean }) {
        await this.ensureAdminLogin();
        const uniqueId = Math.random().toString(36).substring(2, 10);
        const email = `e2e-${roleName}-${uniqueId}@example.com`;
        const password = 'SecurePassword123!';
        
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };

        const createRes = await this.request.post('/api/management/users', {
            data: { name: `E2E ${roleName}`, email },
            headers
        });
        if (!createRes.ok()) throw new Error(`Failed to create user ${email}. Status: ${createRes.status()} Body: ${await createRes.text()}`);
        const createData = await createRes.json();
        const userId = createData.user?.id;
        if (!userId) throw new Error('User ID missing in response: ' + JSON.stringify(createData));
        this.createdUserIds.push(userId);

        const rolesRes = await this.request.get('/api/management/roles', { headers });
        const roles = await rolesRes.json();
        const roleId = roles.find((r: { name: string; id: string }) => r.name === roleName).id;

        await this.request.put(`/api/management/users/${userId}`, {
            data: {
                role_ids: [roleId],
                gallery_ids: options?.assignGalleryId ? [options.assignGalleryId] : [],
                gallery_group_ids: [],
                can_edit_metadata: false
            },
            headers
        });

        const mailpit = new MailpitHelper(this.request);
        const token = await mailpit.extractPasswordResetToken(email);
        const resetRes = await this.request.post('/api/auth/reset-password', { data: { email, token, password }, headers });
        const userCookies = resetRes.headers()['set-cookie'];

        if (options?.assignGalleryId && options?.wantsNotifications) {
            await this.request.post(`/api/galleries/${options.assignGalleryId}/opt-in`, {
                data: { wants_notifications: true },
                headers: { 'Accept': 'application/json', 'Cookie': userCookies! }
            });
        }

        return { email, password, id: userId };
    }

    trackUser(id: string) { if (id) this.createdUserIds.push(id); }
    trackGallery(id: string) { if (id) this.createdGalleryIds.push(id); }
    trackGroup(id: string) { if (id) this.createdGroupIds.push(id); }
    trackTenant(id: string) { if (id) this.createdTenantIds.push(id); }

    async teardown() {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };

        for (const id of this.createdGalleryIds) {
            await this.request.delete(`/api/management/galleries/${id}`, { headers }).catch(() => {});
        }
        for (const id of this.createdGroupIds) {
            await this.request.delete(`/api/management/gallery-groups/${id}`, { headers }).catch(() => {});
        }
        for (const id of this.createdUserIds) {
            await this.request.delete(`/api/test/cleanup-user/${id}`, { headers }).catch(() => {});
        }
        for (const id of this.createdTenantIds) {
            await this.request.delete(`/api/management/tenants/${id}`, { headers }).catch(() => {});
        }
    }
}