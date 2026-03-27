import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import path from 'path';

test.describe.serial('Metadata & Detail View Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Metadata Test ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Photographer can view and edit metadata in detail view', async ({ page }) => {
        await auth.login();

        // 1. Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.clickButton('Speichern');
        await expect(modal.activeModal).toBeHidden({ timeout: 15000 });
        await expect(page.locator('main').locator(`a:has-text("${galleryName}")`).first()).toBeVisible({ timeout: 15000 });

        await page.locator('main').locator(`a:has-text("${galleryName}")`).first().click();
        
        // 2. Bild hochladen
        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);

        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        // 3. In die Detailansicht navigieren
        await page.locator('button[title="Details & Metadaten"]').first().click();

        // 4. Warten bis Detail View geladen ist
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        // 5. Metadaten bearbeiten
        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Playwright Test Title');
        
        await page.getByRole('button', { name: 'Speichern' }).click();

        // Prüfen ob der Speichern-Button wieder freigegeben wurde (Spinner ist weg)
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled({ timeout: 5000 });
    });
});
