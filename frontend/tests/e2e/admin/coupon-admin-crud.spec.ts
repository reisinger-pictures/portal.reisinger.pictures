import { readFileSync } from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
const sampleImage = readFileSync(sampleImagePath);

test.describe('Coupon Admin CRUD', () => {
    let helper: E2ESessionHelper;
    let srpAdmin: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        srpAdmin = await helper.createIsolatedUser('admin', { brand: 'rp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can create a fixed global coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'rp' });

        const couponCode = `FIXED-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://localhost:4321/' };
        const createRes = await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 15, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const createResJson = await createRes.json();
        if (createResJson?.coupon?.id) helper.trackCoupon(createResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(couponCode, { timeout: 10000 });
    });

    test('Admin can create organisation-scoped coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'rp' });

        const couponOrgCode = `ORG-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://localhost:4321/' };
        const orgRes = await request.post('/api/management/coupons', {
            data: { code: couponOrgCode, type: 'fixed', value: 10, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const orgResJson = await orgRes.json();
        if (orgResJson?.coupon?.id) helper.trackCoupon(orgResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');
        await expect(page.locator('h1')).toContainText('Gutscheincode', { timeout: 15000 });

        await expect(page.locator('table')).toContainText(couponOrgCode, { timeout: 10000 });
    });

    test('Admin can delete a used coupon', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        test.setTimeout(120000);

        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'rp' });
        const suffix = Math.random().toString(36).substring(2, 8);
        const jsonHeaders = (cookie: string) => ({
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Cookie': cookie,
            'Referer': 'http://localhost:4321/',
        });

        // A real checkout redemption is the only legitimate way to create a
        // used coupon. `used_count` is server-owned and is intentionally not
        // sent in the management-create payload.
        const photographer = await helper.createIsolatedUser('photographer', { brand: 'rp' });
        const photographerCookie = await helper.loginAs(photographer.email, photographer.password, { brand: 'rp' });
        const galleryResponse = await request.post('/api/management/galleries', {
            data: {
                name: `Coupon Admin Usage ${suffix}`,
                slug: `coupon-admin-usage-${suffix}`,
                type: 'delivery',
                is_public: true,
                is_live: true,
                licensing_mode: 'volume_licensing',
            },
            headers: jsonHeaders(photographerCookie),
        });
        const galleryBody = await galleryResponse.text();
        expect(galleryResponse.ok(), galleryBody).toBeTruthy();
        const galleryId = (JSON.parse(galleryBody) as { gallery?: { id?: string } }).gallery?.id;
        if (!galleryId) throw new Error('Coupon usage gallery was created without an id');
        helper.trackGallery(galleryId);

        const uploadResponse = await request.post('/api/management/upload', {
            headers: {
                'Accept': 'application/json',
                'Cookie': photographerCookie,
                'Referer': 'http://localhost:4321/',
            },
            multipart: {
                gallery_id: galleryId,
                lr_uuid: `coupon-admin-${galleryId}`,
                file: {
                    name: 'sample.jpg',
                    mimeType: 'image/jpeg',
                    buffer: sampleImage,
                },
            },
        });
        const uploadBody = await uploadResponse.text();
        expect(uploadResponse.ok(), uploadBody).toBeTruthy();
        const photoId = (JSON.parse(uploadBody) as { photo_id?: string }).photo_id;
        if (!photoId) throw new Error('Coupon usage gallery was created without a photo');

        const buyer = await helper.createIsolatedUser('power_user', { brand: 'rp' });
        const buyerCookie = await helper.loginAs(buyer.email, buyer.password, { brand: 'rp' });
        const couponCode = `USED-${suffix}`;
        const createResponse = await request.post('/api/management/coupons', {
            data: { code: couponCode, type: 'fixed', value: 5, scope_type: 'global', active: true },
            headers: jsonHeaders(srpCookie),
        });
        const createBody = await createResponse.text();
        expect(createResponse.ok(), createBody).toBeTruthy();
        const couponId = (JSON.parse(createBody) as { coupon?: { id?: string | number } }).coupon?.id;
        if (couponId === undefined || couponId === null) throw new Error('Coupon was created without an id');
        helper.trackCoupon(String(couponId));

        const checkoutResponse = await request.post('/api/orders/checkout', {
            data: {
                items: [{ photoId, tier: 'web' }],
                billing_name: 'Coupon Admin Regression',
                billing_street: 'Teststraße 1',
                billing_zip: '1010',
                billing_city: 'Wien',
                payment_method: 'invoice',
                coupon_code: couponCode,
                withdrawal_waived: true,
            },
            headers: {
                ...jsonHeaders(buyerCookie),
                'Idempotency-Key': `coupon-admin-used-${suffix}`,
            },
        });
        const checkoutBody = await checkoutResponse.text();
        expect(checkoutResponse.ok(), checkoutBody).toBeTruthy();
        expect((JSON.parse(checkoutBody) as { success?: boolean }).success).toBe(true);

        // Verify the server list contract before opening the SPA. This catches
        // a failed redemption or a missing usage counter independently of the
        // table rendering below.
        const listResponse = await request.get('/api/management/coupons?page=1&per_page=100', {
            headers: jsonHeaders(srpCookie),
        });
        const listBody = await listResponse.text();
        expect(listResponse.ok(), listBody).toBeTruthy();
        const usedCoupon = (JSON.parse(listBody) as { data?: Array<{ code?: string; used_count?: number }> })
            .data?.find(coupon => coupon.code === couponCode);
        expect(usedCoupon).toBeDefined();
        expect(usedCoupon?.used_count).toBe(1);

        const sidebar = new SidebarHelper(page);
        const swrListResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/management/coupons' && response.request().method() === 'GET' && response.ok();
        }, { timeout: 15000 });
        await sidebar.navigateTo('Gutscheincode');
        const swrListResponse = await swrListResponsePromise;
        const swrListBody = await swrListResponse.text();
        expect(swrListResponse.ok(), swrListBody).toBeTruthy();
        expect((JSON.parse(swrListBody) as { data?: Array<{ code?: string }> }).data?.some(coupon => coupon.code === couponCode)).toBe(true);
        await expect(page.getByRole('main').getByRole('heading', { name: 'Gutscheincode' })).toBeVisible({ timeout: 15000 });

        const row = page.getByRole('main').locator('tr').filter({ hasText: couponCode });
        await expect(row).toBeVisible({ timeout: 10000 });

        const deleteBtn = row.locator('button[title="Löschen"]');
        await expect(deleteBtn).toBeVisible();
        await expect(deleteBtn).toBeEnabled();
        await deleteBtn.click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        const deleteResponsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === `/api/management/coupons/${couponId}` && response.request().method() === 'DELETE' && response.ok();
        }, { timeout: 15000 });
        await confirmModal.getByRole('button', { name: 'Bestätigen' }).click();
        const deleteResponse = await deleteResponsePromise;
        const deleteBody = await deleteResponse.text();
        expect(deleteResponse.ok(), deleteBody).toBeTruthy();
        await expect(confirmModal).toBeHidden();

        await expect(page.locator('.toast')).toContainText('Gutscheincode gelöscht');
        await expect(row).toBeHidden({ timeout: 10000 });
    });

    test('Admin can toggle coupon active/inactive', { tag: ['@feature:admin:coupon'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(srpAdmin.email, srpAdmin.password, 'http://localhost:4321/');
        const srpCookie = await helper.loginAs(srpAdmin.email, srpAdmin.password, { brand: 'rp' });

        const toggleCouponCode = `TOGGLE-${Math.random().toString(36).substring(2, 8)}`;
        const couHeaders = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cookie': srpCookie, 'Referer': 'http://localhost:4321/' };
        const toggleRes = await request.post('/api/management/coupons', {
            data: { code: toggleCouponCode, type: 'percentage', value: 10, scope_type: 'global', active: true },
            headers: couHeaders,
        });
        const toggleResJson = await toggleRes.json();
        if (toggleResJson?.coupon?.id) helper.trackCoupon(toggleResJson.coupon.id);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Gutscheincode');

        const row = page.locator('tr').filter({ hasText: toggleCouponCode });
        await expect(row).toBeVisible({ timeout: 15000 });
        await expect(row.locator('span.badge-success')).toContainText('Aktiv', { timeout: 10000 });

        const toggleBtn = row.locator('button[title="Deaktivieren"]');
        await toggleBtn.click();
        await expect(page.locator('.toast')).toContainText('Gutscheincode deaktiviert');
        await expect(row.locator('span.badge-ghost')).toContainText('Inaktiv');
    });
});
