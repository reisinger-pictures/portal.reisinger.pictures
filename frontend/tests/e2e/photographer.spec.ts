import { test, expect } from '@playwright/test';

test.describe('Photographer Core Workflow', () => {
  test('Login via Sidebar and create a new Delivery Gallery', async ({ page }) => {
    await page.goto('/');

    await page.fill('input[placeholder="E-Mail Adresse"]', 'florian@reisinger.pictures');
    await page.fill('input[placeholder="Passwort"]', 'admin');
    await page.click('button:has-text("Login")');

    await expect(page.locator('text=Galerie Struktur').first()).toBeVisible({ timeout: 10000 });

    await page.click('button:has-text("Galerie...")');

    // Name der Galerie über den sichtbaren Label-Text anvisieren
    await page.locator('.form-control').filter({ hasText: 'Name der Galerie' }).locator('input').fill('Playwright E2E Wedding');

    // Dropdown ebenfalls über den Label-Text anvisieren
    await page.locator('.form-control').filter({ hasText: 'Galerie-Typ' }).locator('select').selectOption({ label: 'Delivery (Downloads)' });

    await page.locator('.modal-open').getByRole('button', { name: 'Speichern' }).click();

    await expect(page.locator('text=Playwright E2E Wedding').first()).toBeVisible({ timeout: 5000 });
  });
});
