import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupE2EData(request);
    await E2EUserHelper.cleanupTrackedUsers(request);
});

test.describe.serial('Communication Workflow (Flows E, F)', () => {
    let photogUser = { email: '', password: '' };
    const galleryName = `Comm Test ${Math.random().toString(36).substring(2, 10)}`;
    let galleryId = '';

    test.beforeAll(async ({ request }) => {
        photogUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    test.beforeEach(async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);

        await auth.login(photogUser.email, photogUser.password);

        if (!galleryId) {
            await sidebar.openNewGalleryModal();
            await modal.fillInputByLabel('Name der Galerie', galleryName);
            await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');

            const saveResponse = page.waitForResponse(r => r.url().includes('/management/galleries') && r.request().method() === 'POST');
            await modal.clickButton('Speichern');
            const res = await saveResponse;
            const data = await res.json();
            galleryId = data.gallery.id;

            await expect(modal.activeModal).toBeHidden();
        }

        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible();
        await galLink.click();
    });

    test('Flow F: Email button is disabled without subscribers, enabled with opt-in and supports preview', async ({ page, request }) => {
        const modal = new ModalHelper(page);
        const mailpit = new MailpitHelper(request);

        const emailBtn = page.getByRole('button', { name: 'E-Mail senden...' });

        await expect(emailBtn).toBeDisabled();
        await expect(emailBtn).toHaveAttribute('title', /Keine Empfänger/);

        const client = await E2EUserHelper.createIsolatedUser(request, 'client', {
            assignGalleryId: galleryId,
            wantsNotifications: true
        });

        // Opt-In passierte im Hintergrund über die API. Das UI muss neu geladen werden.
        await page.reload();
        await expect(emailBtn).toBeEnabled();

        await emailBtn.click();
        await modal.fillInputByLabel('Betreff', 'Deine Bilder sind da!');
        await modal.activeModal.locator('textarea').fill('Hallo {user_name}, hier der Link: {link}');

        // DoD: Checkbox Test (Vorschau-Funktion)
        await modal.toggleCheckboxByLabel('Vorschau anzeigen', true);
        await expect(modal.activeModal.locator('.prose')).toContainText('Hallo Max Mustermann');
        await modal.toggleCheckboxByLabel('Vorschau anzeigen', false);

        await modal.clickButton('Nachricht Senden');

        await expect(modal.activeModal).toBeHidden();
        await expect(page.locator('.toast')).toContainText('E-Mails versendet');

        const mail = await mailpit.getMessageForEmail(client.email);
        expect(mail.Subject).toContain('Deine Bilder sind da!');
        expect(mail.HTML).toContain('Hallo E2E client');
    });

    test('Flow E: Photographer can generate and revoke an invite link', async ({ page }) => {
        const modal = new ModalHelper(page);
        await page.getByRole('button', { name: 'Einladungslink...' }).click();

        await modal.clickButton('Generieren');
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();

        const tableRow = modal.activeModal.locator('table tbody tr').first();
        await tableRow.locator('button[title="Widerrufen"]').click();

        const confirmModal = page.locator('.modal-global');
        await confirmModal.getByRole('button', { name: 'Widerrufen' }).click();

        await expect(page.locator('td').filter({ hasText: 'Noch keine Einladungen' })).toBeVisible();
        await modal.clickButton('Schließen');
    });
});