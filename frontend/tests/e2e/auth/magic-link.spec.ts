import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

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
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
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
});