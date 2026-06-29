import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';

test.describe('AI Batch Edit Modal (Server-Side)', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('shows AI Batch-Edit button for photographer in delivery gallery', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: true, model: 'gpt-4o' } });
        });

        await page.route('**/api/auth/me', async (route) => {
            await route.fulfill({
                json: {
                    id: 'test-id',
                    name: 'Test Photographer',
                    email: testUser.email,
                    is_super_admin: false,
                    is_admin: false,
                    is_photographer: true,
                    can_edit_metadata: false,
                    ai_is_unconfigured: false,
                    roles: ['photographer'],
                    missing_watermark: false,
                }
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await page.goto('/test-gallery-slug');
        await page.waitForLoadState('networkidle');

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(aiButton).toBeVisible();
    });

    test('opens AI Batch-Edit modal when button is clicked', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: true, model: 'gpt-4o' } });
        });

        await page.route('**/api/auth/me', async (route) => {
            await route.fulfill({
                json: {
                    id: 'test-id',
                    name: 'Test Photographer',
                    email: testUser.email,
                    is_super_admin: false,
                    is_admin: false,
                    is_photographer: true,
                    can_edit_metadata: false,
                    ai_is_unconfigured: false,
                    roles: ['photographer'],
                    missing_watermark: false,
                }
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await page.goto('/test-gallery-slug');
        await page.waitForLoadState('networkidle');

        const aiButton = page.getByRole('button', { name: 'KI Batch-Edit' });
        await aiButton.click();

        await expect(page.getByText('KI Batch-Edit')).toBeVisible();
    });

    test('hides AI button when AI is unconfigured', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: false, model: '' } });
        });

        await page.route('**/api/auth/me', async (route) => {
            await route.fulfill({
                json: {
                    id: 'test-id',
                    name: 'Test Photographer',
                    email: testUser.email,
                    is_super_admin: false,
                    is_admin: false,
                    is_photographer: true,
                    can_edit_metadata: false,
                    ai_is_unconfigured: true,
                    roles: ['photographer'],
                    missing_watermark: false,
                }
            });
        });

        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        await page.goto('/test-gallery-slug');
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('button', { name: 'KI Batch-Edit' })).toBeHidden();
    });
});
