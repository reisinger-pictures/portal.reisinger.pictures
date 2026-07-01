import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';

test.describe('Admin AI Config Banner', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('AT-03-E5a: AI disabled — no banner, no AI button', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: false, status: 'disabled', model: '' } });
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

        await page.waitForTimeout(1000);

        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung' })).toBeHidden({ timeout: 5000 });
    });

    test('AT-03-E5b: AI unconfigured — warning banner visible', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: false, status: 'unconfigured', model: '' } });
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

        await page.waitForTimeout(1000);

        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung nicht konfiguriert' })).toBeVisible({ timeout: 5000 });
    });

    test('AT-03-E5c: AI available — no warning banner', async ({ page }) => {
        await page.route('**/api/ai/status', async route => {
            await route.fulfill({ json: { enabled: true, status: 'available', model: 'gpt-4o' } });
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

        await page.waitForTimeout(1000);

        await expect(page.locator('.alert-warning').filter({ hasText: 'KI-Bildbeschreibung' })).toBeHidden({ timeout: 5000 });
    });
});
