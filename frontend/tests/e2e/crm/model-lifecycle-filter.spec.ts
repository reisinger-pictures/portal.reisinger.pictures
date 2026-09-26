import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

/**
 * N2 (User-Entscheidung): Inaktive Modelle sind nur fuer Super-Admins
 * sichtbar. Regulaere Admins sehen den Status-Filter nur mit „Aktiv"; ein
 * manipulierter/deep-gelinkter `lifecycle_status=inactive|all` wird vom
 * Backend fail-closed mit 403 beantwortet und vom UI mit Toast + Reset
 * abgefangen.
 */
test.describe('Model-Lifecycle-Filter (Super-Admin only)', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin sieht die Status-Optionen Aktiv, Inaktiv und Alle', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const superUser = await helper.createIsolatedUser('super_admin');

        await auth.login(superUser.email, superUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });

        const statusSelect = page.locator('main #model-filter-lifecycle');
        await expect(statusSelect.getByRole('option', { name: 'Aktiv', exact: true })).toHaveCount(1);
        await expect(statusSelect.getByRole('option', { name: 'Inaktiv', exact: true })).toHaveCount(1);
        await expect(statusSelect.getByRole('option', { name: 'Alle', exact: true })).toHaveCount(1);
    });

    test('Normaler Admin sieht nur Aktiv; Deep-Link auf Inaktiv wird mit Toast zurueckgesetzt', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const adminUser = await helper.createIsolatedUser('admin');

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });

        const statusSelect = page.locator('main #model-filter-lifecycle');
        await expect(statusSelect.getByRole('option', { name: 'Aktiv', exact: true })).toHaveCount(1);
        await expect(statusSelect.getByRole('option', { name: 'Inaktiv', exact: true })).toHaveCount(0);
        await expect(statusSelect.getByRole('option', { name: 'Alle', exact: true })).toHaveCount(0);

        // Direct URL access with a tampered filter param — the documented way
        // the filter params are shared. The backend answers 403 (fail-closed);
        // the UI must not swallow it silently.
        await page.goto('/admin-models?lifecycle_status=inactive');
        await expect(page.locator('.toast')).toContainText('Nur Super-Admins', { timeout: 15000 });
        await expect(page).not.toHaveURL(/lifecycle_status=inactive/);
        await expect(statusSelect.getByRole('option', { name: 'Aktiv', exact: true })).toHaveCount(1);
    });
});
