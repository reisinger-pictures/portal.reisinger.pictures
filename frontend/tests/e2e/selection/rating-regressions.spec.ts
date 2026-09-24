import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {FormHelper} from '../helpers/FormHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

test.describe('Selection rating regressions', () => {
    test('Private selection gallery blocks anonymous access and allows invited guest rating', {tag: ['@regression', '@feature:selection']}, async ({page}) => {
        const helper = new E2ESessionHelper(page.request);
        const photographer = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);

        try {
            await auth.login(photographer.email, photographer.password);

            const sidebar = new SidebarHelper(page);
            const modal = new ModalHelper(page);
            const form = new FormHelper(page, modal);
            const galleryName = `Rating Regression ${Math.random().toString(36).substring(2, 10)}`;
            const guestName = 'Rating Regression Guest';

            await sidebar.openNewGalleryModal();
            await form.fillGalleryModal({
                name: galleryName,
                type: 'Auswahl (Ratings)',
            });

            // Selection galleries are a private-only workflow. The disabled
            // control is the product contract, not a transient form state.
            const visibility = modal.activeModal
                .locator('.form-control')
                .filter({hasText: 'Sichtbarkeit'})
                .locator('select');
            await expect(visibility).toBeDisabled();
            await expect(visibility).toHaveValue('false');

            const result = await modal.submitModal('Speichern');
            const galleryId = result?.gallery?.id;
            const galleryPath = result?.gallery?.full_path;
            if (!galleryId || typeof galleryPath !== 'string') {
                throw new Error('Selection gallery was created without an id or client path');
            }
            helper.trackGallery(galleryId);

            const main = page.getByRole('main');
            const galleryLink = main.getByRole('link', {name: new RegExp(galleryName)});
            await expect(galleryLink).toBeVisible({timeout: 15000});
            await galleryLink.click();
            await expect(main.getByRole('heading', {name: galleryName})).toBeVisible();

            const upload = new UploadHelper(page);
            await upload.uploadSampleImage();

            await main.getByRole('button', {name: 'Einladungslink...'}).click();
            await form.fillInviteModal({type: 'personal', name: guestName});
            await modal.clickButton('Generieren');
            await expect(main.getByText('Erfolgreich generiert!')).toBeVisible();
            const inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
            await modal.clickButton('Schließen');

            let guestRatingRequests = 0;
            page.on('request', request => {
                if (/\/api\/photos\/[^/]+\/rate(?:\?|$)/.test(request.url())) {
                    guestRatingRequests += 1;
                }
            });

            await auth.logout();
            await page.goto(`/${galleryPath}`);
            await expect(main.getByRole('heading', {name: galleryName})).toHaveCount(0);
            expect(guestRatingRequests).toBe(0);

            // Redeem a real invite before exercising the rating UI. This keeps
            // the private-access boundary separate from the invited-guest path.
            await page.goto(inviteLink);
            await expect(main.getByRole('heading', {name: 'Willkommen zur Fotoauswahl'})).toBeVisible();
            await main.getByRole('checkbox', {name: /datenschutzerklärung/i}).check();
            await main.getByRole('button', {name: `Weiter als ${guestName}`}).click();
            await expect(main.getByRole('heading', {name: galleryName})).toBeVisible();

            const photoLink = main.getByRole('link').filter({has: main.getByRole('img')}).first();
            await expect(photoLink).toBeVisible({timeout: 15000});
            await photoLink.click();

            const lightbox = page.locator('.pswp');
            await expect(lightbox.getByRole('button', {name: /close/i})).toBeVisible();
            const ratingResponse = page.waitForResponse(response =>
                /\/api\/photos\/[^/]+\/rate(?:\?|$)/.test(response.url()) && response.request().method() === 'POST',
            );
            await page.keyboard.press('5');
            expect((await ratingResponse).ok()).toBeTruthy();
            expect(guestRatingRequests).toBe(1);
            await lightbox.getByRole('button', {name: /close/i}).click();
        } finally {
            await helper.teardown();
        }
    });
});

// The unauthenticated keyboard boundary remains covered by SelectionView
// Vitest coverage; this E2E owns the real private-gallery and invite flow
// without an internal API mock.
