import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';

test.describe('Client Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Client selection workflow with ratings', async ({ page }) => {
        await auth.login();
        
        const uniqueId = Date.now();
        const galleryName = `E2E Selection ${uniqueId}`;

        // Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.clickButton('Speichern');
        await expect(modal.activeModal).toBeHidden();

        // In Galerie navigieren und Invite erstellen
        await page.click(`text=${galleryName}`);
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await modal.fillInputByLabel('Für wen ist dieser Link?', 'Test Client');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLinkInput = modal.activeModal.locator('input[readonly]');
        const inviteLink = await inviteLinkInput.inputValue();
        
        await modal.clickButton('Schließen');
        await expect(modal.activeModal).toBeHidden();

        // Admin ausloggen
        await auth.logout();

        // Playwright navigiert nun als anonymer Gast zum erstellten Invite-Link
        await page.goto(inviteLink);
        
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();
        await page.waitForURL(new RegExp(galleryName.replace(/\s+/g, '-').toLowerCase()), { timeout: 10000 });

        // Verifizieren der Client-Ansicht
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auswahl abschließen' })).toBeVisible();
    });
});
