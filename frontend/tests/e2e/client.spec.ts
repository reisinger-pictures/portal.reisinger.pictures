import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
import path from 'path';

test.describe.serial('Client Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `E2E Selection ${uniqueId}`;
    let inviteLink = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin creates selection gallery and invite link', async ({ page }) => {
        await auth.login();
        
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.clickButton('Speichern');
        await expect(modal.activeModal).toBeHidden({ timeout: 10000 });
        await expect(page.locator(`text=${galleryName}`).first()).toBeVisible({ timeout: 15000 });

        await page.click(`text=${galleryName}`);
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        
        // Neues InviteModal UI: Wir müssen zuerst auf den persönlichen Link wechseln
        await page.locator('text=Persönlicher Link (Einzelperson)').click();
        
        // Das Label wurde ebenfalls umbenannt
        await modal.fillInputByLabel('Name des Gastes', 'Test Client');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        
        await modal.clickButton('Schließen');
    });

    test('Client can access selection gallery via invite link', async ({ page }) => {
        expect(inviteLink).not.toBe('');

        await page.goto(inviteLink);
        
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();
        

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auswahl abschließen' })).toBeVisible();

        // --- DAU Protection Check (Right-Click & Drag) ---
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 15000 });

        // 1. Prüfen, ob das Kontextmenü blockiert wird
        const isPrevented = await image.evaluate((el) => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        expect(isPrevented).toBe(true);

        // 2. Prüfen, ob das Bild nicht gezogen werden kann (Drag & Drop)
        await expect(image).toHaveAttribute('draggable', 'false');
    });
});
