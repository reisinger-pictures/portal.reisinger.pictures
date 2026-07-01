import {expect, test, type Page, type Route} from '@playwright/test';
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

test.describe('AI Batch Edit — Generation Flow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setupBaseMocks(page: Page) {
        await page.route('**/api/ai/status', async (route: Route) => {
            await route.fulfill({ json: { enabled: true, status: 'available', model: 'gpt-4o' } });
        });
        await page.route('**/api/auth/me', async (route: Route) => {
            const response = await route.fetch();
            if (response.ok()) {
                const json = await response.json();
                json.ai_is_unconfigured = false;
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });
    }

    async function injectPhotosIntoGallery(page: Page, photoCount = 3) {
        await page.route('**/api/galleries/*', async (route: Route) => {
            const url = route.request().url();
            const response = await route.fetch();
            if (response.ok() && url.includes('?page=')) {
                const json = await response.json();
                if (json.photos) {
                    const photos = [];
                    for (let i = 1; i <= photoCount; i++) {
                        photos.push({
                            id: `e2e-photo-${i}`,
                            gallery_id: json.gallery?.id || '',
                            filename: `img${i}.jpg`,
                            lr_uuid: `uuid-${i}`,
                            width: 1920,
                            height: 1080,
                            url: `/photos/img${i}.jpg`,
                            thumb_url: `/thumbs/img${i}.jpg`,
                            rating: 0,
                            comment: '',
                            title: '',
                            description: '',
                            keywords: '',
                            location: '',
                            detected_city: '',
                            iptc_city: '',
                            iptc_country: '',
                        });
                    }
                    json.photos = photos;
                    json.total = photoCount;
                }
                await route.fulfill({ response, json });
            } else {
                await route.continue();
            }
        });
    }

    test('AT-03-E1: single generation without context fills fields with AI data', async ({ page }) => {
        await setupBaseMocks(page);

        await page.route('**/api/ai/generate-metadata', async route => {
            await route.fulfill({
                json: { title: 'AI generierter Titel', description: 'AI Beschreibung', keywords: 'key1, key2', location: 'Wien', detected_city: 'Wien' },
            });
        });

        await page.route('**/api/search/locations*', async route => {
            await route.fulfill({
                json: [{ name: 'Wien', state: 'Wien', country: 'Österreich', iso_country: 'AT' }],
            });
        });

        await page.route('**/api/management/galleries/*', async route => {
            await route.fulfill({ json: { gallery: { default_title: '', default_description: '', default_keywords: '' } } });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Gen ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await injectPhotosIntoGallery(page);

        await page.reload();
        await page.waitForTimeout(1000);

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible({ timeout: 10000 });
        await aiButton.click();

        await expect(page.getByRole('heading', { name: 'KI Batch-Edit' })).toBeVisible();

        const generateButtons = page.getByRole('button', { name: 'KI Generieren' });
        await generateButtons.first().click();

        await expect(page.getByPlaceholder('Titel').first()).toHaveValue('AI generierter Titel', { timeout: 8000 });
        await expect(page.getByPlaceholder('Beschreibung').first()).toHaveValue('AI Beschreibung');
        await expect(page.getByPlaceholder('Keywords').first()).toHaveValue('key1, key2');
        await expect(page.getByPlaceholder('Ort/Gebäude').first()).toHaveValue('Wien');
    });

    test('AT-03-E2: generation with global and specific context passes parameters', async ({ page }) => {
        await setupBaseMocks(page);

        await page.route('**/api/ai/generate-metadata', async route => {
            const body = route.request().postDataJSON();
            expect(body.global_context).toBe('Event X');
            expect(body.specific_context).toBe('Person Y');
            await route.fulfill({
                json: { title: 'Context Title', description: 'Context Desc', keywords: 'ctx, kw', location: 'Berlin', detected_city: 'Berlin' },
            });
        });

        await page.route('**/api/search/locations*', async route => {
            await route.fulfill({
                json: [{ name: 'Berlin', state: 'Berlin', country: 'Deutschland', iso_country: 'DE' }],
            });
        });

        await page.route('**/api/management/galleries/*', async route => {
            await route.fulfill({ json: { gallery: { default_title: '', default_description: '', default_keywords: '' } } });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Ctx ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await injectPhotosIntoGallery(page);

        await page.reload();
        await page.waitForTimeout(1000);

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible({ timeout: 10000 });
        await aiButton.click();

        await expect(page.getByRole('heading', { name: 'KI Batch-Edit' })).toBeVisible();

        const globalInput = page.getByPlaceholder(/z\.B\. Sommerfest/);
        await globalInput.fill('Event X');

        const specificInputs = page.getByPlaceholder(/Spezifischer Bild-Kontext/);
        await specificInputs.first().fill('Person Y');

        const generateButtons = page.getByRole('button', { name: 'KI Generieren' });
        await generateButtons.first().click();

        await expect(page.getByPlaceholder('Titel').first()).toHaveValue('Context Title', { timeout: 8000 });
        await expect(page.getByPlaceholder('Beschreibung').first()).toHaveValue('Context Desc');
    });

    test('AT-03-E3a: "Alle generieren (leere)" fills all empty rows with progress bar', async ({ page }) => {
        await setupBaseMocks(page);

        let generateCallCount = 0;
        await page.route('**/api/ai/generate-metadata', async route => {
            generateCallCount++;
            await route.fulfill({
                json: { title: `Batch Title ${generateCallCount}`, description: 'Batch Desc', keywords: 'batch, kw', location: 'Wien', detected_city: 'Wien' },
            });
        });

        await page.route('**/api/search/locations*', async route => {
            await route.fulfill({
                json: [{ name: 'Wien', state: 'Wien', country: 'Österreich', iso_country: 'AT' }],
            });
        });

        await page.route('**/api/management/galleries/*', async route => {
            await route.fulfill({ json: { gallery: { default_title: '', default_description: '', default_keywords: '' } } });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Batch ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await injectPhotosIntoGallery(page, 3);

        await page.reload();
        await page.waitForTimeout(1000);

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible({ timeout: 10000 });
        await aiButton.click();

        await expect(page.getByRole('heading', { name: 'KI Batch-Edit' })).toBeVisible();

        await page.unroute('**/api/galleries/*');
        await page.getByRole('button', { name: /Alle generieren/ }).click();

        await expect(page.locator('.progress-primary')).toBeVisible({ timeout: 5000 });

        await expect(page.getByPlaceholder('Titel').first()).toHaveValue(/Batch Title/, { timeout: 15000 });
        await expect.poll(() => generateCallCount, { timeout: 10000 }).toBe(3);
    });

    test('AT-03-E3b: save persists metadata and shows success toast', async ({ page }) => {
        await setupBaseMocks(page);

        await page.route('**/api/ai/generate-metadata', async route => {
            await route.fulfill({
                json: { title: 'Save Title', description: 'Save Desc', keywords: 'save, kw', location: 'Wien', detected_city: 'Wien' },
            });
        });

        await page.route('**/api/search/locations*', async route => {
            await route.fulfill({
                json: [{ name: 'Wien', state: 'Wien', country: 'Österreich', iso_country: 'AT' }],
            });
        });

        await page.route('**/api/management/galleries/*', async route => {
            await route.fulfill({ json: { gallery: { default_title: '', default_description: '', default_keywords: '' } } });
        });

        let metaSaved = false;
        await page.route('**/api/photos/*/meta', async route => {
            if (route.request().method() === 'PUT') {
                metaSaved = true;
                await route.fulfill({ json: { success: true } });
            } else {
                await route.continue();
            }
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Save ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await injectPhotosIntoGallery(page);

        await page.reload();
        await page.waitForTimeout(1000);

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible({ timeout: 10000 });
        await aiButton.click();

        await expect(page.getByRole('heading', { name: 'KI Batch-Edit' })).toBeVisible();

        const generateButtons = page.getByRole('button', { name: 'KI Generieren' });
        await generateButtons.first().click();
        await expect(page.getByPlaceholder('Titel').first()).toHaveValue('Save Title', { timeout: 8000 });

        const saveButtons = page.getByRole('button', { name: 'Speichern' });
        await saveButtons.first().click();

        await expect(page.locator('.toast').filter({ hasText: 'Gespeichert!' })).toBeVisible({ timeout: 8000 });
        expect(metaSaved).toBe(true);
    });

    test('AT-03-E3c: error toast shown when AI generation fails with 502', async ({ page }) => {
        await setupBaseMocks(page);

        await page.route('**/api/ai/generate-metadata', async route => {
            await route.fulfill({ status: 502, json: { error: 'AI API Error: 502' } });
        });

        await page.route('**/api/management/galleries/*', async route => {
            await route.fulfill({ json: { gallery: { default_title: '', default_description: '', default_keywords: '' } } });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Err ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);
        await injectPhotosIntoGallery(page);

        await page.reload();
        await page.waitForTimeout(1000);

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible({ timeout: 10000 });
        await aiButton.click();

        await expect(page.getByRole('heading', { name: 'KI Batch-Edit' })).toBeVisible();

        const generateButtons = page.getByRole('button', { name: 'KI Generieren' });
        await generateButtons.first().click();

        await expect(page.locator('.toast').filter({ hasText: /Fehler bei der KI Generierung/ })).toBeVisible({ timeout: 8000 });
    });
});
