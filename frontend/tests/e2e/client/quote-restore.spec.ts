import {test, expect} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {UploadHelper} from '../helpers/UploadHelper';

test.describe('Quote Cart Restore Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = {email: '', password: '', id: ''};
    let photographerUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('client');
        photographerUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Navigating with quote_token fetches real data, populates the cart, and cleans URL', {tag: ['@feature:client:quote', '@regression']}, async ({page, request}) => {
        test.setTimeout(120000);
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        const upload = new UploadHelper(page);

        // Build the quote fixture through the approved gallery/upload flow.
        // The quote-link endpoint then signs the real photo ids; no synthetic
        // ids are used in this cart-restore test.
        await auth.login(photographerUser.email, photographerUser.password);
        const galleryName = `Quote Restore ${Math.random().toString(36).substring(2, 10)}`;
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(
            galleryName,
            'Öffentlich (Für alle sichtbar)',
        );
        expect(galleryId).toBeTruthy();
        await upload.uploadSampleImage();
        await upload.uploadSampleImage();
        const gallerySlug = new URL(page.url()).pathname.split('/').filter(Boolean).pop();
        expect(gallerySlug).toBeTruthy();
        await auth.logout();

        const detailRes = await request.get(`/api/galleries/${gallerySlug}`, {
            headers: {'Cookie': helper.getAdminToken(), Accept: 'application/json'},
        });
        expect(detailRes.ok(), await detailRes.text()).toBeTruthy();
        const detail = await detailRes.json() as {photos?: Array<{id?: string}>};
        const photoIds = (detail.photos ?? []).map(photo => photo.id).filter((id): id is string => Boolean(id));
        expect(photoIds.length).toBeGreaterThanOrEqual(2);

        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: {photo_ids: photoIds.slice(0, 2), custom_price: 150000},
            headers: {Cookie: helper.getAdminToken(), Accept: 'application/json', 'Content-Type': 'application/json'},
        });
        expect(quoteRes.ok(), await quoteRes.text()).toBeTruthy();
        const quoteData = await quoteRes.json() as {link?: string};
        const quoteToken = quoteData.link?.split('quote_token=')[1];
        expect(quoteToken).toBeTruthy();

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Warenkorb');
        await page.evaluate((token) => {
            const url = new URL(window.location.href);
            url.searchParams.set('quote_token', token as string);
            window.history.pushState({}, '', url.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }, quoteToken);

        const toast = page.locator('.toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Angebot aus Link wiederhergestellt.');
        await expect(page.getByRole('button', {name: 'Entfernen'})).toHaveCount(2);
        await expect(page.locator('.text-3xl.font-mono.text-primary')).toHaveText('1500.00 €');
        await expect(page).toHaveURL(/.*\/cart$/);
    });
});
