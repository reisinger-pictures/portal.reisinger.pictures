import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupE2EData(request);
    await E2EUserHelper.cleanupTrackedUsers(request);
});

test.describe.serial('Download Triggers UI', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
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
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.selectByLabel('Sichtbarkeit', 'Öffentlich (Für alle sichtbar)');
        await modal.submitModal('Speichern');
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

        // 5. Test: Einzel-Download
        // Wir fangen das Download-Event ab, bevor wir klicken
        const singleDownloadPromise = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Einzel-Download' }).first().click();
        const singleDownload = await singleDownloadPromise;
        
        // Prüfen, ob die Datei eine JPG-Endung hat (oder JPEG)
        expect(singleDownload.suggestedFilename().toLowerCase()).toMatch(/\.jpe?g$/);

        // 6. Test: ZIP-Download
        const zipDownloadPromise = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Alle herunterladen (.zip)' }).click();
        const zipDownload = await zipDownloadPromise;
        
        // Prüfen, ob ein ZIP-Archiv generiert wird
        expect(zipDownload.suggestedFilename().toLowerCase()).toMatch(/\.zip$/);
    });
});
