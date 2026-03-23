import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
import path from 'path';

test.describe.serial('Gallery Invite Link Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Invite Test ${uniqueId}`;
    let inviteLinkAnon = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin creates gallery and generates anonymous invite link', async ({ page }) => {
        await auth.login();
        
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.clickButton('Speichern');
        await expect(page.locator(`text=${galleryName}`).first()).toBeVisible({ timeout: 20000 });

        await page.click(`text=${galleryName}`);

        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        
        // Kein Name eintragen = Anonymer Link
        await modal.clickButton('Generieren');
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        
        inviteLinkAnon = await modal.activeModal.locator('input[readonly]').inputValue();
    });

    test('Guest (Anonymous) redeems invite by providing name and email', async ({ page }) => {
        expect(inviteLinkAnon).not.toBe('');
        
        await page.goto(inviteLinkAnon);
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        
        await page.getByPlaceholder('z.B. Maria Muster').fill('Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill(`gast-${uniqueId}@example.com`);
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();
        // networkidle entfernt (Anti-Pattern mit SWR)
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 20000 });
    });

    test('Logged-in User redeems anonymous invite directly', async ({ page }) => {
        expect(inviteLinkAnon).not.toBe('');
        
        // Logge dich als Admin/Fotograf ein
        await auth.login();

        // Rufe den Invite-Link auf
        await page.goto(inviteLinkAnon);
        
        // Da der User angemeldet ist, wird das Formular gar nicht erst angezeigt,
        // das Backend verknüpft die Galerie sofort mit dem bestehenden Account und leitet weiter.
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 20000 });
    });
});
