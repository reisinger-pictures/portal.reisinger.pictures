import { test, expect } from '@playwright/test';
import { AuthHelper } from './helpers/AuthHelper';
import { SidebarHelper } from './helpers/SidebarHelper';
import { ModalHelper } from './helpers/ModalHelper';
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
        await modal.clickButton('Speichern');

        await expect(page.locator(`text=${galleryName}`).first()).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can edit an existing gallery', async ({ page }) => {
        const galLink = page.locator(`text=${galleryName}`).first();
        await galLink.scrollIntoViewIfNeeded();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        await modal.fillInputByLabel('Name der Galerie', editedName);
        await modal.clickButton('Speichern');
        
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();
    });

    test('Photographer can upload an image to the gallery', async ({ page }) => {
        const editedLink = page.locator(`text=${editedName}`).first();
        await editedLink.scrollIntoViewIfNeeded();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible();

        const fileInput = page.locator('input[type="file"]');
        await expect(fileInput).toBeAttached();
        
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);

        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeHidden({ timeout: 15000 });
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });
    });
});
