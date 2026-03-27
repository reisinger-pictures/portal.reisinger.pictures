import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Admin Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin can manage users and roles', async ({ page }) => {
        await auth.login();

        await sidebar.navigateTo('Benutzer & Rechte');
        await expect(page.locator('h1:has-text("Benutzer & Rechte")')).toBeVisible();

        const uniqueEmail = `e2e-client-${Date.now()}@example.com`;

        await page.getByRole('button', { name: '+ Neuen Nutzer anlegen' }).click();
        await modal.fillInputByLabel('Name', 'Test Admin Client');
        await modal.fillInputByLabel('E-Mail Adresse', uniqueEmail);
        
        // Klick ausführen (Achtung: page.once('dialog') ist entfernt, da wir nun Toasts nutzen!)
        await modal.clickButton('Nutzer anlegen & Einladen');

        // Wenn das Modal nicht schließt, wirft das Backend einen 500er (Wahrscheinlich Mailpit nicht erreichbar!)
        // Prüfen, ob Erfolgs-Toast sichtbar ist (Timeout erhöht für evtl. SMTP/Mailpit-Latenz)
        const toast = page.locator('.toast');
        await expect(toast).toBeVisible({ timeout: 15000 });
        await expect(toast).toContainText('Nutzer angelegt', { timeout: 5000 });
        
        // Modal sollte sich danach geschlossen haben
        await expect(modal.activeModal).toBeHidden();

        await page.fill('input[placeholder="Nutzer suchen (Name oder E-Mail)..."]', uniqueEmail);
        await expect(page.locator(`td:has-text("${uniqueEmail}")`)).toBeVisible();

        await page.locator('tr').filter({ hasText: uniqueEmail }).locator('button', { hasText: 'Bearbeiten' }).click();
        await expect(modal.activeModal.locator('h3:has-text("Test Admin Client bearbeiten")')).toBeVisible();
        await modal.clickButton('Abbrechen');
    });

    test('Admin can access settings', async ({ page }) => {
        await auth.login();
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("Einstellungen")')).toBeVisible();
    });

    test('Admin can update profile settings including FTP slug', async ({ page }) => {
        await auth.login();
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("Einstellungen")')).toBeVisible();

        // Generiere eindeutige Werte
        const uniqueSuffix = Date.now().toString().slice(-6);
        const newName = `E2E Admin ${uniqueSuffix}`;
        const newSlug = `e2e-${uniqueSuffix}`;
        const newCopyright = `© ${newName}`;

        // Felder ausfüllen (Robuste Locators via .form-control)
        await page.locator('.form-control').filter({ hasText: 'Dein Name' }).locator('input').fill(newName);
        await page.locator('.form-control').filter({ hasText: 'FTP Upload Ordner' }).locator('input').fill(newSlug);
        await page.locator('.form-control').filter({ hasText: 'Standard-Urheber' }).locator('input').fill(newCopyright);

        // Speichern
        await page.getByRole('button', { name: 'Profil speichern' }).click();

        // Toast abwarten
        const toast = page.locator('.toast');
        await expect(toast).toBeVisible({ timeout: 15000 });
        await expect(toast).toContainText('Profil aktualisiert', { timeout: 5000 });

        // Reload um Persistenz (Datenbank-Speicherung) zu testen
        await page.reload();
        await expect(page.locator('.form-control').filter({ hasText: 'Dein Name' }).locator('input')).toHaveValue(newName, { timeout: 15000 });
        await expect(page.locator('.form-control').filter({ hasText: 'FTP Upload Ordner' }).locator('input')).toHaveValue(newSlug);
        await expect(page.locator('.form-control').filter({ hasText: 'Standard-Urheber' }).locator('input')).toHaveValue(newCopyright);
    });


    test('Header Live-Search dropdown appears and handles navigation', async ({ page }) => {
        await auth.login();

        const headerSearchInput = page.locator('header input[placeholder="Suche in allen Galerien..."]');
        await expect(headerSearchInput).toBeVisible();

        // 1. Eingabe von 1 Zeichen -> Dropdown bleibt geschlossen
        await headerSearchInput.fill('A');
        await expect(page.locator('text=Suche nach "A"')).toBeHidden();

        // 2. Eingabe von 2+ Zeichen -> Dropdown öffnet sich
        await headerSearchInput.fill('Ab');
        await expect(page.locator('text=Suche nach "Ab"')).toBeVisible({ timeout: 15000 });

        // 3. Klick auf den Link
        await page.locator('text=Suche nach "Ab"').click();
        
        // 4. Verifikation: Navigation zu SearchView und Input enthält den Wert aus der URL
        await expect(page).toHaveURL(/.*\/search\?q=Ab/);
        const searchViewInput = page.locator('input[placeholder="Galerien und Bilder suchen..."]');
        await expect(searchViewInput).toHaveValue('Ab');
    });

});
