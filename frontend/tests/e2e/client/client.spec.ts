import { test, expect } from '@playwright/test';
import { NetworkHelper } from '../helpers/NetworkHelper';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';

// 🧹 Clean-Up Hook: Räumt alle isoliert erstellten Galerien auf, bevor der E2E-User gelöscht wird.


test.describe('Client Selection Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('End-to-End Selection Flow: Create, Invite, Redeem, DAU-Protect, Rate and Filter', { tags: ['@smoke', '@feature:client'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const upload = new UploadHelper(page);
        const network = new NetworkHelper(page);

        const uniqueId = Math.random().toString(36).substring(2, 10);
        const galleryName = `E2E Selection ${uniqueId}`;

        // --- PHASE 1: FOTOGRAF BEREITET VOR ---
        await auth.login(testUser.email, testUser.password);
        
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Auswahl (Ratings)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);
        
        const galLink = page.locator('main').getByText(galleryName, { exact: true }).first();
        await expect(async () => {
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            await galLink.click();
        }).toPass({ timeout: 15000 });
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await form.fillInviteModal({ type: 'personal', name: 'Test Client' });
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');

        // Fotograf loggt sich aus
        await auth.logout();

        // --- PHASE 2: GAST LÖST EIN UND BEWERTET ---
        expect(inviteLink).not.toBe('');
        await page.goto(inviteLink);
        
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        await page.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();

        // DAU Protection prüfen (Rechtsklick & Drag)
        const image = page.locator('a.pswp-item img').first();
        await image.scrollIntoViewIfNeeded();
        await expect(image).toBeVisible({ timeout: 15000 });

        const isPrevented = await image.evaluate((el) => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        expect(isPrevented).toBe(true);
        await expect(image).toHaveAttribute('draggable', 'false');

        // --- PHASE 3: GAST FILTERT UND NUTZT PHOTOSWIPE ---
        const ratePromise = network.waitForRating();
        await page.locator('main input[type="radio"]').nth(4).click();
        await ratePromise;

        // Filter: "Neu (Unbewertet)"
        await page.getByRole('button', { name: 'Neu', exact: true }).click();
        await expect(page.locator('a.pswp-item')).toHaveCount(0);

        // Filter: "Meine Favoriten"
        await page.getByRole('button', { name: 'Favoriten', exact: true }).click();
        await expect(page.locator('a.pswp-item')).toHaveCount(1);

        // PhotoSwipe aufrufen
        await page.locator('a.pswp-item').first().click();
        await expect(page.locator('.pswp')).toBeVisible();
        
        await expect(async () => {
            await expect(page.locator('button.pswp__button--close')).toBeVisible({ timeout: 3000 });
            await page.locator('button.pswp__button--close').click();
            await expect(page.locator('.pswp')).toBeHidden({ timeout: 10000 });
        }).toPass({ timeout: 15000 });
    });
});
