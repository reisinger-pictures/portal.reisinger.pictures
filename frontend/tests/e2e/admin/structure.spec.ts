import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Management Structure View (Tree)', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const groupName = `Tree Group ${uniqueId}`;
    const subGroupName = `Sub Group ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login();
    });

    test('Admin can create nested groups and toggle tree nodes', async ({ page }) => {
        // 1. Zur Verwaltung navigieren
        await sidebar.navigateTo('Galerien');
        await expect(page.locator('h1:has-text("Galerien")')).toBeVisible();

        // 2. Übergeordneten Ordner erstellen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', groupName);
        const savePromise = page.waitForResponse(res => res.url().includes('/api/management/galler') && ['POST', 'PUT'].includes(res.request().method()));
        await modal.clickButton('Speichern');
        await savePromise;
        await expect(modal.activeModal).toBeHidden({ timeout: 15000 });

        // Warten bis der Ordner im DOM gerendert ist (Geduldiges Assert)
        const rootGroupNode = page.locator(`summary:has-text("${groupName}")`);
        await expect(rootGroupNode).toBeVisible({ timeout: 15000 });

        // 3. Unterordner im erstellten Ordner anlegen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', subGroupName);
        await modal.selectByLabel('Übergeordnete Meta-Galerie', groupName);
        const savePromise2 = page.waitForResponse(res => res.url().includes('/api/management/galler') && ['POST', 'PUT'].includes(res.request().method()));
        await modal.clickButton('Speichern');
        await savePromise2;
        await expect(modal.activeModal).toBeHidden({ timeout: 15000 });

        // 4. Verifizieren: "Alle ausklappen" Button testen
        await page.getByRole('button', { name: 'Alle ausklappen' }).click();
        
        // Der Unterordner muss nun zwingend sichtbar sein
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible({ timeout: 15000 });

        // 5. Verifizieren: "Alle einklappen" Button testen
        await page.getByRole('button', { name: 'Alle einklappen' }).click();
        
        // Das <details> Element darf nun nicht mehr das 'open' Attribut besitzen
        await expect(rootGroupNode.locator('..')).not.toHaveAttribute('open', '');

        // 6. Manuellen Toggle (Klick auf den Ordner) testen
        await rootGroupNode.click();
        await expect(rootGroupNode.locator('..')).toHaveAttribute('open', '');
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible();
    });
});
