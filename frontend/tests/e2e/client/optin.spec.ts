import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import {NetworkHelper} from "../helpers/NetworkHelper";
import {FormHelper} from '../helpers/FormHelper';



test.describe('Client Notifications Opt-In', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Client can toggle email notifications in gallery view', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = 'OptIn Test ' + Math.random().toString(36).substring(2, 10);

        await auth.login(testUser.email, testUser.password); 
        
        // Eigene Galerie erstellen für Isolierung
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
                const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        // Galerie öffnen
        const galleryLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galleryLink).toBeVisible();
        await galleryLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        
        // Kundenansicht simulieren
        await page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' }).click();
        
        // Toggle finden und klicken
        const toggle = page.locator('main input[type="checkbox"]').first();
        await expect(toggle).toBeVisible();
        
        const isCheckedInitial = await toggle.isChecked();
        
        // Auf API warten
        const network = new NetworkHelper(page);
        const optInResponse = network.waitForOptIn();
        await toggle.click();
        await optInResponse;
        
        // Neuladen um Persistenz zu verifizieren
        await page.reload();
        const toggleAfterReload = page.locator('main input[type="checkbox"]').first();
        await expect(toggleAfterReload).toBeVisible();
        expect(await toggleAfterReload.isChecked()).not.toBe(isCheckedInitial);
    });
});
