import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.afterAll(async ({ request }) => {
  await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('Client Notifications Opt-In', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    test('Client can toggle email notifications in gallery view', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = 'OptIn Test ' + Date.now();

        await auth.login(testUser.email, testUser.password); 
        
        // Eigene Galerie erstellen für Isolierung
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.submitModal('Speichern');

        // Galerie öffnen
        const galleryLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galleryLink).toBeVisible({ timeout: 15000 });
        await galleryLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        
        // Kundenansicht simulieren
        await page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' }).click();
        
        // Toggle finden und klicken
        const toggle = page.locator('input[type="checkbox"].toggle-primary');
        await expect(toggle).toBeVisible();
        
        const isCheckedInitial = await toggle.isChecked();
        
        // Auf API warten
        const optInResponse = page.waitForResponse(res => res.url().includes('/opt-in') && res.request().method() === 'POST');
        await toggle.click();
        await optInResponse;
        
        // Neuladen um Persistenz zu verifizieren
        await page.reload();
        const toggleAfterReload = page.locator('input[type="checkbox"].toggle-primary');
        await expect(toggleAfterReload).toBeVisible();
        expect(await toggleAfterReload.isChecked()).not.toBe(isCheckedInitial);
    });
});
