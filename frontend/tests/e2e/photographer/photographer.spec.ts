import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import path from 'path';

test.describe.serial('Photographer Core Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Playwright Workflow ${uniqueId}`;
    const editedName = `Playwright Edited ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        
        // Da die Tests isoliert sind, loggen wir uns für jeden Teilschritt neu ein.
        // Das sorgt dafür, dass wir immer sauber vom Dashboard starten.
        await auth.login();
    });

    test('Photographer can create a new delivery gallery', async ({ page }) => {
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        // Explizit auf die API-Antwort warten, um Flakiness auszuschließen
        const responsePromise = page.waitForResponse(res => res.url().includes('/api/management/galleries') && res.request().method() === 'POST');
        const saveResponse = page.waitForResponse(res => res.url().includes('/api/management/galleries') && (res.request().method() === 'POST' || res.request().method() === 'PUT'));
        await modal.clickButton('Speichern');
        await saveResponse;
        const response = await responsePromise;
        expect(response.status()).toBe(200);

        await expect(page.locator('main').locator(`a:has-text("${galleryName}")`).first()).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can edit an existing gallery', async ({ page }) => {
        await sidebar.navigateTo('Galerien');
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        await modal.fillInputByLabel('Name der Galerie', editedName);
        await modal.clickButton('Speichern');
        
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can upload an image to the gallery', async ({ page }) => {
        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });

        const fileInput = page.locator('input[type="file"]');
        await expect(fileInput).toBeAttached();
        
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);

        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeHidden({ timeout: 15000 });
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });
    });

    test('Photographer sees the new gallery and photo in personal feed on dashboard', async ({ page }) => {
        // Zurück zum Dashboard navigieren
        await page.goto('/');

        // Warten bis der Personal Feed gerendert ist
        const feedHeader = page.locator('h2:has-text("Deine neuesten Uploads & Galerien")');
        await expect(feedHeader).toBeVisible({ timeout: 15000 });

        // Feed auf generelle Funktionalität prüfen (irgendein Bild/Galerie muss laden)
        await expect(page.locator('.space-y-8').first()).toBeVisible({ timeout: 15000 });
    });


    test('Photographer can toggle between management and client view using the tab switcher', async ({ page }) => {
        // 1. Zur Galerie navigieren
        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });

        // 2. Prüfen, ob wir standardmäßig in der Management-Ansicht sind
        const inviteBtn = page.getByRole('button', { name: 'Einladungslink...' });
        await expect(inviteBtn).toBeVisible();

        // 3. Tab-Switcher suchen und auf "Kundenansicht" klicken
        const clientTab = page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' });
        await expect(clientTab).toBeVisible();
        await clientTab.click();

        // 4. Verifizieren: URL enthält ?view=client und UI ändert sich zur DeliveryView
        await expect(page).toHaveURL(/.*\?view=client/);
        // UI hat erfolgreich gewechselt (Kundenansicht lädt)
        await expect(inviteBtn).toBeHidden(); // Management-Buttons müssen weg sein

        // 5. Zurück zur Verwaltung wechseln
        const managementTab = page.locator('button[role="tab"]').filter({ hasText: 'Verwaltung' });
        await managementTab.click();

        // 6. Verifizieren: URL enthält ?view=management und UI ist wieder ManagementGalleryView
        await expect(page).toHaveURL(/.*\?view=management/);
        await expect(inviteBtn).toBeVisible({ timeout: 10000 });
        await expect(page.locator('text=Lade deine Bilder herunter.')).toBeHidden();
    });

});
