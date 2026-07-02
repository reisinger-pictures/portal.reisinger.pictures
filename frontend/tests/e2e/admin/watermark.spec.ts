import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Watermark Configuration', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can see and interact with watermark settings', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');

        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const watermarkCard = page.locator('main').locator('h2:has-text("Bildschutz")');
        await expect(watermarkCard).toBeVisible();

        const slider = page.locator('main input[type="range"]');
        await expect(slider).toBeVisible();

        const opacityLabel = page.locator('main .font-mono').first();
        await expect(opacityLabel).toBeVisible();

        const preview = page.locator('main img[alt="Watermark Preview"]');
        await expect(preview).toBeVisible({ timeout: 10000 });

        const submitBtn = page.getByRole('button', { name: 'Wasserzeichen generieren & anwenden' });
        await expect(submitBtn).toBeVisible();
    });

    test('Admin can adjust watermark opacity and submit the form', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');

        const slider = page.locator('main input[type="range"]');
        await expect(slider).toBeVisible();

        await slider.evaluate((el: HTMLInputElement) => {
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set!;
            nativeInputValueSetter.call(el, '0.5');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        const opacityLabel = page.locator('main .label-text-alt.font-mono');
        await expect(opacityLabel).toContainText('50');

        const submitBtn = page.getByRole('button', { name: 'Wasserzeichen generieren & anwenden' });
        await expect(submitBtn).toBeEnabled({ timeout: 10000 });
    });
});
