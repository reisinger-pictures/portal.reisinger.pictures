import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Photographer Team Access Workflow', () => {
    let helper: E2ESessionHelper;
    let photogA = { email: '', password: '', id: '' };
    let photogB = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogA = await helper.createIsolatedUser('photographer');
        photogB = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        await helper.teardown();
    });

    test('Test 5: Photog B can access and upload to an OPEN gallery created by Photog A', async ({ page }) => {
        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);
        const sidebar = new SidebarHelper(page);

        const galleryName = `Open Team Gallery ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(photogA.email, photogA.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await auth.logout();

        await auth.login(photogB.email, photogB.password);
        await sidebar.navigateTo('Galerien');
        
        const galLink = page.locator('main').getByText(galleryName, { exact: true }).first();
        await expect(galLink).toBeVisible();
        await galLink.click();

        await upload.uploadSampleImage();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible();
    });

    test('Test 6: Photog B CANNOT access a RESTRICTED gallery created by Photog A', async ({ page }) => {
        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const sidebar = new SidebarHelper(page);

        const galleryName = `Restricted Team Gallery ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(photogA.email, photogA.password);
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await galleryHelper.setPhotographerTeamAccess('Restriktiv');
        const galleryUrl = page.url();
        await auth.logout();

        await auth.login(photogB.email, photogB.password);
        await sidebar.navigateTo('Galerien');
        
        await expect(page.locator('main').getByText(galleryName)).toBeHidden();

        await page.goto(galleryUrl);
        await expect(page.locator('text=Galerie nicht gefunden')).toBeVisible();
    });
});
