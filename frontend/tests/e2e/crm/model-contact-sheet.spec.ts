import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Model Contact Sheet Export', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin exportiert internes und externes Contact Sheet als PDF', { tag: ['@feature:model-export', '@smoke'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Fixture via the sanctioned API helper: one public + one internal photo.
        const model = await helper.createRegisteredModel({ photoCount: 2 });

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        // The Models filter shares its accessible name "Suche" with the header
        // gallery search and that search's submit button, so getByLabel('Suche')
        // is a strict-mode violation. The `main` landmark cannot disambiguate
        // either: it wraps the sticky header as well. Target the filter via the
        // id its <label htmlFor> points at.
        await page.locator('#model-filter-q').fill(model.firstName);

        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();

        const detail = page.locator('main');
        const internalButton = detail.getByTestId('model-contact-sheet-internal');
        await expect(internalButton).toBeVisible();
        await expect(detail.getByTestId('model-contact-sheet-external')).toBeVisible();

        // --- Internal variant -------------------------------------------------
        const internalResponsePromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet') && response.url().includes('variant=internal'),
        );
        const internalDownloadPromise = page.waitForEvent('download');
        await internalButton.click();

        const internalResponse = await internalResponsePromise;
        expect(internalResponse.status()).toBe(200);
        expect(internalResponse.headers()['content-type']).toContain('application/pdf');
        expect(internalResponse.headers()['content-disposition']).toContain('attachment');
        expect(internalResponse.headers()['content-disposition']).toMatch(/filename="?model-.*-internal-\d{8}\.pdf"?/);

        const internalDownload = await internalDownloadPromise;
        expect(internalDownload.suggestedFilename()).toMatch(/-internal-\d{8}\.pdf$/);
        await expect(page.locator('.toast')).toContainText('Contact Sheet wurde heruntergeladen');

        // --- External variant -------------------------------------------------
        const externalResponsePromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet') && response.url().includes('variant=external'),
        );
        const externalDownloadPromise = page.waitForEvent('download');
        await detail.getByTestId('model-contact-sheet-external').click();

        const externalResponse = await externalResponsePromise;
        expect(externalResponse.status()).toBe(200);
        expect(externalResponse.headers()['content-type']).toContain('application/pdf');

        const externalDownload = await externalDownloadPromise;
        expect(externalDownload.suggestedFilename()).toMatch(/-external-\d{8}\.pdf$/);
    });
});
