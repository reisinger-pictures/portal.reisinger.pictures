import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

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

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryName = `Metadata Test ${uniqueId()}`;

    const openMetadataEditor = async (page: Page) => {
        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Details & Metadaten' }).click();
        await expect(main.getByRole('heading', { name: 'IPTC Metadaten' })).toBeVisible();
    };

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can view and edit metadata in detail view', { tag: ['@feature:delivery:metadata'] }, async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await openMetadataEditor(page);

        const titleInput = page.getByRole('main').locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Playwright Test Title');

        const cityInput = page.getByRole('main').locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        await cityInput.click();
        // The visible autocomplete result is the synchronization point; the
        // test does not assert or stub the background request.
        await cityInput.pressSequentially('Salzburg', { delay: 100 });

        const dropdownItem = page.getByRole('main').getByRole('option').filter({ hasText: 'Salzburg' }).first();
        await expect(dropdownItem).toBeVisible({ timeout: 15000 });
        await dropdownItem.click();

        await expect(page.getByRole('main').locator('div.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).toHaveValue('Salzburg');
        await page.getByRole('main').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('main').getByRole('button', { name: 'Speichern' })).toBeEnabled();
    });

    // captured_at is immutable EXIF data and cannot be seeded through a
    // metadata mutation. Its read-only formatting is covered by the
    // IptcMetadataEditor component regression test.

    test('Photographer can add keywords', { tag: ['@feature:delivery:metadata'] }, async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName + ' Keywords');
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await openMetadataEditor(page);

        const keywordInput = page.getByRole('main').locator('.form-control').filter({ hasText: 'Schlagwörter' }).locator('input[type="text"]').first();
        await keywordInput.fill('test, e2e, playwright');
        // Press Enter to commit keywords
        await keywordInput.press('Enter');

        await page.getByRole('main').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('main').getByRole('button', { name: 'Speichern' })).toBeEnabled();
    });

    test('Photographer can add description', { tag: ['@feature:delivery:metadata'] }, async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName + ' Description');
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await openMetadataEditor(page);

        const descInput = page.getByRole('main').locator('div.form-control').filter({ hasText: 'Beschreibung' }).locator('textarea');
        const testDescription = 'Eine ausführliche Beschreibung für den E2E Test.';
        await descInput.fill(testDescription);

        await page.getByRole('main').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('main').getByRole('button', { name: 'Speichern' })).toBeEnabled();

        // Navigate back and re-open to verify persistence
        await page.locator('main button:has(span.mdi--arrow-left)').click();
        await openMetadataEditor(page);
        await expect(descInput).toHaveValue(testDescription);
    });

    test('Photographer can add copyright', { tag: ['@feature:delivery:metadata'] }, async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName + ' Copyright');
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        // Set copyright via profile page
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Profil');
        await page.getByRole('main').locator('.form-control').filter({ hasText: 'Standard-Urheber' }).locator('input').fill('© Test Photographer');
        await page.getByRole('main').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toBeVisible({ timeout: 5000 });

        // Navigate back to gallery and open detail view
        await sidebar.navigateTo('Galerien & Ordner');
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName + ' Copyright' }).first();
        await expect(galLink).toBeVisible();
        await galLink.scrollIntoViewIfNeeded();
        await galLink.click();
        await expect(page.getByRole('heading', { name: galleryName + ' Copyright' })).toBeVisible();

        await openMetadataEditor(page);

        const copyrightField = page.getByRole('main').locator('.form-control').filter({ hasText: 'Urheber / Copyright' }).locator('input');
        await expect(copyrightField).toHaveValue('© Test Photographer');
    });
});
