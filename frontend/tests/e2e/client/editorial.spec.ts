import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {FormHelper} from '../helpers/FormHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

test.describe('Editorial Only UI & Checkout Constraints', () => {
    let helper: E2ESessionHelper;
    let photogUser = {email: '', password: '', id: ''};
    let clientUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        clientUser = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Editorial flag is inherited and blocks commercial licenses in UI', async ({page}) => {
        const auth = new AuthHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const upload = new UploadHelper(page);

        const galleryName = `Editorial Test ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(photogUser.email, photogUser.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Galerien');

        await page.getByRole('button', {name: 'Neue Galerie'}).click();
        await form.fillGalleryModal({name: galleryName, type: 'Delivery (Downloads)', editorialOnly: true});
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        const galLink = page.locator('main').getByText(galleryName, { exact: true }).first();
        await expect(async () => {
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            await galLink.click();
        }).toPass({ timeout: 15000 });

        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();

        const editorialContainer = page.locator('.form-control').filter({hasText: 'Nur für redaktionelle Nutzung'});
        const editorialCheckbox = editorialContainer.locator('input[type="checkbox"]');
        await expect(editorialCheckbox).toBeChecked();
        await expect(editorialCheckbox).toBeDisabled();

        const adminToken = helper.getAdminToken();
        await page.request.post(`/api/management/galleries/${resData.gallery.id}/sync-access`, {
            data: {user_id: clientUser.id, action: 'attach'},
            headers: {'Cookie': adminToken, 'Accept': 'application/json'}
        });

        await auth.logout();

        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').getByText(galleryName).first().click();

        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});

        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();

        await expect(page.locator('label').filter({hasText: 'Werbung / Kampagne'})).toBeHidden();
        await expect(page.locator('label').filter({hasText: 'Tageszeitungen / Zeitschriften'})).toBeVisible();
    });
});
