import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('Download Triggers UI', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryName = `Download Test ${uniqueId()}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        
        await auth.login(testUser.email, testUser.password);
    });

    test('Triggers single image and zip downloads successfully', async ({ page }) => {
        // 1. Setup: Delivery Galerie erstellen und auf ÖFFENTLICH setzen!
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)', visibility: 'Öffentlich (Für alle sichtbar)', freeDownload: true });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);
        const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(link).toBeVisible({ timeout: 15000 });
        
        await page.locator('main').locator('a').filter({ hasText: galleryName }).first().click();

        // 2. Bild hochladen
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        // URL der Galerie merken
        const galleryUrl = page.url();

        // 3. Admin ausloggen, um in die Gast/Kunden-Ansicht zu wechseln
        await auth.logout();
        await page.waitForTimeout(1500); // SWR Cache leeren lassen

        // 4. Als anonymer Gast die öffentliche Galerie aufrufen
        await page.goto(galleryUrl);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible();
        await expect(page.locator('a.pswp-item img').first()).toHaveJSProperty('complete', true);
        await expect(async () => { expect(await page.locator('a.pswp-item img').first().evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0); }).toPass();

        // 5. Test: Einzel-Download (Via Detailansicht)
        await page.getByRole('button', { name: 'Bild öffnen' }).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible();

        const [singleDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'Jetzt herunterladen' }).first().click()
        ]);
        expect(singleDownload.suggestedFilename().toLowerCase()).toMatch(/\.jpe?g$/);

        // Zurück zur Galerie für den ZIP Download
        await page.goBack();
        await expect(page.locator('h1:has-text("' + galleryName + '")')).toBeVisible();

        // 6. Test: ZIP-Download (Dropdown ausklappen und Format wählen)
        await page.locator('div[role="button"]').filter({ hasText: 'Alle herunterladen (.zip)' }).click();
        
        const [zipDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('link', { name: 'ORIGINAL Format' }).click()
        ]);
        expect(zipDownload.suggestedFilename().toLowerCase()).toMatch(/\.zip$/);
    });
});
