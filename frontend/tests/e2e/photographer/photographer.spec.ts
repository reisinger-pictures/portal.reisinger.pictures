import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('Photographer Core Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Date.now() + '-' + Math.round(Math.random() * 10000);
    const galleryName = `Playwright Workflow ${uniqueId()}`;
    const editedName = `Playwright Edited ${uniqueId()}`;

    let testUser = { email: '', password: '' };
    let isGalleryCreated = false;

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can create a new delivery gallery', async ({ page }) => {
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.submitModal('Speichern');

        const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(link).toBeVisible({ timeout: 15000 });
        isGalleryCreated = true;
    });

    test('Photographer can edit an existing gallery', async ({ page }) => {
        test.skip(!isGalleryCreated, 'Test requires gallery from previous step');

        await sidebar.navigateTo('Galerien');
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        await modal.fillInputByLabel('Name der Galerie', editedName);
        await modal.submitModal('Speichern');

        // FIX: Zurück zur Übersicht navigieren, da sich der Slug geändert hat
        await sidebar.navigateTo('Galerien');
        const newLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await expect(newLink).toBeVisible();
        await newLink.click();

        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();
    });

    test('Photographer can upload an image to the gallery', async ({ page }) => {
        test.skip(!isGalleryCreated, 'Test requires gallery from previous step');

        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();
    });

    test('Photographer sees the new gallery and photo in personal feed on dashboard', async ({ page }) => {
        test.skip(!isGalleryCreated, 'Test requires gallery from previous step');

        await page.goto('/');

        const feedHeader = page.locator('h2:has-text("Deine neuesten Uploads & Galerien")');
        await expect(feedHeader).toBeVisible();
        await expect(page.locator('.space-y-8').first()).toBeVisible();
    });

    test('Photographer can toggle between management and client view using the tab switcher', async ({ page }) => {
        test.skip(!isGalleryCreated, 'Test requires gallery from previous step');

        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();

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
        await expect(page.locator('text=Lade deine Bilder herunter.')).toBeHidden();
    });

    test('Photographer can update profile settings and verify FTP slug in dashboard', async ({ page }) => {
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("Einstellungen")')).toBeVisible();

        const uniqueSuffix = Date.now().toString().slice(-6);
        const newName = `Test Photog ${uniqueSuffix}`;
        const newSlug = `ftp-${uniqueSuffix}`;
        const newCopyright = `© ${newName}`;

        await page.locator('.form-control').filter({ hasText: 'Dein Name' }).locator('input').fill(newName);
        await page.locator('.form-control').filter({ hasText: 'FTP Upload Ordner' }).locator('input').fill(newSlug);
        await page.locator('.form-control').filter({ hasText: 'Standard-Urheber' }).locator('input').fill(newCopyright);

        await page.getByRole('button', { name: 'Profil speichern' }).click();
        const toast = page.locator('.toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Profil aktualisiert');

        await page.goto('/');
        await expect(page.locator('h2:has-text("FTP Inbox")')).toBeVisible();
        await expect(page.locator(`code:has-text("/${newSlug}")`)).toBeVisible();
    });
});