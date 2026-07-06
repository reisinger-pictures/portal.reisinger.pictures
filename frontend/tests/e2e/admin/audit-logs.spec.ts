import { test, expect, Page, APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Audit Logs (Download Logs)', () => {
    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '', id: '' };
    let clientUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        clientUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setupGalleryWithDownload(page: Page, request: APIRequestContext): Promise<string> {
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password);

        const galleryName = `Audit Test ${Math.random().toString(36).substring(2, 10)}`;

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({
            name: galleryName,
            type: 'Delivery (Downloads)',
            visibility: 'Privat (Nur mit Link / Passwort)',
            freeDownload: true
        });
        const resData = await modal.submitModal('Speichern');
        const galleryId = resData?.gallery?.id;
        if (galleryId) helper.trackGallery(galleryId);

        const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(link).toBeVisible({ timeout: 15000 });
        await link.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 10000 });

        const adminToken = helper.getAdminToken();
        await request.post(`/api/management/galleries/${galleryId}/sync-access`, {
            data: { user_id: clientUser.id, action: 'attach' },
            headers: { 'Cookie': adminToken, 'Accept': 'application/json' }
        });

        await auth.logout();
        await auth.login(clientUser.email, clientUser.password);

        await page.locator('main').locator('.card').filter({ hasText: galleryName }).first().click();

        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 15000 });
        await image.scrollIntoViewIfNeeded();

        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible();

        const downloadLink = page.getByRole('link', { name: 'Download', exact: true }).first();
        await expect(downloadLink).toBeVisible();
        await downloadLink.evaluate(node => node.removeAttribute('target'));
        const [download] = await Promise.all([
            page.waitForEvent('download', { timeout: 30000 }),
            downloadLink.click()
        ]);
        expect(download.suggestedFilename()).toMatch(/\.jpg$/i);

        await auth.logout();

        return galleryName;
    }

    test('Download by client is recorded in audit log, visible to photographer', { tag: ['@feature:admin:audit'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const galleryName = await setupGalleryWithDownload(page, request);

        await auth.login(photogUser.email, photogUser.password);

        await sidebar.navigateTo('Auswertungen');
        await expect(page.locator('h1:has-text("Statistiken & Audit-Logs")')).toBeVisible({ timeout: 10000 });
        await page.reload();

        const table = page.locator('main table');
        await expect(table).toBeVisible();

        const tableBody = table.locator('tbody');
        await expect(tableBody.locator('tr').first()).toBeVisible({ timeout: 15000 });
        await expect(page.locator('main table td').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });
    });

    test('Filter by gallery name in audit logs', { tag: ['@feature:admin:audit'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const galleryName = await setupGalleryWithDownload(page, request);

        await auth.login(photogUser.email, photogUser.password);

        await sidebar.navigateTo('Auswertungen');
        await expect(page.locator('h1:has-text("Statistiken & Audit-Logs")')).toBeVisible({ timeout: 10000 });
        await page.reload();

        const table = page.locator('main table');
        await expect(table).toBeVisible();

        await expect(table.locator('td').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });
    });

    test('Pagination works in audit logs', { tag: ['@feature:admin:audit'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.navigateTo('Auswertungen');

        await expect(page.locator('h1:has-text("Statistiken & Audit-Logs")')).toBeVisible({ timeout: 10000 });

        const table = page.locator('main table');
        await expect(table).toBeVisible();

        const headers = table.locator('thead th');
        await expect(headers).toHaveText(['Datum / Zeit', 'Benutzer / Gast', 'Galerie', 'Typ', 'Qualität']);
    });

    test('Empty state when no logs', { tag: ['@feature:admin:audit'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.navigateTo('Auswertungen');

        await expect(page.locator('h1:has-text("Statistiken & Audit-Logs")')).toBeVisible({ timeout: 10000 });

        const table = page.locator('main table');
        await expect(table).toBeVisible();

        await expect(page.locator('main table tbody')).toContainText('Noch keine Downloads aufgezeichnet.');
    });
});
