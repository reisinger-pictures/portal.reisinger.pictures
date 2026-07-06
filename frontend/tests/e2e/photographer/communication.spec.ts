import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';

import { GalleryHelper } from '../helpers/GalleryHelper';
import {SidebarHelper} from "../helpers/SidebarHelper";
import {ModalHelper} from "../helpers/ModalHelper";
import {NetworkHelper} from "../helpers/NetworkHelper";

test.describe('Communication Workflow (Flows E, F)', () => {
    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test.beforeEach(async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(photogUser.email, photogUser.password);
    });

    test('Flow F: Email button is disabled without subscribers, enabled with opt-in and supports preview', { tag: ['@feature:photographer:communication'] }, async ({ page, request }) => {
        const galleryHelper = new GalleryHelper(page, helper);
        
        const galleryName = `Comm F ${Math.random().toString(36).substring(2, 10)}`;
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        if (!galleryId) throw new Error('Gallery ID not available');
        
        const emailBtn = page.getByRole('button', { name: 'E-Mail senden...' });
        await expect(emailBtn).toBeDisabled();
        await expect(emailBtn).toHaveAttribute('title', /Keine Empfänger/);

        // Create client user and attach to gallery via API
        const clientUser = await helper.createIsolatedUser('client');
        await request.post(`/api/management/galleries/${galleryId}/sync-access`, {
            data: { user_id: clientUser.id, action: 'attach' },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });

        // Login as client, toggle email opt-in ON
        const auth = new AuthHelper(page);
        await auth.logout();
        await auth.login(clientUser.email, clientUser.password);

        await page.locator('main').getByText(galleryName).first().click();
        await expect(page.getByRole('heading', { name: galleryName }).first()).toBeVisible();

        const optInToggle = page.locator('label').filter({ hasText: 'E-Mail Updates' }).locator('input[type="checkbox"]');
        const network = new NetworkHelper(page);
        const optInResponse = network.waitForOptIn();
        await optInToggle.click();
        await optInResponse;

        // Login back as photographer
        await auth.logout();
        await auth.login(photogUser.email, photogUser.password);

        // Navigate to gallery list
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Galerien');

        // Navigate to gallery and reload to clear SWR cache
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible();
        await galLink.scrollIntoViewIfNeeded();
        await galLink.evaluate(el => (el as HTMLElement).click());
        await expect(page.getByRole('heading', { name: galleryName })).toBeVisible();
        await page.reload();

        // Assert email button is now enabled
        await expect(emailBtn).toBeEnabled();
        await expect(emailBtn).not.toHaveAttribute('title', /Keine Empfänger/);

        // Open email modal
        await emailBtn.click();
        const emailModal = page.locator('.modal-open').last();
        await expect(emailModal).toBeVisible();
        await expect(emailModal.locator('h3')).toContainText('Nachricht an Kunden senden');

        // Fill email form
        await emailModal.locator('input[type="text"]').first().fill('Test Subject');
        const editor = emailModal.locator('.ProseMirror').first();
        await editor.click();
        await editor.fill('Test body content');

        // Send and assert success
        await emailModal.getByRole('button', { name: 'Nachricht Senden' }).click();
        await expect(page.locator('.toast')).toContainText('E-Mails versendet');
    });

    test('Flow E: Photographer can generate and revoke an invite link', { tag: ['@feature:photographer:communication'] }, async ({ page }) => {
        const modal = new ModalHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        
        const galleryName = `Comm E ${Math.random().toString(36).substring(2, 10)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        await page.getByRole('button', { name: 'Einladungslink...' }).click();

        await modal.clickButton('Generieren');
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();

        const tableRow = modal.activeModal.locator('table tbody tr').first();
        await tableRow.locator('button[title="Widerrufen"]').click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Widerrufen' }).click();

        await expect(page.locator('td').filter({ hasText: 'Noch keine Einladungen' })).toBeVisible();
        await modal.clickButton('Schließen');
    });
});