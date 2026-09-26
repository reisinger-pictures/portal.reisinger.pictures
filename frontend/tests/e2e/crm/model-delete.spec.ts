import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Model löschen (DSGVO)', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin löscht ein Model; normaler Admin sieht den Löschen-Button nicht', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const model = await helper.createRegisteredModel();
        const adminUser = await helper.createIsolatedUser('admin');

        // Normal admin: the model detail opens, but the DSGVO delete zone is hidden.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(model.firstName);

        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();
        await expect(page.getByTestId('model-delete-zone')).toHaveCount(0);
        // Scope to the dialog and match the name exactly: the sidebar's mobile
        // close button is labelled "Menü schließen" and is only rendered on
        // narrow viewports, so an unscoped substring match becomes a strict-mode
        // violation on mobile.
        // The shell renders a labelled header close button and the footer has its
        // own, so an unscoped 'Schließen' matches two. Scope to the footer.
        await page.getByRole('dialog').locator('.modal-action').getByRole('button', { name: 'Schließen', exact: true }).click();
        await auth.logout();

        // Super-admin: delete the model for good.
        const superUser = await helper.createIsolatedUser('super_admin');
        await auth.login(superUser.email, superUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(model.firstName);

        const cardAgain = page.locator('[data-testid^="model-card-"]').first();
        await expect(cardAgain).toBeVisible({ timeout: 15000 });
        await cardAgain.click();
        await page.getByTestId('model-delete').click();
        await page.locator('.modal-global').getByRole('button', { name: 'Endgültig löschen', exact: true }).click();

        await expect(page.locator('.toast')).toContainText('Profil wurde gelöscht');
        // The list refreshes: the deleted model is gone.
        await expect(page.locator('[data-testid^="model-card-"]')).toHaveCount(0, { timeout: 15000 });
    });

    test('Super-Admin bricht die DSGVO-Löschung ab; Profil bleibt erhalten', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const model = await helper.createRegisteredModel();
        const superUser = await helper.createIsolatedUser('super_admin');

        await auth.login(superUser.email, superUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(model.firstName);

        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();

        // Trigger the danger-zone deletion, then abort it.
        await page.getByTestId('model-delete').click();
        const confirmDialog = page.locator('.modal-global');
        await expect(confirmDialog).toContainText('Profil endgültig löschen?');
        await confirmDialog.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(confirmDialog).toHaveCount(0);

        // Detail stays open (nothing was deleted), then close it.
        await expect(page.getByTestId('model-delete-zone')).toBeVisible();
        // The shell renders a labelled header close button and the footer has its
        // own, so an unscoped 'Schließen' matches two. Scope to the footer.
        await page.getByRole('dialog').locator('.modal-action').getByRole('button', { name: 'Schließen', exact: true }).click();

        // Server-side persistence: reload the filtered list — the model is still there.
        await page.reload();
        await expect(page.locator('#model-filter-q')).toHaveValue(model.firstName);
        await expect(page.locator('[data-testid^="model-card-"]')).toHaveCount(1, { timeout: 15000 });
    });
});
