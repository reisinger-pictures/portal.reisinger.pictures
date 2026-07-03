import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('G1: FTP Inbox Feature', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('FTP Inbox is visible for photographers on Dashboard', async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.locator('main');
        await expect(main.locator('h2', { hasText: 'FTP Inbox' })).toBeVisible();
        await expect(main.locator('code.font-bold.font-mono')).toBeVisible();
    });

    test('FTP Inbox is NOT visible for non-photographer roles', async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('client');
        const auth = new AuthHelper(page);

        await auth.login(testUser.email, testUser.password);
        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });

        await expect(page.locator('h2:has-text("FTP Inbox")')).toHaveCount(0);
    });

    test('Photographer can set and remove target gallery in FTP Inbox', async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `FTP Target ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.reload();
        await sidebar.navigateTo('Dashboard');

        const ftpCard = page.locator('main').locator('.card').filter({ hasText: 'FTP Inbox' });
        const select = ftpCard.locator('select');
        await expect(select).toBeVisible();

        const optionValue = await select.locator('option').filter({ hasText: galleryName }).getAttribute('value');
        await select.selectOption(optionValue!);

        const setzenBtn = ftpCard.getByRole('button', { name: 'Setzen' });
        await expect(setzenBtn).toBeEnabled();
        await setzenBtn.click();

        await expect(ftpCard.locator('text=' + galleryName).first()).toBeVisible({ timeout: 5000 });

        await ftpCard.locator('button[title="Zuordnung aufheben"]').click();

        await expect(ftpCard.locator('select')).toBeVisible({ timeout: 5000 });
    });
});
