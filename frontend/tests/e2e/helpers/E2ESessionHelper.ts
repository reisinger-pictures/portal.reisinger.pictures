import { readFileSync } from 'node:fs';
import path from 'node:path';
import { APIRequestContext, type APIResponse } from '@playwright/test';
import { E2ECookieJar, extractCookieHeader } from './E2ECookieJar';
import { MailpitHelper } from './MailpitHelper';

const e2eBrandSettings = {
    accounting_email: 'brand-settings-e2e@example.com',
    primary_color: '#123456',
    secondary_color: '#654321',
} as const;

/**
 * The five pricing factors the shooting-calculator modal consumes, in the exact
 * spelling of `/api/settings/license-terms`.
 *
 * Two of them are money, three are not — and that split is the reason this
 * helper cannot be unit-blind.
 */
const SHOOTING_CALCULATOR_SETTING_KEYS = [
    'calc_base_price',
    'calc_hourly_rate',
    'calc_images_per_hour',
    'calc_outdoor_images_per_hour',
    'calc_flatrate_multiplier',
] as const;

/**
 * The subset that is money, and therefore in **cents** (owner decision
 * 2026-09-28: every monetary amount is cents, whole-euro amounts included).
 *
 * `calc_images_per_hour` / `calc_outdoor_images_per_hour` are counts and
 * `calc_flatrate_multiplier` a dimensionless factor; none of them is scaled.
 */
const SHOOTING_CALCULATOR_MONEY_KEYS = ['calc_base_price', 'calc_hourly_rate'] as const;

/**
 * Smallest cent amount the licence-terms endpoint accepts for the two money
 * fields — `integer|min:500`, the rule `base_price` has always carried. Used
 * only to catch a unit error, never to accept or reject a legitimate value.
 */
const MIN_CENTS_PER_MONEY_FIELD = 500;

type ShootingCalculatorSettingKey = typeof SHOOTING_CALCULATOR_SETTING_KEYS[number];
type ShootingCalculatorMoneyKey = typeof SHOOTING_CALCULATOR_MONEY_KEYS[number];
type ShootingCalculatorNonMoneyKey = Exclude<ShootingCalculatorSettingKey, ShootingCalculatorMoneyKey>;

/**
 * The five calculator factors in the API's own typing: the two money fields as
 * cent **numbers**, the two counts and the factor as the stored **text**.
 *
 * Typing the record this way is what makes the unit check hold on the restore
 * leg as well. A record of strings (`'5000'`) would have to be stringified to
 * be written and re-parsed to be checked, and a stringified amount is exactly
 * what a `× 100` bug looks like after it has been through `String()`.
 */
export type ShootingCalculatorSettings =
    & Record<ShootingCalculatorMoneyKey, number>
    & Record<ShootingCalculatorNonMoneyKey, string>;

/**
 * `PUT /management/settings/license-terms` validates these three as `required`,
 * so every save of the calculator factors has to carry them along — that is
 * exactly what `CalculatorSettingsCard.onSubmit` does.
 */
const LICENSE_MULTIPLIER_KEYS = ['mult_commercial', 'mult_unlimited', 'mult_international'] as const;

/**
 * Reject a money field that is not an integer cent amount.
 *
 * Without this the helper is unit-blind: it would keep accepting `'50'` for
 * `calc_base_price`, and every calculator E2E test would still pass after the
 * unit changed — the fixtures would go on writing euros into a cents field and
 * the assertions would go on reading euros back out of it, with the two errors
 * cancelling. That is the exact shape of the defect the 2026-09-28 cents
 * decision removed from production code: a value that is a valid *euro* amount
 * landing in a column that means cents, silently.
 *
 * The check is deliberately about the *unit*, not about a particular number: any
 * integer at or above the endpoint's own minimum is a plausible cent amount,
 * while `'50'` and `50.5` can only be euros or sub-cent fractions.
 */
const isShootingCalculatorMoneyKey = (key: string): key is ShootingCalculatorMoneyKey =>
    (SHOOTING_CALCULATOR_MONEY_KEYS as readonly string[]).includes(key);

const assertIntegerCents = (key: string, value: unknown): void => {
    if (typeof value !== 'number' || !Number.isInteger(value)) {
        throw new Error(
            `Calculator setting "${key}" is money and must be integer cents from ` +
            `/api/settings/license-terms, got ${JSON.stringify(value)} (${typeof value}). ` +
            `Euros are not accepted here — a spec that means 50 € has to write 5000.`,
        );
    }
    if (value < MIN_CENTS_PER_MONEY_FIELD) {
        throw new Error(
            `Calculator setting "${key}" is ${value} cents, below the ${MIN_CENTS_PER_MONEY_FIELD}-cent ` +
            `minimum the licence-terms endpoint enforces. That is a euro amount written into a cents field.`,
        );
    }
};

const pickShootingCalculatorSettings = (payload: Record<string, unknown>): ShootingCalculatorSettings => {
    const settings: Record<string, number | string> = {};
    for (const key of SHOOTING_CALCULATOR_SETTING_KEYS) {
        const value = payload[key];
        if (typeof value !== 'string' && typeof value !== 'number') {
            throw new Error(
                `Calculator setting "${key}" is missing from /api/settings/license-terms: ${JSON.stringify(payload)}`,
            );
        }
        settings[key] = value;
    }

    for (const key of SHOOTING_CALCULATOR_MONEY_KEYS) {
        assertIntegerCents(key, settings[key]);
    }

    // Every key of the record was validated above: the money keys are integers
    // and the others are the text the endpoint serves, so the cast states the
    // check rather than papering over it.
    return settings as ShootingCalculatorSettings;
};

type VolumePresetResponse = {
    id: string | number;
    name: string;
    is_default?: boolean;
    tiers: Array<{
        min_quantity: number;
        price_cents: number;
    }>;
};

type CouponDefinition = {
    code: string;
    type: string;
    value?: number;
    scope_type: string;
    scope_id?: string;
    active: boolean;
    used_count?: number;
    max_items?: number;
    package_quantity?: number;
    package_price_cents?: number;
    expires_at?: string;
};

type CouponResponse = {
    success?: boolean;
    coupon?: {
        id?: string | number;
        code?: string;
    };
};

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
    private adminCookies = new E2ECookieJar();

    constructor(private request: APIRequestContext) {}

    private rememberAdminCookies(response: APIResponse) {
        this.adminCookies.update(response);
        this.adminToken = this.adminCookies.toCookieHeader();
    }

    private async ensureAdminLogin() {
        if (this.adminToken) return;
        const loginRes = await this.request.post('/api/auth/login', {
            data: { email: process.env.ADMIN_EMAIL || 'admin@example.com', password: process.env.ADMIN_PASSWORD || 'admin' },
            headers: { 'Accept': 'application/json' }
        });
        if (!loginRes.ok()) throw new Error('Admin login failed: ' + await loginRes.text());

        this.adminCookies = new E2ECookieJar();
        this.rememberAdminCookies(loginRes);
        if (!this.adminToken) throw new Error('Admin login response did not contain an auth cookie');
    }

    getAdminToken() {
        return this.adminToken || '';
    }

    private async putBrandSettings(payload: Record<string, string | null>) {
        await this.ensureAdminLogin();
        const response = await this.request.put('/api/management/brand-settings/rp', {
            data: payload,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cookie': this.adminToken!,
            },
        });
        this.rememberAdminCookies(response);
        if (!response.ok()) {
            throw new Error(`Brand settings update failed: ${response.status()} ${await response.text()}`);
        }
    }

    async seedBrandSettings() {
        await this.putBrandSettings(e2eBrandSettings);
    }

    async resetBrandSettings() {
        await this.putBrandSettings({
            accounting_email: null,
            primary_color: null,
            secondary_color: null,
        });
    }

    /**
     * Raw `/api/settings/license-terms` payload, exactly as the settings form
     * receives it.
     */
    private async getLicenseTermsPayload(): Promise<Record<string, unknown>> {
        await this.ensureAdminLogin();
        const response = await this.request.get('/api/settings/license-terms', {
            headers: { 'Accept': 'application/json', 'Cookie': this.adminToken! },
        });
        this.rememberAdminCookies(response);
        if (!response.ok()) {
            throw new Error(`Reading the license terms failed: ${response.status()} ${await response.text()}`);
        }
        return await response.json() as Record<string, unknown>;
    }

    /**
     * Read the shooting-calculator pricing factors as the calculator itself
     * sees them — the same public endpoint `useLicenseTerms()` (and therefore
     * `ShootingCalculatorModal`) fetches.
     *
     * These settings are a per-*brand* singleton, and the portal has exactly one
     * brand, so this returns the values every worker in the run shares. Callers
     * that depend on specific numbers must establish them with
     * `setShootingCalculatorSettings()` while holding
     * `SHOOTING_CALCULATOR_SETTINGS_LOCK`; see `GlobalSettingsLock.ts`.
     */
    async getShootingCalculatorSettings(): Promise<ShootingCalculatorSettings> {
        return pickShootingCalculatorSettings(await this.getLicenseTermsPayload());
    }

    /**
     * Write a partial set of shooting-calculator pricing factors and return the
     * resulting effective values, so a caller can assert what the calculator
     * will actually use instead of assuming the write landed.
     *
     * This is the endpoint the settings form submits
     * (`useLicenseTerms().updateTerms`), used here as test *setup* by specs
     * whose subject is the calculator's arithmetic rather than the settings form.
     * The form also has to send the three licence multipliers on every save —
     * the controller marks them `required` — so this mirrors that contract and
     * echoes the currently effective values instead of inventing defaults.
     *
     * Writing is only safe while holding `SHOOTING_CALCULATOR_SETTINGS_LOCK`:
     * the row is shared by every worker of the run.
     *
     * The two money keys are in **cents** (owner decision 2026-09-28), and both
     * this method and {@link E2ESessionHelper.getShootingCalculatorSettings}
     * enforce it, so a spec cannot write euros and then read euros back and call
     * the round trip a success.
     */
    async setShootingCalculatorSettings(
        settings: Partial<Record<ShootingCalculatorSettingKey, string | number>>,
    ): Promise<ShootingCalculatorSettings> {
        await this.ensureAdminLogin();

        const payload: Record<string, string> = {};
        for (const key of SHOOTING_CALCULATOR_SETTING_KEYS) {
            const value = settings[key];
            if (value === undefined) continue;
            // Held to the same unit contract on the way out as on the way in:
            // a spec cannot introduce the euro/cent mismatch it would then be
            // unable to detect when reading the row back.
            if (isShootingCalculatorMoneyKey(key)) {
                assertIntegerCents(key, value);
            }
            payload[key] = String(value);
        }
        if (Object.keys(payload).length === 0) {
            throw new Error('setShootingCalculatorSettings() needs at least one setting to write');
        }

        const current = await this.getLicenseTermsPayload();
        for (const key of LICENSE_MULTIPLIER_KEYS) {
            const value = current[key];
            payload[key] = typeof value === 'string' || typeof value === 'number' ? String(value) : '1.5';
        }

        const response = await this.request.put('/api/management/settings/license-terms', {
            data: payload,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cookie': this.adminToken!,
            },
        });
        this.rememberAdminCookies(response);
        if (!response.ok()) {
            throw new Error(`Writing the calculator settings failed: ${response.status()} ${await response.text()}`);
        }
        const body = await response.json() as { success?: boolean };
        if (body?.success !== true) {
            throw new Error(`Writing the calculator settings was not acknowledged: ${JSON.stringify(body)}`);
        }

        return this.getShootingCalculatorSettings();
    }

    async loginAs(email: string, password: string, _options?: { brand?: string }): Promise<string> {
        const loginRes = await this.request.post('/api/auth/login', {
            data: { email, password },
            headers: { 'Accept': 'application/json' },
        });
        if (!loginRes.ok()) throw new Error(`Login failed for ${email}: ${await loginRes.text()}`);
        const cookieHeader = extractCookieHeader(loginRes);
        if (!cookieHeader) throw new Error(`Login response for ${email} did not contain an auth cookie`);
        return cookieHeader;
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
        this.rememberAdminCookies(createRes);
        if (!createRes.ok()) throw new Error(`Failed to create user ${email}. Status: ${createRes.status()} Body: ${await createRes.text()}`);
        const createData = await createRes.json();
        const userId = createData.user?.id;
        if (!userId) throw new Error('User ID missing in response: ' + JSON.stringify(createData));
        this.createdUserIds.push(userId);

        const rolesRes = await this.request.get('/api/management/roles', { headers });
        this.rememberAdminCookies(rolesRes);
        if (!rolesRes.ok()) {
            throw new Error(`Role lookup failed. Status: ${rolesRes.status()} Body: ${await rolesRes.text()}`);
        }
        const roles = await rolesRes.json() as Array<{ name: string; id: string }>;
        const role = roles.find((candidate) => candidate.name === roleName);
        if (!role) throw new Error(`Role ${roleName} was not returned by /api/management/roles`);
        const roleId = role.id;

        // U-02: non-super-admin users must have a brand assigned. Super-admin is cross-brand.
        const brand = options?.brand ?? (roleName === 'super_admin' ? null : 'rp');

        const updateUserRes = await this.request.put(`/api/management/users/${userId}`, {
            data: {
                role_ids: [roleId],
                gallery_ids: options?.assignGalleryId ? [options.assignGalleryId] : [],
                gallery_group_ids: [],
                can_edit_metadata: false,
                brand,
            },
            headers
        });
        this.rememberAdminCookies(updateUserRes);
        if (!updateUserRes.ok()) {
            throw new Error(`Failed to configure user ${email}. Status: ${updateUserRes.status()} Body: ${await updateUserRes.text()}`);
        }

        const refererBrand = 'http://localhost:4321/';
        const mailpit = new MailpitHelper(this.request);
        const token = await mailpit.extractPasswordResetToken(email);
        const resetRes = await this.request.post('/api/auth/reset-password', {
            data: { email, token, password },
            headers: { ...headers, 'Referer': refererBrand },
        });
        if (!resetRes.ok()) throw new Error(`Password reset failed for ${email}. Token: ${token}. Response: ${await resetRes.text()}`);
        const userCookies = extractCookieHeader(resetRes);
        if (!userCookies) throw new Error(`Password reset response for ${email} did not contain an auth cookie`);

        if (options?.assignGalleryId && options?.wantsNotifications) {
            await this.request.post(`/api/galleries/${options.assignGalleryId}/opt-in`, {
                data: { wants_notifications: true },
                headers: { 'Accept': 'application/json', 'Cookie': userCookies }
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
        this.rememberAdminCookies(res);
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
        this.rememberAdminCookies(listRes);
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
        this.rememberAdminCookies(listRes);
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
        this.rememberAdminCookies(res);
        if (!res.ok()) throw new Error(`Model access link creation failed: ${await res.text()}`);
        return res.json() as Promise<{ link: string; expires_at: string | null }>;
    }

    /** Delete a customer created by a model registration (cascade removes the profile). */
    async deleteModelCustomer(id: string) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        const response = await this.request.delete(`/api/management/customers/${id}`, { headers }).catch(() => null);
        if (response) this.rememberAdminCookies(response);
    }

    /**
     * Grant a user access to whole gallery groups.
     *
     * The gallery tree only shows a photographer the groups that are either
     * unrestricted or assigned to them, and a group survives pruning only if it
     * contains galleries inside getAllowedGalleryIds(). Group membership feeds
     * that list recursively, so assigning the meta-gallery groups is what makes
     * admin-created fixtures visible to a photographer in the tree.
     *
     * The fixtures must be created by the admin first (volume presets are
     * admin-only), so this cannot be folded into createIsolatedUser.
     */
    async assignUserToGalleryGroups(userId: string, groupIds: string[]): Promise<void> {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };

        // No read-back: there is no GET for a single management user, and a
        // freshly created isolated user has no group assignments yet, so
        // replacing the set is equivalent to extending it.
        const updateRes = await this.request.put(`/api/management/users/${userId}`, {
            data: { gallery_group_ids: [...new Set(groupIds)] },
            headers,
        });
        this.rememberAdminCookies(updateRes);
        if (!updateRes.ok()) {
            throw new Error(`Failed to assign groups to user ${userId}. Status: ${updateRes.status()} Body: ${await updateRes.text()}`);
        }
    }

    async createVolumePreset(data: { name: string; tiers: Array<{ min_quantity: number; price_cents: number }> }): Promise<VolumePresetResponse> {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.post('/api/management/settings/volume-presets', { data, headers });
        this.rememberAdminCookies(res);
        if (!res.ok()) throw new Error(`Volume preset creation failed: ${await res.text()}`);
        return res.json() as Promise<VolumePresetResponse>;
    }

    async createCoupon(data: CouponDefinition): Promise<CouponResponse> {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.post('/api/management/coupons', { data, headers });
        this.rememberAdminCookies(res);
        if (!res.ok()) throw new Error(`Coupon creation failed: ${await res.text()}`);
        return res.json() as Promise<CouponResponse>;
    }

    async updateCoupon(id: string, data: Partial<CouponDefinition>): Promise<CouponResponse> {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.put(`/api/management/coupons/${id}`, { data, headers });
        this.rememberAdminCookies(res);
        if (!res.ok()) throw new Error(`Coupon update failed: ${await res.text()}`);
        return res.json() as Promise<CouponResponse>;
    }

    async updateGalleryLicensing(
        galleryId: string,
        licensingMode: 'scope_licensing' | 'volume_licensing',
        volumePresetId: string | number | null = null,
    ) {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': this.adminToken! };
        const res = await this.request.put(`/api/management/galleries/${galleryId}`, {
            data: { licensing_mode: licensingMode, volume_preset_id: volumePresetId },
            headers,
        });
        this.rememberAdminCookies(res);
        if (!res.ok()) throw new Error(`Gallery licensing update failed: ${await res.text()}`);
    }

    async seedBillingSettings() {
        await this.ensureAdminLogin();
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        const response = await this.request.put('/api/management/settings/billing-details', {
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
        this.rememberAdminCookies(response);
    }

    private async deleteResources(ids: string[], endpoint: string, label: string) {
        const headers = { 'Accept': 'application/json', 'Cookie': this.adminToken! };
        for (const id of ids) {
            const response = await this.request.delete(`${endpoint}/${id}`, { headers })
                .catch((err) => {
                    console.warn(`Cleanup: Failed to delete ${label} ${id}`, err);
                    return null;
                });
            if (response) this.rememberAdminCookies(response);
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
