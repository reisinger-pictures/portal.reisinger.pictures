import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Cart Persistence & Validation Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Invalid or corrupted localStorage is caught by Zod and results in empty cart', { tag: ['@feature:client:cart'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);
        
        const cartKey = `rp_cart_${btoa(String(testUser.id))}`;

        // Einmal initial zum Cart navigieren
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page.locator('text=Dein Warenkorb ist leer.')).toBeVisible();

        // 1. Inject completely corrupted JSON
        await page.addInitScript((key) => {
            localStorage.setItem(key, 'THIS_IS_NOT_JSON');
        }, cartKey);
        await page.reload();
        await expect(page.locator('text=Dein Warenkorb ist leer.')).toBeVisible();

        // 2. Inject valid JSON but invalid schema (missing required fields)
        await page.addInitScript((key) => {
            localStorage.setItem(key, JSON.stringify([{ invalid: 'data', price: 'not-a-number' }]));
        }, cartKey);
        await page.reload();
        await expect(page.locator('text=Dein Warenkorb ist leer.')).toBeVisible();

        // 3. Inject valid cart matching the Zod schema
        await page.addInitScript((key) => {
            localStorage.setItem(key, JSON.stringify([{
                photoId: 'valid-id-123',
                filename: 'Test Bild',
                thumb_url: 'https://placehold.co/100',
                tier: 'web',
                price: 1500,
                useCaseName: 'E2E Test Lizenz',
                modifierNames: [],
                isQuote: false
            }]));
        }, cartKey);
        await page.reload();
        
        await expect(page.locator('text=Dein Warenkorb ist leer.')).toBeHidden();
        await expect(page.locator('text=Test Bild').or(page.locator('text=E2E Test Lizenz')).first()).toBeVisible();
    });
});
