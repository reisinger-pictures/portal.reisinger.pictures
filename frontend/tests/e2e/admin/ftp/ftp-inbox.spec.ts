import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ModalHelper } from '../../helpers/ModalHelper';
import { FormHelper } from '../../helpers/FormHelper';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

test.describe('G1: FTP Inbox Feature', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('FTP Inbox is visible for photographers on Dashboard', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.locator('main');
        await expect(main.locator('h2', { hasText: 'FTP Inbox' })).toBeVisible();
        await expect(main.locator('code.font-bold.font-mono')).toBeVisible();
    });

    test('FTP Inbox shows quarantined files and their SFTP folder', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        let quarantinePath = '';

        await auth.login(testUser.email, testUser.password);

        await page.route('**/api/management/ftp/status', async route => {
            const response = await route.fetch();
            const responseBody: unknown = await response.json();
            if (!isRecord(responseBody)) {
                throw new Error('FTP status response is not an object.');
            }

            const status = responseBody;
            if (typeof status.ftp_folder !== 'string') {
                throw new Error('FTP status response does not contain an upload folder.');
            }
            if (!isRecord(status.connection)) {
                throw new Error('FTP status response does not contain connection details.');
            }
            quarantinePath = `${status.ftp_folder}/incoming/_quarantine`;
            await route.fulfill({
                response,
                body: JSON.stringify({
                    ...status,
                    connection: { ...status.connection, path: '/incoming' },
                    quarantine_count: 2,
                }),
            });
        });

        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const alert = main.getByRole('alert');
        await expect(alert).toBeVisible();
        await expect(alert).toContainText('2 Dateien liegen im Quarantäne-Ordner');
        await expect(alert.getByText(quarantinePath, { exact: true })).toBeVisible();
        await expect(alert).toContainText('Sie bleiben erhalten und werden nicht gelöscht.');
    });

    test('FTP Inbox is NOT visible for non-photographer roles', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('client');
        const auth = new AuthHelper(page);

        await auth.login(testUser.email, testUser.password);
        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });

        await expect(page.locator('h2:has-text("FTP Inbox")')).toHaveCount(0);
    });

    test('Photographer can set and remove target gallery in FTP Inbox', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `FTP Target ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.reload();
        await sidebar.navigateTo('Dashboard');

        const ftpCard = page.locator('main').locator('.card').filter({ hasText: 'FTP Inbox' });
        const select = ftpCard.locator('select');
        await expect(select).toBeVisible();

        const optionValue = await select.locator('option').filter({ hasText: galleryName }).getAttribute('value');
        await select.selectOption(optionValue!);

        const setzenBtn = ftpCard.getByRole('button', { name: 'Setzen' });
        await expect(setzenBtn).toBeEnabled();
        await setzenBtn.click();

        await expect(ftpCard.locator('text=' + galleryName).first()).toBeVisible({ timeout: 5000 });

        await ftpCard.locator('button[title="Zuordnung aufheben"]').click();

        await expect(ftpCard.locator('select')).toBeVisible({ timeout: 5000 });
    });

    test('FTP Inbox imports one bounded batch manually and catches up once on the next mount', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `FTP Batch ${Math.random().toString(36).substring(2, 10)}`;
        let simulatedQueueCount = 0;
        let processCalls = 0;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const galleryResponse = await modal.submitModal('Speichern');
        const galleryId = galleryResponse?.gallery?.id;
        if (!galleryId) throw new Error('The FTP target gallery was not created.');
        helper.trackGallery(galleryId);

        const initialStatusRead = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/management/ftp/status' && response.request().method() === 'GET';
        });
        await sidebar.navigateTo('Dashboard');
        const initialStatusResponse = await initialStatusRead;
        expect(initialStatusResponse.ok()).toBe(true);

        const main = page.getByRole('main');
        const ftpCard = main.locator('.card').filter({ hasText: 'FTP Inbox' });
        const queueCount = ftpCard.getByTestId('ftp-queue-count');
        await expect(queueCount).toHaveText('0');
        const targetSelect = ftpCard.getByRole('combobox');
        await expect(targetSelect).toBeVisible();
        await expect(targetSelect).toHaveValue('');

        await page.route('**/api/management/ftp/status', async route => {
            const response = await route.fetch();
            const responseBody: unknown = await response.json();
            if (!isRecord(responseBody)) {
                throw new Error('FTP status response is not an object.');
            }

            await route.fulfill({
                response,
                body: JSON.stringify({ ...responseBody, file_count: simulatedQueueCount }),
            });
        });

        await page.route('**/api/management/ftp/process', async route => {
            processCalls += 1;
            const batchLimit = processCalls === 1 ? 2 : 1;
            const processed = Math.min(simulatedQueueCount, batchLimit);
            simulatedQueueCount -= processed;

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, processed }),
            });
        });

        expect(processCalls).toBe(0);

        simulatedQueueCount = 3;
        const galleryOption = ftpCard.getByRole('option', { name: new RegExp(galleryName) });
        await expect(galleryOption).toHaveCount(1);
        const optionValue = await galleryOption.getAttribute('value');
        if (optionValue === null) throw new Error('The FTP target gallery option has no value.');
        await ftpCard.getByRole('combobox').selectOption(optionValue);
        await ftpCard.getByRole('button', { name: 'Setzen' }).click();

        await expect(ftpCard.getByText(galleryName, { exact: true })).toBeVisible();
        await expect(ftpCard.getByRole('combobox')).toHaveCount(0);
        await expect(queueCount).toHaveText('3');
        expect(processCalls).toBe(0);

        await ftpCard.getByRole('button', { name: 'Bilder Importieren' }).click();
        await expect(queueCount).toHaveText('1');
        expect(processCalls).toBe(1);

        await sidebar.navigateTo('Galerien & Ordner');
        await sidebar.navigateTo('Dashboard');

        await expect.poll(() => processCalls).toBe(2);
        await expect(queueCount).toHaveText('0');
        expect(processCalls).toBe(2);
    });
});
