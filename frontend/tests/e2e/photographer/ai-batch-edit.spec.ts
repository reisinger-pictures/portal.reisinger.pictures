import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

test.describe('AI Batch Edit Modal (Server-Side)', () => {
    let helper: E2ESessionHelper;
    let testUser = {email: '', password: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('shows AI Batch-Edit button for photographer in delivery gallery', async ({page}) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({json: {enabled: true, model: 'gpt-4o'}});
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = false;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(`AI Test ${Math.random().toString(36).substring(2, 8)}`);

        const aiButton = page.getByRole('button', {name: 'KI Batch-Edit'});
        await expect(aiButton).toBeVisible();
    });

    test('opens AI Batch-Edit modal when button is clicked', async ({page}) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({json: {enabled: true, model: 'gpt-4o'}});
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = false;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(`AI Test ${Math.random().toString(36).substring(2, 8)}`);

        const aiButton = page.getByRole('button', {name: 'KI Batch-Edit'});
        await aiButton.click();

        await expect(page.getByRole('heading', {name: 'KI Batch-Edit'})).toBeVisible();
    });

    test('hides AI button when AI is unconfigured', async ({page}) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({json: {enabled: false, model: ''}});
        });

        await page.route('**/api/auth/me', async route => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = true;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(`AI Test ${Math.random().toString(36).substring(2, 8)}`);

        await expect(page.getByRole('button', {name: 'KI Batch-Edit'})).toBeHidden();
    });
});
