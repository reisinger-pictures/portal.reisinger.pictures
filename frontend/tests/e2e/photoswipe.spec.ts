import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
import path from 'path';

test.describe.serial('PhotoSwipe & Lightbox UI', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Lightbox Test ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Lightbox opens and displays custom IPTC captions', async ({ page }) => {
        await auth.login();

        // 1. Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.clickButton('Speichern');
        await expect(page.locator(`text=${galleryName}`).first()).toBeVisible({ timeout: 15000 });
        await page.click(`text=${galleryName}`);

        // 2. Bild hochladen
        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        // 3. Metadaten pflegen
        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel (Object Name)' }).locator('input');
        await titleInput.fill('Episches Testbild');

        const descInput = page.locator('div.form-control').filter({ hasText: 'Beschreibung (Caption)' }).locator('textarea');
        await descInput.fill('Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.');

        const artistInput = page.locator('div.form-control').filter({ hasText: 'Urheber / Copyright (Artist)' }).locator('input');
        await artistInput.fill('Jane Doe');

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled({ timeout: 5000 });

        // 4. Zurück zur Galerie
        await page.locator('button.btn-ghost:has(.mdi--arrow-left)').click();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        // HARD REFRESH: Wir zwingen die SPA, den Cache zu ignorieren und die frischen DB-Daten zu laden
        await page.reload();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        // WARTEN auf DOM: Das Attribut muss nun die neuen Daten aus der DB enthalten
        await expect(page.locator('a.pswp-item').first()).toHaveAttribute('data-title', 'Episches Testbild', { timeout: 15000 });

        // 5. Lightbox öffnen
        await page.locator('a.pswp-item').first().click();

        // 6. Assert: Lightbox sichtbar
        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();

        // 7. Assert: Custom Captions injiziert
        await expect(lightbox.locator('text=Episches Testbild')).toBeVisible();
        await expect(lightbox.locator('text=Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.')).toBeVisible();
        await expect(lightbox.locator('text=© Jane Doe')).toBeVisible();

        // 8. Lightbox schließen
        // Architektur-konform: Geduldige Asserts (Auto-Retries) statt statischen Sleeps.
        // PhotoSwipe ignoriert Schließen-Events, solange die Öffnungs-Animation läuft.
        // Wir wiederholen den Klick asynchron, bis die Lightbox wirklich zu ist.
        await expect(async () => {
            await page.locator('button.pswp__button--close').click({ force: true });
            await expect(lightbox).toBeHidden({ timeout: 2000 });
        }).toPass({ timeout: 15000 });
    });
});
