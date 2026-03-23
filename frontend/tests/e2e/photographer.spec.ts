import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
import path from 'path';

test.describe('Photographer Core Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Create, edit and actually upload an image to a gallery', async ({ page }) => {
        await auth.login();

        const uniqueId = Date.now();
        const galleryName = `Playwright Workflow ${uniqueId}`;
        const editedName = `Playwright Edited ${uniqueId}`;

        // 1. Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.clickButton('Speichern');

        await expect(page.locator(`text=${galleryName}`).first()).toBeVisible({ timeout: 5000 });

        // 2. In die Galerie navigieren
        await page.click(`text=${galleryName}`);
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        // 3. Galerie bearbeiten
        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        await modal.fillInputByLabel('Name der Galerie', editedName);
        await modal.clickButton('Speichern');
        
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();

        // 4. Echter Upload mit dem Backend-Fixture
        const fileInput = page.locator('input[type="file"]');
        await expect(fileInput).toBeAttached();
        
        // ESM kompatibler Pfad
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        
        // Datei ins Input-Feld laden
        await fileInput.setInputFiles(sampleImagePath);

        // 5. Warten bis das Bild verarbeitet und gerendert wird
        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeHidden({ timeout: 15000 });
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 10000 });
    });
});
