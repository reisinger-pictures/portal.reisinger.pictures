import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';



test.describe('Metadata & Detail View Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryName = `Metadata Test ${uniqueId()}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can view and edit metadata in detail view', async ({ page }) => {
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);
        
        // Robustes Auffinden des Galerie-Links (inkl. Reload-Logik bei SWR-Verzögerung)
        const galLink = page.locator('main').getByText(galleryName).first();
        await expect(galLink).toBeVisible();

        await page.locator('main').getByText(galleryName).first().click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Playwright Test Title');

        const cityInput = page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        await cityInput.click();
        const searchSalzburgPromise = page.waitForResponse(res => res.url().includes('/api/search/locations'));
        await cityInput.pressSequentially('Salzburg', { delay: 100 });
        await searchSalzburgPromise;

        const dropdownItem = page.locator('li').filter({ hasText: 'Salzburg' }).first();
        await expect(dropdownItem).toBeVisible({ timeout: 15000 });
        await dropdownItem.click();

        await expect(page.locator('div.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).toHaveValue('Salzburg');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled();
    });
});
