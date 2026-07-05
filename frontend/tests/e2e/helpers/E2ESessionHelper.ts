import { APIRequestContext } from '@playwright/test';
import { MailpitHelper } from './MailpitHelper';

export class E2ESessionHelper {
    private createdUserIds: string[] = [];
    private createdGalleryIds: string[] = [];
    private createdGroupIds: string[] = [];
    private createdTenantIds: string[] = [];
    private createdCustomerIds: string[] = [];
    private createdSnippetIds: string[] = [];
    private createdProductIds: string[] = [];
    private createdContractIds: string[] = [];
    private createdCouponIds: string[] = [];
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

    async createIsolatedUser(roleName: 'admin' | 'photographer' | 'client' | 'power_user' | 'customer_manager' | 'super_admin', options?: { assignGalleryId?: string, wantsNotifications?: boolean, brand?: 'rp' | 'srp' }) {
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

        // U-02: non-super-admin users must have a brand assigned. Super-admin is cross-brand.
        const brand = options?.brand ?? (roleName === 'super_admin' ? null : 'rp');

        await this.request.put(`/api/management/users/${userId}`, {
            data: {
                role_ids: [roleId],
                gallery_ids: options?.assignGalleryId ? [options.assignGalleryId] : [],
                gallery_group_ids: [],
                can_edit_metadata: false,
                brand,
            },
            headers
        });

        // Password reset must include a Referer matching the user's brand —
        // AuthController::resetPassword checks brand mismatch (U-01).
        const refererBrand = options?.brand === 'srp' ? 'http://buy.localhost:4321/' : 'http://localhost:4321/';
        const mailpit = new MailpitHelper(this.request);
        const token = await mailpit.extractPasswordResetToken(email);
        const resetRes = await this.request.post('/api/auth/reset-password', {
            data: { email, token, password },
            headers: { ...headers, 'Referer': refererBrand },
        });
        if (!resetRes.ok()) throw new Error(`Password reset failed for ${email}. Token: ${token}. Response: ${await resetRes.text()}`);
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
    trackCustomer(id: string) { if (id) this.createdCustomerIds.push(id); }
    trackSnippet(id: string) { if (id) this.createdSnippetIds.push(id); }
    trackProduct(id: string) { if (id) this.createdProductIds.push(id); }
    trackContract(id: string) { if (id) this.createdContractIds.push(id); }
    trackCoupon(id: string) { if (id) this.createdCouponIds.push(id); }

    async seedBillingSettings() {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        await this.request.put('/api/management/settings/billing-details', {
            data: {
                bank_holder: 'Reisinger Pictures GmbH',
                bank_iban: 'AT123456789012345678',
                bank_bic: 'TESTBICXXX',
                company_street: 'Teststr. 1',
                company_zip: '1010',
                company_city: 'Wien',
                company_country: 'Österreich',
            },
            headers
        });
    }

    private async deleteResources(ids: string[], endpoint: string, label: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        for (const id of ids) {
            await this.request.delete(`${endpoint}/${id}`, { headers })
                .catch((err) => console.warn(`Cleanup: Failed to delete ${label} ${id}`, err));
        }
    }

    async teardown() {
        await this.ensureAdminLogin();

        // NOTE: Contract cleanup via API would need a DELETE endpoint
        // on /api/management/contracts/{id}. Currently only tracking is supported.
        await this.deleteResources(this.createdContractIds, '/api/management/contracts', 'contract');
        await this.deleteResources(this.createdGalleryIds, '/api/management/galleries', 'gallery');
        await this.deleteResources(this.createdGroupIds, '/api/management/gallery-groups', 'gallery-group');
        await this.deleteResources(this.createdUserIds, '/api/test/cleanup-user', 'user');
        await this.deleteResources(this.createdTenantIds, '/api/management/tenants', 'tenant');
        await this.deleteResources(this.createdCustomerIds, '/api/management/customers', 'customer');
        await this.deleteResources(this.createdSnippetIds, '/api/management/text-snippets', 'text-snippet');
        await this.deleteResources(this.createdProductIds, '/api/management/products', 'product');
        await this.deleteResources(this.createdCouponIds, '/api/management/coupons', 'coupon');
    }
}