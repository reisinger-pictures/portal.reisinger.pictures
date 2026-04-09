import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { FormHelper } from '../helpers/FormHelper';

// 🧹 Clean-Up Hook: Räumt alle isoliert erstellten Galerien auf, bevor der E2E-User gelöscht wird.


test.describe('Photographer Core Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test.beforeEach(async ({ page }) => {
        const auth = new AuthHelper(page);
        // Login für jeden parallelen Worker/Test sicherstellen
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can create a new delivery gallery', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `Create Test ${Math.random().toString(36).substring(2, 10)}`;

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        await modal.submitModal('Speichern');

        await expect(async () => {
            const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
            if (!(await link.isVisible())) await page.reload();
            await expect(link).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 15000 });
    });

    test('Photographer can edit an existing gallery', async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        const modal = new ModalHelper(page);
        const sidebar = new SidebarHelper(page);
        
        const galleryName = `To Edit ${Math.random().toString(36).substring(2, 10)}`;
        const editedName = `Edited ${Math.random().toString(36).substring(2, 10)}`;

        // Precondition isoliert herstellen
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        // Warten, bis React Hook Form den State gesetzt hat (verhindert Überschreiben des Inputs durch React)
        const nameInput = modal.activeModal.locator('.form-control').filter({ hasText: 'Name der Galerie' }).locator('input');
        await expect(nameInput).toHaveValue(galleryName);
        await nameInput.fill(editedName);
        await modal.submitModal('Speichern');

        await sidebar.navigateTo('Galerien');
        const newLink = page.locator('main').getByText(editedName).first();
        await expect(newLink).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can upload an image and sees it in personal feed', async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);
        const sidebar = new SidebarHelper(page);
        const galleryName = `Upload Test ${Math.random().toString(36).substring(2, 10)}`;

        // Precondition
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        
        // Upload Workflow testen
        await upload.uploadSampleImage();

        // Feed prüfen
        await sidebar.navigateTo('Dashboard');
        const feedHeader = page.locator('h2:has-text("Deine neuesten Uploads & Galerien")');
        await expect(feedHeader).toBeVisible();
        await expect(page.locator('.space-y-8').first()).toBeVisible();
    });

    test('Photographer can toggle between management and client view', async ({ page }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `Toggle Test ${Math.random().toString(36).substring(2, 10)}`;

        // Precondition
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        const inviteBtn = page.getByRole('button', { name: 'Einladungslink...' });
        await expect(inviteBtn).toBeVisible();

        const clientTab = page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' });
        await expect(clientTab).toBeVisible();
        await clientTab.click();

        await expect(page).toHaveURL(/.*\?view=client/);
        await expect(inviteBtn).toBeHidden();

        const managementTab = page.locator('button[role="tab"]').filter({ hasText: 'Verwaltung' });
        await managementTab.click();

        await expect(page).toHaveURL(/.*\?view=management/);
        await expect(inviteBtn).toBeVisible();
    });

    test('Photographer can update profile settings and verify FTP slug', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        
        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const newName = `Test Photog ${uniqueSuffix}`;
        const newSlug = `ftp-${uniqueSuffix}`;
        const newCopyright = `© ${newName}`;

        await sidebar.navigateTo('Mein Profil');
        await expect(page.locator('h1:has-text("Mein Profil")')).toBeVisible();

        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        await form.fillProfileForm({ name: newName, ftpSlug: newSlug, copyright: newCopyright });

        await page.getByRole('button', { name: 'Profil speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Profil aktualisiert');

        await sidebar.navigateTo('Dashboard');
        await page.reload();
        await expect(page.locator('h2:has-text("FTP Inbox")')).toBeVisible();
        await expect(page.locator(`code:has-text("/${newSlug}")`)).toBeVisible();
    });
});
