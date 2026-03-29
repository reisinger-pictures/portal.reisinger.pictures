import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Client Notifications Opt-In', () => {
    test('Client can toggle email notifications in gallery view', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = 'OptIn Test ' + Date.now();

        await auth.login(); 
        
        // Eigene Galerie erstellen für Isolierung
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        const savePromise = page.waitForResponse(res => res.url().includes('/api/management/galler') && ['POST', 'PUT'].includes(res.request().method()));
        await modal.clickButton('Speichern');
        await savePromise;
        await expect(modal.activeModal).toBeHidden({ timeout: 15000 });

        // Galerie öffnen
        const galleryLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galleryLink).toBeVisible({ timeout: 15000 });
        await galleryLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });
        
        // Kundenansicht simulieren
        await page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' }).click();
        
        // Toggle finden und klicken
        const toggle = page.locator('input[type="checkbox"].toggle-primary');
        await expect(toggle).toBeVisible({ timeout: 15000 });
        
        const isCheckedInitial = await toggle.isChecked();
        
        // Auf API warten
        const optInResponse = page.waitForResponse(res => res.url().includes('/opt-in') && res.request().method() === 'POST');
        await toggle.click();
        await optInResponse;
        
        // Neuladen um Persistenz zu verifizieren
        await page.reload();
        const toggleAfterReload = page.locator('input[type="checkbox"].toggle-primary');
        await expect(toggleAfterReload).toBeVisible({ timeout: 15000 });
        expect(await toggleAfterReload.isChecked()).not.toBe(isCheckedInitial);
    });
});
