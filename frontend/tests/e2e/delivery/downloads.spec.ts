import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe.serial('Download Triggers UI', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Download Test ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        
        await auth.login();
    });

    test('Triggers single image and zip downloads successfully', async ({ page }) => {
        // 1. Setup: Delivery Galerie erstellen und auf ÖFFENTLICH setzen!
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.selectByLabel('Sichtbarkeit', 'Öffentlich (Für alle sichtbar)');
        const savePromise = page.waitForResponse(res => res.url().includes('/api/management/galler') && ['POST', 'PUT'].includes(res.request().method()));
        await modal.clickButton('Speichern');
        await savePromise;
        await expect(page.locator('main').locator('a').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });
        
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
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });
        await expect(page.locator('a.pswp-item img').first()).toHaveJSProperty('complete', true);
        await expect(async () => { expect(await page.locator('a.pswp-item img').first().evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0); }).toPass({ timeout: 15000 });

        // 5. Test: Einzel-Download
        // Wir fangen das Download-Event ab, bevor wir klicken
        const singleDownloadPromise = page.waitForEvent('download', { timeout: 15000 });
        await page.getByRole('button', { name: 'Einzel-Download' }).first().click();
        const singleDownload = await singleDownloadPromise;
        
        // Prüfen, ob die Datei eine JPG-Endung hat (oder JPEG)
        expect(singleDownload.suggestedFilename().toLowerCase()).toMatch(/\.jpe?g$/);

        // 6. Test: ZIP-Download
        const zipDownloadPromise = page.waitForEvent('download', { timeout: 15000 });
        await page.getByRole('button', { name: 'Alle herunterladen (.zip)' }).click();
        const zipDownload = await zipDownloadPromise;
        
        // Prüfen, ob ein ZIP-Archiv generiert wird
        expect(zipDownload.suggestedFilename().toLowerCase()).toMatch(/\.zip$/);
    });
});
