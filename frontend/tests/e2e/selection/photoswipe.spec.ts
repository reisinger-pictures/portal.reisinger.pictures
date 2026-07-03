import { test, expect } from '@playwright/test';
import { NetworkHelper } from '../helpers/NetworkHelper';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('PhotoSwipe in Selection Gallery', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Photographer creates gallery and client interacts with PhotoSwipe', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const network = new NetworkHelper(page);
        
        const galleryName = `Selection Lightbox ${Math.random().toString(36).substring(2, 10)}`;

        // 1. Setup by Photographer
        await auth.login(testUser.email, testUser.password);
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Auswahl (Ratings)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(link).toBeVisible();
        await link.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await form.fillInviteModal({ type: 'personal', name: 'Lightbox Tester' });
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');
        await auth.logout();

        // 2. Client Interaction
        await page.goto(inviteLink);
        await page.getByRole('checkbox', { name: /datenschutzerklärung/i }).check();
        await page.getByRole('button', { name: 'Weiter als Lightbox Tester' }).click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await page.locator('a.pswp-item').first().click();
        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();
        await expect(page.locator('#rating-portal-anchor')).toBeVisible();

        const commentInput = page.locator('#rating-portal-anchor input[type="text"]');
        await commentInput.fill('Episches Bild im Fullscreen!');
        
        const commentResp = network.waitForRating();
        await commentInput.press('Enter');
        await commentResp;
        const ratingBar = page.locator('#rating-portal-anchor .rating');
        await expect(ratingBar).toBeVisible({ timeout: 5000 });
        const star5 = ratingBar.locator('input[type="radio"]').nth(4);
        const rateResponse = network.waitForRating();
        await star5.click();
        await rateResponse;

        await expect(page.locator('#rating-portal-anchor .rating input[type="radio"]').nth(4)).toBeChecked({ timeout: 5000 });
        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(lightbox).toBeHidden();
        }).toPass({ timeout: 15000 });

        await expect(page.locator('.card-body input[type="radio"]').nth(4)).toBeChecked({ timeout: 5000 });
    });
});