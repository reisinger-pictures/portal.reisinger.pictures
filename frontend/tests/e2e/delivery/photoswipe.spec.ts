import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import {FormHelper} from '../helpers/FormHelper';



test.describe('PhotoSwipe & Lightbox UI', () => {
    let helper: E2ESessionHelper;
    let testUser = {email: '', password: ''};

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryName = `Lightbox Test ${uniqueId()}`;

    test.beforeEach(async ({page}) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Lightbox opens and displays custom IPTC captions', async ({page}) => {
        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const galLink = page.locator('main').locator('a').filter({hasText: galleryName}).first();
        await expect(galLink).toBeVisible();
        await galLink.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const titleInput = page.locator('div.form-control').filter({hasText: 'Titel'}).locator('input');
        await titleInput.fill('Episches Testbild');

        const descInput = page.locator('div.form-control').filter({hasText: 'Beschreibung'}).locator('textarea');
        await descInput.fill('Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.');

        await page.getByRole('button', {name: 'Speichern'}).click();
        await expect(page.getByRole('button', {name: 'Speichern'})).toBeEnabled();

        await page.locator('main button:has(span.mdi--arrow-left)').click();

        // BEST PRACTICE: Authentic user behavior. Force a fresh fetch to see updated metadata.
        await page.reload();

        const image = page.locator('a.pswp-item img').first();
        await image.scrollIntoViewIfNeeded();
        await expect(image).toBeVisible({ timeout: 15000 });

        await expect(page.locator('a.pswp-item').first()).toHaveAttribute('data-title', 'Episches Testbild');

        await page.locator('a.pswp-item').first().click();

        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();

        await expect(lightbox.locator('text=Episches Testbild')).toBeVisible();
        await expect(lightbox.locator('text=Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.')).toBeVisible();
        await expect(lightbox.locator('.pswp__custom-caption small')).toContainText('©');

        await expect(page.locator('button.pswp__button--close')).toBeVisible({ timeout: 5000 });
        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(lightbox).toBeHidden();
        }).toPass({ timeout: 15000 });
    });
});