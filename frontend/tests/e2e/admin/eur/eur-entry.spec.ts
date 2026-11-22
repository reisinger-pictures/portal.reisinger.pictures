import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

test.describe('EÜR integration entry point', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin opens Buchhaltung (EÜR) and returns to the portal', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);

        await sidebar.navigateTo('Buchhaltung (EÜR)');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Buchhaltung (EÜR)', level: 1 })).toBeVisible();
        await expect(main).toContainText('Unternehmensdaten');
        await expect(main).toContainText('Geringwertige Wirtschaftsgüter');
        await expect(main).toContainText('unter 1.000 €');
        await expect(main).toContainText('Betriebsanteil');

        await main.getByRole('link', { name: 'Zurück zum Portal' }).click();

        await expect(page).toHaveURL('/');
        // "/" rendert das Dashboard mit currentView "structure" — die
        // Galerie-Verwaltung liegt auf "/galleries" und ist hier nicht
        // gemountet. Der belastbare Rückweg ist daher: EÜR-View weg,
        // Portal-Shell (Sidebar) weiterhin vorhanden.
        await expect(main.getByRole('heading', { name: 'Buchhaltung (EÜR)', level: 1 })).toHaveCount(0);
        await expect(page.getByRole('complementary').getByRole('link', { name: 'Buchhaltung (EÜR)' })).toBeVisible();
    });
});
