import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('G3: Gallery Deletion', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Photographer can delete a gallery from structure view', { tags: ['@feature:photographer:gallery'] }, async ({ page }) => {
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `Delete Test ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.reload();

        const editBtn = page.locator('a').filter({ hasText: galleryName }).locator('..').locator('button[data-tip="Bearbeiten"]');
        await expect(editBtn).toBeVisible({ timeout: 10000 });
        await editBtn.click();

        await modal.clickButton('Löschen');

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Unwiderruflich löschen' }).click();

        await expect(page.locator('.toast')).toContainText('Galerie erfolgreich gelöscht.');

        await sidebar.navigateTo('Galerien');
        await expect(page.locator('a').filter({ hasText: galleryName })).toHaveCount(0);
    });

    test('Photographer can cancel gallery deletion', { tags: ['@feature:photographer:gallery'] }, async ({ page }) => {
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `Cancel Delete ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.reload();

        const editBtn = page.locator('a').filter({ hasText: galleryName }).locator('..').locator('button[data-tip="Bearbeiten"]');
        await expect(editBtn).toBeVisible({ timeout: 10000 });
        await editBtn.click();

        await modal.clickButton('Löschen');

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(page.locator('.modal-global')).toBeHidden({ timeout: 5000 });

        // The GalleryModal is still open after cancelling the delete confirmation;
        // close it so sidebar navigation (which checks for .modal-open === 0) can proceed.
        await modal.closeModal();
        await expect(page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });

        await sidebar.navigateTo('Galerien');
        await expect(page.locator('a').filter({ hasText: galleryName })).toBeVisible({ timeout: 10000 });
    });
});
