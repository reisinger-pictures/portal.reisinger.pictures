import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Model-Filter & Deeplink', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Bereitschafts-Kategorie + Stufe schreibt URL-Parameter, Abwählen entfernt ihn, Reload behält den Filter', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });

        const categoryGroup = page.locator('main').getByRole('group', { name: 'Bereitschafts-Kategorien' });
        const portrait = categoryGroup.getByRole('button', { name: 'Portrait', exact: true });
        const slider = page.locator('main').getByRole('slider', { name: 'Bereitschaftsstufe' });

        // Without a selected category the threshold is "Egal" (slider disabled).
        await expect(slider).toBeDisabled();

        await portrait.click();
        await expect(portrait).toHaveAttribute('aria-pressed', 'true');
        await expect(slider).toBeEnabled();
        // A category chip alone carries no threshold → no `willingness_<category>` param.
        await expect(page).not.toHaveURL(/willingness_portrait=/);

        // Setting the level writes the threshold param.
        await slider.fill('3');
        await expect(page).toHaveURL(/willingness_portrait=gerne/);

        // Changing the level updates the param.
        await slider.fill('4');
        await expect(page).toHaveURL(/willingness_portrait=sehr_gerne/);

        // Reload keeps the URL-driven filter.
        await page.reload();
        await expect(page.locator('main').getByRole('slider', { name: 'Bereitschaftsstufe' })).toHaveValue('4');
        await expect(page).toHaveURL(/willingness_portrait=sehr_gerne/);

        // Clearing the level removes the param but keeps the category chip active.
        await page.locator('main').getByTitle('Stufe abwählen').click();
        await expect(page).not.toHaveURL(/willingness_portrait=/);
        await expect(
            page.locator('main').getByRole('group', { name: 'Bereitschafts-Kategorien' })
                .getByRole('button', { name: 'Portrait', exact: true }),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    test('?model=<id>-Deeplink öffnet den Detail-Dialog; Schließen entfernt den Parameter', { tag: ['@feature:model-registration'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);

        // Fixture via the sanctioned API helper (no DB/localStorage hacking).
        const model = await helper.createRegisteredModel();
        const listRes = await request.get('/api/management/models?q=' + encodeURIComponent(model.firstName), {
            headers: { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() },
        });
        expect(listRes.ok()).toBeTruthy();
        const list = await listRes.json() as Array<{ id: string }>;
        const modelId = list[0]?.id;
        if (!modelId) throw new Error('Registered model not found in management list');

        await auth.login(adminUser.email, adminUser.password);
        // Direct URL access with query param — the documented purpose of `?model=`.
        await page.goto(`/admin-models?model=${modelId}`);

        await expect(page.getByTestId('model-facts')).toBeVisible({ timeout: 15000 });
        await expect(page.locator('.modal-box').getByRole('heading', { level: 3 })).toContainText(model.firstName);

        await page.locator('.modal-box').getByRole('button', { name: 'Schließen', exact: true }).click();
        await expect(page).not.toHaveURL(/[?&]model=/);
        await expect(page.getByTestId('model-facts')).toHaveCount(0);
    });
});
