import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {FormHelper} from '../helpers/FormHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

test.describe('Selection rating regressions', () => {
    test('Guest rating remains read-only on a real public selection gallery', {tag: ['@regression', '@feature:selection']}, async ({page}) => {
        const helper = new E2ESessionHelper(page.request);
        const photographer = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);

        try {
            await auth.login(photographer.email, photographer.password);

            const sidebar = new SidebarHelper(page);
            const modal = new ModalHelper(page);
            const form = new FormHelper(page, modal);
            const galleryName = `Rating Regression ${Math.random().toString(36).substring(2, 10)}`;

            await sidebar.openNewGalleryModal();
            await form.fillGalleryModal({
                name: galleryName,
                type: 'Auswahl (Ratings)',
                visibility: 'Öffentlich (Für alle sichtbar)',
            });
            const result = await modal.submitModal('Speichern');
            if (!result?.gallery?.id) throw new Error('Selection gallery was created without an id');
            helper.trackGallery(result.gallery.id);

            const main = page.getByRole('main');
            const galleryLink = main.getByRole('link', {name: new RegExp(galleryName)});
            await expect(galleryLink).toBeVisible({timeout: 15000});
            await galleryLink.click();
            await expect(main.getByRole('heading', {name: galleryName})).toBeVisible();

            const upload = new UploadHelper(page);
            await upload.uploadSampleImage();
            const galleryUrl = page.url();
            const photoLink = main.getByRole('link').filter({has: main.getByRole('img')}).first();
            await expect(photoLink).toBeVisible({timeout: 15000});

            await auth.logout();
            await page.goto(galleryUrl);
            await expect(main.getByRole('heading', {name: galleryName})).toBeVisible();

            let guestRatingRequests = 0;
            page.on('request', request => {
                if (/\/api\/photos\/[^/]+\/rate(?:\?|$)/.test(request.url())) {
                    guestRatingRequests += 1;
                }
            });

            await photoLink.click();
            await expect(page.getByRole('button', {name: /close/i})).toBeVisible();
            await page.keyboard.press('5');
            await page.waitForTimeout(500);
            expect(guestRatingRequests).toBe(0);
            await page.getByRole('button', {name: /close/i}).click();
        } finally {
            await helper.teardown();
        }
    });
});

// Failed-request rollback and 401 authentication transitions are pure client
// behavior and remain covered by SelectionView/useGallery Vitest regressions;
// this E2E owns the real guest/read-only boundary without an internal API mock.
