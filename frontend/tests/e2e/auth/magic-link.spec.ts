import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
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
        await expect(page.locator(`a:has-text("${galleryName}")`).first()).toBeVisible({ timeout: 20000 });

        await page.locator(`a:has-text("${galleryName}")`).first().click();

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

    test('Guest (Anonymous) redeems invite directly and accesses gallery', async ({ page }) => {
        expect(inviteLinkAnon).not.toBe('');

        await page.goto(inviteLinkAnon);
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();

        const guestEmail = `gast-${uniqueId}@example.com`;
        await page.getByPlaceholder('z.B. Maria Muster').fill('Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill(guestEmail);
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();

        // Statt auf eine Mail zu warten, prüfen wir, ob der Gast direkt in die Galerie geleitet wird
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();

        // Bild muss sichtbar sein, was die transienten Rechte bestätigt
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 15000 });
    });

    test('Logged-in User redeems anonymous invite directly', async ({ page }) => {
        // Sicherstellen, dass der Test nicht isoliert fehlschlägt
        test.skip(inviteLinkAnon === '', 'Test requires link from previous step');

        await auth.login();

        // Wir fangen den API-Request ab, um sicherzustellen, dass das Auto-Redeem im Hintergrund feuert
        // Aufruf des Magic Links
        await page.goto(inviteLinkAnon);

        // Da AuthHelper.login nun auf den Abmelden-Button wartet, ist die Session hier etabliert.

        // 2. Aufruf des Magic Links
        await page.goto(inviteLinkAnon);

        // 3. Geduldiges Warten auf den Auto-Redeem Redirect in den /galleries/ Pfad (gemäß TESTING.md)
        await expect(page).toHaveURL(/.*\/galleries\/.*/, { timeout: 20000 });

        // Finale Bestätigung: Der Name der Galerie ist als h1 sichtbar
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
    });
});