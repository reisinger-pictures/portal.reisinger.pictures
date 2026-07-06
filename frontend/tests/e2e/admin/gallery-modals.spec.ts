import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Gallery & Group Modals Roundtrip', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Photographer can save and restore all boolean flags via Group Modal', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien');

        const uniqueName = `Group Flags ${Math.random().toString(36).substring(2, 8)}`;

        // 1. Group erstellen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await form.fillGroupModal({
            name: uniqueName,
            freeDownload: true,
            editorialOnly: true,
            hidden: true
        });
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.group?.id) helper.trackGroup(resData.group.id);

        await expect(page.locator('.toast')).toContainText('Ordner erfolgreich erstellt');

        await page.reload();
        await page.waitForSelector('summary', { timeout: 10000 });
        await page.locator('summary').filter({ hasText: uniqueName }).locator('button').filter({ has: page.locator('span.mdi--pencil') }).click();
        
        // Assert Modal UI is populated
        await modal.assertCheckboxByLabel('Im Frontend verstecken', true);
        await modal.assertCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', true);
        await modal.assertCheckboxByLabel('Kostenlosen Download erlauben', true);
    });

    test('Photographer can save and restore all boolean flags via Gallery Modal', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien');

        const uniqueName = `Gallery Flags ${Math.random().toString(36).substring(2, 8)}`;

        // 1. Gallery erstellen
        await page.getByRole('button', { name: 'Neue Galerie' }).click();
        await form.fillGalleryModal({
            name: uniqueName,
            type: 'Delivery (Downloads)',
            freeDownload: true,
            editorialOnly: true,
            hidden: true,
            live: true
        });
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await expect(page.locator('.toast')).toContainText('Galerie erfolgreich erstellt');

        // 2. Roundtrip Check
        await page.reload();
        await page.locator('a').filter({ hasText: uniqueName }).locator('..').locator('button[data-tip="Bearbeiten"]').click();
        
        // Assert Modal UI is populated
        await modal.assertCheckboxByLabel('Im Frontend verstecken', true);
        await modal.assertCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', true);
        await modal.assertCheckboxByLabel('Kostenlosen Download erlauben', true);
        await modal.assertCheckboxByLabel('LIVE Galerie', true);
    });
});
