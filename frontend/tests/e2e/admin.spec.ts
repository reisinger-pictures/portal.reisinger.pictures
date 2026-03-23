import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';

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
});
