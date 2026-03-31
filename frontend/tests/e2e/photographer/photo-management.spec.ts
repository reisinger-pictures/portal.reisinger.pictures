import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';

test.describe.serial('Photo Management Workflow (Flows A, B, K, L, M)', () => {
    let adminUser = { email: '', password: '' };
    let photogUser = { email: '', password: '' };
    let clientUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        adminUser = await E2EUserHelper.createIsolatedUser(request, 'admin');
        photogUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
        clientUser = await E2EUserHelper.createIsolatedUser(request, 'client');
    });

    const uniqueId = Date.now();
    const galleryNameB = `Flow B Test ${uniqueId}`;
    const galleryNameA = `Flow A Test ${uniqueId}`;
    const galleryNameK = `Flow K Expired ${uniqueId}`;

    test('Flow B: Photographer can delete a photo from detail view', async ({ page }) => {
        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page);

        await auth.login(photogUser.email, photogUser.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryNameB);

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible({ timeout: 15000 });

        await page.getByRole('button', { name: 'Bild löschen' }).click();
        
        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible({ timeout: 15000 });

        await confirmModal.getByRole('button', { name: 'Löschen', exact: true }).click();
        await expect(confirmModal).toBeHidden({ timeout: 10000 });

        await expect(page.locator(`h1:has-text("${galleryNameB}")`)).toBeVisible({ timeout: 15000 });
        await page.reload();
        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeVisible({ timeout: 15000 });
    });

    test('Flow A: Photographer grants transient metadata rights via Magic Link, Client edits, Photographer restores', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryHelper = new GalleryHelper(page);
        let magicLink = '';

        // === PHASE 1: PHOTOGRAPHER PREPARES GALLERY & LINK ===
        await auth.login(photogUser.email, photogUser.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryNameA);

        await page.getByRole('button', { name: 'Vorgaben...' }).click();
        await modal.toggleCheckboxByLabel('Kunden dürfen Metadaten bearbeiten', true);
        await modal.submitModal('Speichern');

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible({ timeout: 15000 });
        
        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Originaler Titel vom Fotografen');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten gespeichert', { timeout: 10000 });
        
        await page.locator('button.btn-ghost:has(.mdi--arrow-left)').click();
        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        
        await modal.toggleCheckboxByLabel('Gast darf Metadaten bearbeiten', true);
        await modal.clickButton('Generieren');
        
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible({ timeout: 15000 });
        magicLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await auth.logout();

        // === PHASE 2: GUEST EDITS VIA MAGIC LINK ===
        await page.goto(magicLink);
        await expect(page.locator('h2:has-text("Willkommen")')).toBeVisible({ timeout: 15000 });
        
        await page.getByPlaceholder('z.B. Maria Muster').fill('Test Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill('gast@example.com');
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();
        
        await expect(page.locator(`h1:has-text("${galleryNameA}")`)).toBeVisible({ timeout: 15000 });

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible({ timeout: 15000 });

        const clientTitleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await expect(clientTitleInput).toHaveValue('Originaler Titel vom Fotografen', { timeout: 10000 });
        
        await clientTitleInput.fill('Titel geändert durch den Kunden');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten gespeichert', { timeout: 10000 });

        // === PHASE 3: PHOTOGRAPHER RESTORES ===
        await auth.logout();
        await auth.login(photogUser.email, photogUser.password);
        await sidebar.navigateTo('Galerien');
        await page.locator('main').locator('a').filter({ hasText: galleryNameA }).first().click();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input')).toHaveValue('Titel geändert durch den Kunden', { timeout: 10000 });

        await page.getByRole('button', { name: 'Historie' }).click();
        const historyModal = page.locator('.modal-open');
        await expect(historyModal.locator('h3:has-text("Änderungshistorie")')).toBeVisible({ timeout: 15000 });

        await expect(historyModal.locator('td').filter({ hasText: 'Originaler Titel vom Fotografen' })).toBeVisible({ timeout: 15000 });

        await historyModal.getByRole('button', { name: 'Wiederherstellen' }).click();
        const confirmGlobal = page.locator('.modal-global');
        await expect(confirmGlobal).toBeVisible({ timeout: 15000 });
        await confirmGlobal.getByRole('button', { name: 'Wiederherstellen' }).click();

        await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input')).toHaveValue('Originaler Titel vom Fotografen', { timeout: 10000 });
    });

    test('Flow K: Expired galleries are crossed out and deny guest access', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);

        await auth.login(photogUser.email, photogUser.password);

        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryNameK);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.selectByLabel('Sichtbarkeit', 'Öffentlich (Für alle sichtbar)');
        
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        const dateString = yesterday.toISOString().split('T')[0];
        
        await modal.fillInputByLabel('Ablaufdatum', dateString);
        await modal.submitModal('Speichern');

        const link = page.locator('main').locator('a').filter({ hasText: galleryNameK }).first();
        await expect(link).toBeVisible({ timeout: 15000 });
        await expect(link).toHaveClass(/line-through/, { timeout: 15000 });

        const href = await link.getAttribute('href');
        expect(href).not.toBeNull();

        await auth.logout();

        await page.goto(href as string);
        await expect(page.locator('text=Galerie nicht gefunden oder Zugriff verweigert.')).toBeVisible({ timeout: 15000 });
    });

    test('Flow L: Invalid magic link does not destroy active user session', async ({ page }) => {
        const auth = new AuthHelper(page);
        
        await auth.login(clientUser.email, clientUser.password);

        await page.goto('/invite/this-is-a-totally-invalid-token-that-does-not-exist');

        await expect(page.locator('text=Dieser Einladungslink ist ungültig oder abgelaufen.')).toBeVisible({ timeout: 15000 });

        await page.goto('/');
        await expect(page.locator('h1:has-text("Willkommen zurück")')).toBeVisible({ timeout: 15000 });
    });

    test('Flow M: Admin cannot see management buttons or delete photos', async ({ page }) => {
        const auth = new AuthHelper(page);
        
        await auth.login(adminUser.email, adminUser.password);

        const galleryLink = page.locator('ul.menu').getByText('Galerien');
        await expect(galleryLink).toBeHidden({ timeout: 15000 });
        
        await auth.logout();
    });
});
