import {expect, test, type Page, type Route} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

test.describe('AI Gallery Defaults — Generate & Preview', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setupAIMocks(page: Page) {
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
        await page.route('**/api/ai/generate-metadata-text', async (route: Route) => {
            await route.fulfill({
                json: { title: 'AI Galerie Titel', description: 'KI generierte Beschreibung für die Galerie', keywords: 'galerie, ki, test', location: 'Wien', detected_city: 'Wien' },
            });
        });
    }

    test('AT-03-E4: opens defaults modal, generates AI preview, and applies suggestion', async ({ page }) => {
        await setupAIMocks(page);

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        const galleryName = `AI Defaults ${Math.random().toString(36).substring(2, 8)}`;
        await galleryHelper.createAndOpenDeliveryGallery(galleryName);

        await page.waitForTimeout(500);

        const metadataButton = page.getByRole('button', { name: 'Vorgaben...' });
        await expect(metadataButton).toBeVisible({ timeout: 10000 });
        await metadataButton.click();

        await expect(page.getByRole('heading', { name: 'Metadaten-Vorgaben' })).toBeVisible({ timeout: 5000 });

        const aiGenerateButton = page.getByRole('button', { name: /KI generieren/ });
        await expect(aiGenerateButton).toBeVisible();
        await aiGenerateButton.click();

        await expect(page.getByRole('heading', { name: 'KI-Vorschlag für Vorgaben' })).toBeVisible({ timeout: 5000 });

        const textarea = page.getByPlaceholder(/z\.B\. Hochzeitsreportage/);
        await expect(textarea).toBeVisible();
        await textarea.fill('Hochzeitsreportage im Wiener Burggarten, Mai 2026');

        const generateButton = page.getByRole('button', { name: 'KI generieren' }).last();
        await expect(generateButton).toBeEnabled();
        await generateButton.click();

        await expect(page.getByText('Vorschlag:')).toBeVisible({ timeout: 8000 });
        await expect(page.getByText('AI Galerie Titel')).toBeVisible();
        await expect(page.getByText('KI generierte Beschreibung für die Galerie')).toBeVisible();
        await expect(page.getByText('galerie, ki, test')).toBeVisible();
        await expect(page.getByText('Ort: Wien')).toBeVisible();

        const applyButton = page.getByRole('button', { name: 'Vorschlag übernehmen' });
        await expect(applyButton).toBeVisible();
        await applyButton.click();

        await expect(page.getByText('KI-Vorschlag für Vorgaben')).toBeHidden({ timeout: 5000 });
    });
});
