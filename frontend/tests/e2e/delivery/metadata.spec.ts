import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.afterAll(async ({ request }) => {
  await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('Metadata & Detail View Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Date.now() + Math.floor(Math.random() * 1000);
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
        await modal.submitModal('Speichern');
        
        // Robustes Auffinden des Galerie-Links (inkl. Reload-Logik bei SWR-Verzögerung)
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });

        await page.locator('main').locator('a').filter({ hasText: galleryName }).first().click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Playwright Test Title');

        const cityInput = page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        await cityInput.click();
        await cityInput.pressSequentially('Salzburg', { delay: 100 });

        const dropdownItem = page.locator('li').filter({ hasText: 'Salzburg' }).first();
        await expect(dropdownItem).toBeVisible();
        await dropdownItem.click();

        await expect(page.locator('div.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).toHaveValue('Salzburg');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled();
    });
});
