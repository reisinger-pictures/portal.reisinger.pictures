import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';



test.describe('Photo Management Workflow (Flows A, B, K, L, M)', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '' };
    let photogUser = { email: '', password: '' };
    let clientUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser( 'admin');
        photogUser = await helper.createIsolatedUser( 'photographer');
        clientUser = await helper.createIsolatedUser( 'client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryNameB = `Flow B Test ${uniqueId()}`;
    const galleryNameA = `Flow A Test ${uniqueId()}`;
    const galleryNameK = `Flow K Expired ${uniqueId()}`;

    test('Flow B: Photographer can delete a photo from detail view', async ({ page }) => {
        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        await auth.login(photogUser.email, photogUser.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryNameB);

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        await page.getByRole('button', { name: 'Bild löschen' }).click();
        
        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();

        await confirmModal.getByRole('button', { name: 'Löschen', exact: true }).click();
        await expect(confirmModal).toBeHidden();

        await expect(page.locator(`h1:has-text("${galleryNameB}")`)).toBeVisible();
        await page.reload();
        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeVisible();
    });

    test('Flow A: Photographer grants transient metadata rights via Magic Link, Client edits, Photographer restores', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        // === PHASE 1: PHOTOGRAPHER PREPARES GALLERY & LINK ===
        await auth.login(photogUser.email, photogUser.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryNameA);

        await page.getByRole('button', { name: 'Vorgaben...' }).click();
        await modal.toggleCheckboxByLabel('Kunden dürfen Metadaten bearbeiten', true);
        await modal.submitModal('Speichern');

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();
        
        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Originaler Titel vom Fotografen');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten gespeichert');
        
        await page.locator('button.btn-ghost:has(.mdi--arrow-left)').click();
        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        
        await modal.toggleCheckboxByLabel('Gast darf Metadaten bearbeiten', true);
        await modal.clickButton('Generieren');
        
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const magicLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await auth.logout();

        // === PHASE 2: GUEST EDITS VIA MAGIC LINK ===
        await page.goto(magicLink);
        await expect(page.locator('h2:has-text("Willkommen")')).toBeVisible();
        
        await page.getByPlaceholder('z.B. Maria Muster').fill('Test Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill('gast@example.com');
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();
        
        await expect(page.locator(`h1:has-text("${galleryNameA}")`)).toBeVisible();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const clientTitleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await expect(clientTitleInput).toHaveValue('Originaler Titel vom Fotografen');
        
        await clientTitleInput.fill('Titel geändert durch den Kunden');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten gespeichert');

        // === PHASE 3: PHOTOGRAPHER RESTORES ===
        await auth.logout();
        await auth.login(photogUser.email, photogUser.password);
        await sidebar.navigateTo('Galerien');
        await page.locator('main').locator('a').filter({ hasText: galleryNameA }).first().click();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input')).toHaveValue('Titel geändert durch den Kunden');

        await page.getByRole('button', { name: 'Historie' }).click();
        const historyModal = page.locator('.modal-open');
        await expect(historyModal.locator('h3:has-text("Änderungshistorie")')).toBeVisible();

        await expect(historyModal.locator('td').filter({ hasText: 'Originaler Titel vom Fotografen' })).toBeVisible();

        await historyModal.getByRole('button', { name: 'Wiederherstellen' }).click();
        const confirmGlobal = page.locator('.modal-global');
        await expect(confirmGlobal).toBeVisible();
        await confirmGlobal.getByRole('button', { name: 'Wiederherstellen' }).click();

        await expect(async () => {
            await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input')).toHaveValue('Originaler Titel vom Fotografen', { timeout: 1000 });
        }).toPass({ timeout: 15000 });
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
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const link = page.locator('main').locator('a').filter({ hasText: galleryNameK }).first();
        await expect(async () => {
            await page.reload();
            await expect(link).toBeVisible({ timeout: 2000 });
            await expect(link).toHaveClass(/line-through/);
        }).toPass({ timeout: 15000 });

        const href = await link.getAttribute('href');
        expect(href).not.toBeNull();

        await auth.logout();

        await page.goto(href as string);
        await expect(page.locator('text=Galerie nicht gefunden oder Zugriff verweigert.')).toBeVisible();
    });

    test('Flow L: Invalid magic link does not destroy active user session', async ({ page }) => {
        const auth = new AuthHelper(page);
        
        await auth.login(clientUser.email, clientUser.password);

        await page.goto('/invite/this-is-a-totally-invalid-token-that-does-not-exist');

        await expect(page.locator('text=Dieser Einladungslink ist ungültig oder abgelaufen.')).toBeVisible();

        await page.goto('/');
        await expect(page.locator('h1:has-text("Willkommen zurück")')).toBeVisible();
    });

    test('Flow M: Admin cannot see management buttons or delete photos', async ({ page }) => {
        const auth = new AuthHelper(page);
        
        await auth.login(adminUser.email, adminUser.password);

        const galleryLink = page.locator('ul.menu').getByText('Galerien');
        await expect(galleryLink).toBeHidden();
        
        await auth.logout();
    });
});