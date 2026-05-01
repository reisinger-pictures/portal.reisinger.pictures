import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('Gallery Invite Link Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        await helper.teardown();
    });

    test('End-to-End Anonymous Magic Link Workflow', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        
        const uniqueId = () => Math.random().toString(36).substring(2, 10);
        const galleryName = `Invite Test ${uniqueId()}`;

        // 1. Photographer creates gallery and link
        await auth.login(testUser.email, testUser.password);
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Auswahl (Ratings)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);
        
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await modal.clickButton('Generieren');
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLinkAnon = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');
        await auth.logout();

        // 2. Anonymous Guest redeems link
        await page.goto(inviteLinkAnon);
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();

        const guestEmail = `gast-${uniqueId()}@example.com`;
        await page.getByPlaceholder('z.B. Maria Muster').fill('Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill(guestEmail);
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible();
        
        // 3. Logged-in User redeems link directly
        await auth.logout();
        await auth.login(testUser.email, testUser.password);
        await page.goto(inviteLinkAnon);
        await expect(page).toHaveURL(/.*\/galleries\/.*/);
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
    });

    test('End-to-End Anonymous Magic Link with Password Protection', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        
        const uniqueId = () => Math.random().toString(36).substring(2, 10);
        const galleryName = `Password Test ${uniqueId()}`;
        const password = 'TEST_PASSWORD_REDACTED';

        // 1. Photographer creates gallery WITH password
        await auth.login(testUser.email, testUser.password);
        await sidebar.openNewGalleryModal();
        
        await form.fillGalleryModal({ name: galleryName, type: 'Auswahl (Ratings)' });
        await modal.fillInputByLabel('Passwort (Optional)', password);
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);
        
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await form.fillInviteModal({ type: 'personal', name: 'Secure Guest' });
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');
        await auth.logout();

        // 2. Anonymous Guest redeems link
        await page.goto(inviteLink);
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();

        const pwdInput = page.locator('main').locator('input[type="password"]');
        await expect(pwdInput).toBeVisible();
        await expect(page.locator('text=bitte gib das Galerie-Passwort ein')).toBeVisible();

        // Enter wrong password
        await pwdInput.fill('WrongPassword!');
        await page.getByRole('button', { name: 'Weiter als Secure Guest' }).click();
        await expect(page.locator('.alert-error')).toContainText('Das Galerie-Passwort ist nicht korrekt.');

        // Enter correct password
        await pwdInput.fill(password);
        await page.getByRole('button', { name: 'Weiter als Secure Guest' }).click();

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
    });
});