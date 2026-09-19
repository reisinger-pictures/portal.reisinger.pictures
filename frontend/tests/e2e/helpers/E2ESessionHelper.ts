import { readFileSync } from 'node:fs';
import path from 'node:path';
import { APIRequestContext } from '@playwright/test';
import { MailpitHelper } from './MailpitHelper';

export class E2ESessionHelper {
    private createdUserIds: string[] = [];
    private createdGalleryIds: string[] = [];
    private createdGroupIds: string[] = [];
    private createdOrgIds: string[] = [];
    private createdCustomerIds: string[] = [];
    private createdSnippetIds: string[] = [];
    private createdProductIds: string[] = [];
    private createdContractIds: string[] = [];
    private createdCouponIds: string[] = [];
    private createdPresetIds: string[] = [];
    private createdModelInviteIds: string[] = [];
    private createdModelCustomerIds: string[] = [];
    private adminToken: string | null = null;

    constructor(private request: APIRequestContext) {}

    private async ensureAdminLogin() {
        if (this.adminToken) return;
        const loginRes = await this.request.post('/api/auth/login', {
            data: { email: process.env.ADMIN_EMAIL || 'admin@example.com', password: process.env.ADMIN_PASSWORD || 'admin' },
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

    async loginAs(email: string, password: string, _options?: { brand?: string }): Promise<string> {
        const loginRes = await this.request.post('/api/auth/login', {
            data: { email, password },
            headers: { 'Accept': 'application/json' },
        });
        if (!loginRes.ok()) throw new Error(`Login failed for ${email}: ${await loginRes.text()}`);
        const cookies = loginRes.headers()['set-cookie'];
        const match = cookies?.match(/rp_jwt=([^;]+)/);
        return match ? `rp_jwt=${match[1]}` : (cookies || '');
    }

    async createIsolatedUser(roleName: 'admin' | 'photographer' | 'client' | 'power_user' | 'customer_manager' | 'super_admin', options?: { assignGalleryId?: string, wantsNotifications?: boolean, brand?: string }) {
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

        const refererBrand = 'http://localhost:4321/';
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
    trackOrg(id: string) { if (id) this.createdOrgIds.push(id); }
    trackCustomer(id: string) { if (id) this.createdCustomerIds.push(id); }
    trackSnippet(id: string) { if (id) this.createdSnippetIds.push(id); }
    trackProduct(id: string) { if (id) this.createdProductIds.push(id); }
    trackContract(id: string) { if (id) this.createdContractIds.push(id); }
    trackCoupon(id: string) { if (id) this.createdCouponIds.push(id); }
    trackPreset(id: string) { if (id) this.createdPresetIds.push(id); }
    trackModelInvite(id: string) { if (id) this.createdModelInviteIds.push(id); }
    trackModelCustomer(id: string) { if (id) this.createdModelCustomerIds.push(id); }

    /**
     * Create a model-registration invite via the admin endpoint (magic-link
     * primary). Accepts either a plain e-mail (legacy callers) or a payload.
     */
    async createModelInvite(payload: string | { email?: string; label?: string }) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const input = typeof payload === 'string' ? { email: payload } : payload;
        const data: { email?: string; label?: string } = {};
        if (input.email) data.email = input.email;
        if (input.label) data.label = input.label;
        const res = await this.request.post('/api/management/model-invites', { data, headers });
        if (!res.ok()) throw new Error(`Model invite creation failed: ${await res.text()}`);
        const body = await res.json();
        if (body?.invite?.id) this.trackModelInvite(body.invite.id);
        return body as { success: boolean; link: string; invite: { id: string; email: string | null; link: string } };
    }

    /**
     * Register a single model through the public API (current catalogue:
     * willingness per category + stock, all mandatory consents, age proof
     * upload). Tracks the created customer for teardown.
     *
     * `photoCount` optionally uploads person photos: the first is public and
     * elected as primary, the rest are internal (matches the frontend defaults
     * plus one elected main image for owner photo-management tests).
     */
    async createRegisteredModel(options?: { photoCount?: number }): Promise<{ firstName: string; email: string; customerId: string | null }> {
        await this.ensureAdminLogin();
        const unique = Math.random().toString(36).substring(2, 10);
        const firstName = `E2EDel${unique}`;
        const email = `e2e-model-${unique}@example.com`;

        const invite = await this.createModelInvite({ email, label: `E2E ${unique}` });
        const token = invite.link.split('/').pop() as string;
        const ageProof = readFileSync(path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'));

        const willingnessKeys = [
            'willingness_portrait',
            'willingness_fashion',
            'willingness_business',
            'willingness_boudoir',
            'willingness_bikini',
            'willingness_akt',
            'willingness_sport',
            'willingness_couple_family',
        ];
        const fields: Record<string, string> = {
            'persons[0][answers][first_name]': firstName,
            'persons[0][answers][last_name]': 'Modell',
            'persons[0][answers][birthdate]': '1995-05-05',
            'persons[0][answers][gender]': 'weiblich',
            'persons[0][answers][email]': email,
            'persons[0][answers][phone]': '+43 660 1234567',
            'persons[0][answers][street]': 'Teststraße 1',
            'persons[0][answers][zip]': '4020',
            'persons[0][answers][city]': 'Linz',
            'persons[0][answers][country]': 'Österreich',
            'persons[0][answers][experience_portrait]': '0',
            'persons[0][answers][consent_privacy]': '1',
            'persons[0][answers][consent_accuracy]': '1',
            'persons[0][answers][consent_contact]': '1',
            'persons[0][answers][consent_photos]': '1',
            'persons[0][create_account]': '0',
            'manager_index': '0',
        };
        for (const key of willingnessKeys) {
            fields[`persons[0][answers][${key}]`] = key === 'willingness_portrait' ? 'gerne' : 'nein';
        }
        fields['persons[0][answers][willingness_stock]'] = 'nein';

        const photoFields: Record<string, { name: string; mimeType: string; buffer: Buffer }> = {};
        const photoCount = options?.photoCount ?? 0;
        for (let index = 0; index < photoCount; index += 1) {
            photoFields[`persons[0][photos][${index}][file]`] = {
                name: `sample-${index}.jpg`,
                mimeType: 'image/jpeg',
                buffer: ageProof,
            };
            fields[`persons[0][photos][${index}][visibility]`] = index === 0 ? 'public' : 'internal';
            if (index === 0) fields[`persons[0][photos][${index}][is_primary]`] = '1';
        }

        const res = await this.request.post(`/api/model-registration/${token}`, {
            headers: { 'Accept': 'application/json' },
            multipart: {
                ...fields,
                ...photoFields,
                'persons[0][age_proof]': { name: 'sample.jpg', mimeType: 'image/jpeg', buffer: ageProof },
            },
        });
        if (!res.ok()) throw new Error(`Model registration failed (${res.status()}): ${await res.text()}`);

        // Resolve the created customer for teardown (super-admin token sees all brands).
        let customerId: string | null = null;
        const listRes = await this.request.get('/api/management/models?q=' + encodeURIComponent(firstName), {
            headers: { 'Accept': 'application/json', 'Cookie': this.adminToken! },
        });
        if (listRes.ok()) {
            const list = await listRes.json() as Array<{ customer_id: string }>;
            if (list[0]?.customer_id) {
                customerId = list[0].customer_id;
                this.trackModelCustomer(customerId);
            }
        }

        return { firstName, email, customerId };
    }

    /**
     * Resolve a registered model's customer id by first name and track it for
     * teardown (super-admin token sees all brands).
     */
    private async resolveModelCustomerId(firstName: string): Promise<string | null> {
        const listRes = await this.request.get('/api/management/models?q=' + encodeURIComponent(firstName), {
            headers: { 'Accept': 'application/json', 'Cookie': this.adminToken! },
        });
        if (!listRes.ok()) return null;
        const list = await listRes.json() as Array<{ customer_id: string }>;
        const customerId = list[0]?.customer_id ?? null;
        if (customerId) this.trackModelCustomer(customerId);
        return customerId;
    }

    /**
     * Register a two-person group through the public API (person 0 = manager).
     * Returns both persons with their resolved customer ids so the transfer flow
     * and teardown can address them.
     */
    async createRegisteredGroup(): Promise<{
        manager: { firstName: string; email: string; customerId: string | null };
        member: { firstName: string; email: string; customerId: string | null };
    }> {
        await this.ensureAdminLogin();
        const unique = Math.random().toString(36).substring(2, 10);
        const manager = { first: `E2EGrpA${unique}`, email: `e2e-group-a-${unique}@example.com` };
        const member = { first: `E2EGrpB${unique}`, email: `e2e-group-b-${unique}@example.com` };

        const invite = await this.createModelInvite({ email: manager.email, label: `E2E Group ${unique}` });
        const token = invite.link.split('/').pop() as string;
        const ageProof = readFileSync(path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'));

        const willingnessKeys = [
            'willingness_portrait',
            'willingness_fashion',
            'willingness_business',
            'willingness_boudoir',
            'willingness_bikini',
            'willingness_akt',
            'willingness_sport',
            'willingness_couple_family',
        ];
        const fields: Record<string, string> = { 'manager_index': '0' };
        [manager, member].forEach((person, index) => {
            fields[`persons[${index}][answers][first_name]`] = person.first;
            fields[`persons[${index}][answers][last_name]`] = 'Gruppe';
            fields[`persons[${index}][answers][birthdate]`] = '1995-05-05';
            fields[`persons[${index}][answers][gender]`] = 'weiblich';
            fields[`persons[${index}][answers][email]`] = person.email;
            fields[`persons[${index}][answers][phone]`] = '+43 660 1234567';
            fields[`persons[${index}][answers][street]`] = 'Teststraße 1';
            fields[`persons[${index}][answers][zip]`] = '4020';
            fields[`persons[${index}][answers][city]`] = 'Linz';
            fields[`persons[${index}][answers][country]`] = 'Österreich';
            fields[`persons[${index}][answers][experience_portrait]`] = '0';
            fields[`persons[${index}][answers][consent_privacy]`] = '1';
            fields[`persons[${index}][answers][consent_accuracy]`] = '1';
            fields[`persons[${index}][answers][consent_contact]`] = '1';
            fields[`persons[${index}][answers][consent_photos]`] = '1';
            fields[`persons[${index}][answers][consent_all_persons]`] = '1';
            for (const key of willingnessKeys) {
                fields[`persons[${index}][answers][${key}]`] = key === 'willingness_portrait' ? 'gerne' : 'nein';
            }
            fields[`persons[${index}][answers][willingness_stock]`] = 'nein';
            fields[`persons[${index}][create_account]`] = '0';
        });

        const res = await this.request.post(`/api/model-registration/${token}`, {
            headers: { 'Accept': 'application/json' },
            multipart: {
                ...fields,
                'persons[0][age_proof]': { name: 'sample.jpg', mimeType: 'image/jpeg', buffer: ageProof },
                'persons[1][age_proof]': { name: 'sample.jpg', mimeType: 'image/jpeg', buffer: ageProof },
            },
        });
        if (!res.ok()) throw new Error(`Group registration failed (${res.status()}): ${await res.text()}`);

        return {
            manager: { firstName: manager.first, email: manager.email, customerId: await this.resolveModelCustomerId(manager.first) },
            member: { firstName: member.first, email: member.email, customerId: await this.resolveModelCustomerId(member.first) },
        };
    }

    /** Create a 24h profile access link for a registered model (admin endpoint). */
    async createModelAccessLink(customerId: string): Promise<{ link: string; expires_at: string | null }> {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.post(`/api/management/models/${customerId}/access-link`, { data: {}, headers });
        if (!res.ok()) throw new Error(`Model access link creation failed: ${await res.text()}`);
        return res.json() as Promise<{ link: string; expires_at: string | null }>;
    }

    /** Delete a customer created by a model registration (cascade removes the profile). */
    async deleteModelCustomer(id: string) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        await this.request.delete(`/api/management/customers/${id}`, { headers }).catch(() => undefined);
    }

    async createVolumePreset(data: { name: string; tiers: Array<{ min_quantity: number; price_cents: number }> }) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.post('/api/management/settings/volume-presets', { data, headers });
        if (!res.ok()) throw new Error(`Volume preset creation failed: ${await res.text()}`);
        return res.json();
    }

    async createCoupon(data: {
        code: string;
        type: string;
        value: number;
        scope_type: string;
        scope_id?: string;
        active: boolean;
        used_count?: number;
    }) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.post('/api/management/coupons', { data, headers });
        if (!res.ok()) throw new Error(`Coupon creation failed: ${await res.text()}`);
        return res.json();
    }

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
        await this.deleteResources(this.createdOrgIds, '/api/management/orgs', 'Org');
        await this.deleteResources(this.createdCustomerIds, '/api/management/customers', 'customer');
        await this.deleteResources(this.createdSnippetIds, '/api/management/text-snippets', 'text-snippet');
        await this.deleteResources(this.createdProductIds, '/api/management/products', 'product');
        await this.deleteResources(this.createdCouponIds, '/api/management/coupons', 'coupon');
        await this.deleteResources(this.createdPresetIds, '/api/management/settings/volume-presets', 'volume-preset');
        await this.deleteResources(this.createdModelInviteIds, '/api/management/model-invites', 'model-invite');
        await this.deleteResources(this.createdModelCustomerIds, '/api/management/customers', 'model-customer');
    }
}