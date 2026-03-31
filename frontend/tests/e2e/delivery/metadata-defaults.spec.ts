import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe.serial('Smart Assistance & Metadata Defaults Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Smart Default Test ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Gallery defaults behavior: Graz (auto-fill) and Linz (stay empty on ambiguity)', async ({ page }) => {
        // 1. Neue Delivery Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        
        await modal.submitModal('Speichern');

        // 2. Galerie öffnen und Vorgaben-Modal aufrufen
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.click();

        await page.getByRole('button', { name: 'Vorgaben...' }).click();
        await expect(page.locator('h3:has-text("Metadaten-Vorgaben")')).toBeVisible();

        // Metadaten Defaults aktivieren
        await page.locator('span.label-text').filter({ hasText: 'Standard-Metadaten beim Upload anwenden' }).click();

        const cityInput = page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        const stateInput = page.locator('.form-control').filter({ has: page.locator('.label-text', { hasText: 'Bundesland' }) }).locator('input[type="text"]');

        // --- FALL 1: Graz (Eindeutiger Fall) ---
        await cityInput.click();
        await cityInput.pressSequentially('Graz', { delay: 100 });

        const dropdownGraz = page.locator('li').filter({ hasText: 'Graz' }).first();
        await expect(dropdownGraz).toBeVisible({ timeout: 15000 });
        await dropdownGraz.click();

        await expect(stateInput).toHaveValue('Steiermark');

        // --- FALL 2: Linz (Mehrdeutigkeit) ---
        await cityInput.click();
        await cityInput.clear();
        await cityInput.pressSequentially('Linz', { delay: 100 });

        const dropdownLinzOOE = page.locator('li').filter({ hasText: 'Oberösterreich' }).first();
        await expect(dropdownLinzOOE).toBeVisible({ timeout: 15000 });
        await dropdownLinzOOE.click();

        await expect(stateInput).toHaveValue('Oberösterreich');
        
        // Speichern im Vorgaben-Modal
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('text=Metadaten-Vorgaben gespeichert')).toBeVisible();
        await page.keyboard.press('Escape');

        // 3. Bild hochladen und Vererbung prüfen
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        await expect(page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]')).toHaveValue('Linz');
        await expect(page.locator('.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).toHaveValue('Oberösterreich');
    });
});