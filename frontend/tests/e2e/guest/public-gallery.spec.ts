import { test, expect } from '@playwright/test';

test.describe('Guest Public Gallery Access (G5)', () => {
    // Renamed 2026-09-26: the name claimed this opened a public gallery, and it
    // never did. It verifies the unauthenticated landing page and that the guest
    // sidebar exposes exactly one email and one password field, which is a real
    // check — a duplicated field would be the same class of defect as the
    // accessible-name bugs fixed earlier. Kept as @smoke: the test body is
    // worth something, only its name was wrong. The actual gap — no spec opens
    // a public gallery as a guest — is recorded in AGENTS.todo.md.
    test('Guest landing page is reachable and the login form is unambiguous', { tag: ['@smoke', '@feature:guest'] }, async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('main').getByRole('heading', { name: 'Neueste Entdeckungen' }).first()).toBeVisible({ timeout: 10000 });

        const searchInput = page.getByRole('main').locator('input[placeholder="Suche in allen Galerien..."]').first();
        await expect(searchInput).toBeVisible();

        await expect(page.locator('main')).toBeVisible();

        const loginForm = page.getByRole('complementary');
        await expect(loginForm.getByRole('textbox', { name: 'E-Mail Adresse' })).toHaveCount(1);
        await expect(loginForm.getByLabel('Passwort', { exact: true })).toHaveCount(1);
    });
});
