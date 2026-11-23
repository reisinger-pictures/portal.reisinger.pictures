import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * DEG-006/007/008: die Ausschlüsse werden an der Schreibgrenze geprüft; die
 * gespeicherten Merkmale und die Ausnahme erscheinen in der Anlagenliste.
 * Anlagegüter werden über die API angelegt — die SPA hat keinen Anlage-Dialog.
 */
test.describe('EAR · DEG-Ausschlüsse', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Ausschlüsse werden abgelehnt, zulässige Merkmale angezeigt', { tag: ['@feature:eur-degression'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(superAdmin.email, superAdmin.password);
        const headers = await browserAuthHeadersFromPage(page);

        const payload = {
            name: 'DEG-Prüfling',
            invoice_no: 'R-DEG-E2E',
            supplier: 'Lieferant',
            purchase_date: '2026-01-15',
            cost_cents: 240000,
            useful_life_years: 3,
            business_share: 100,
            method: 'degressive',
            degressive_rate: 3000,
        };

        // DEG-006: gebrauchte Güter sind degressiv nicht zulässig.
        const rejected = await page.request.post('/api/management/eur/assets', {
            headers,
            data: {...payload, name: 'Gebraucht-Gut', is_used: true},
        });
        expect(rejected.status()).toBe(422);
        expect((await rejected.json() as {message: string}).message).toContain('gebrauchte Wirtschaftsgüter');

        // DEG-007/008: Ausnahme und Elektroauto sind ausdrücklich zulässig.
        const created = await page.request.post('/api/management/eur/assets', {
            headers,
            data: {
                ...payload,
                is_immaterial: true,
                immaterial_exception: 'digitalisation',
                special_depreciation: true,
                is_electric_vehicle: true,
            },
        });
        expect(created.status()).toBe(201);

        await sidebar.navigateTo('EA Rechnung');
        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();

        const row = main.getByRole('row').filter({ hasText: 'DEG-Prüfling' });
        await expect(row.getByText('Immateriell')).toBeVisible();
        await expect(row.getByText(/Ausnahme: Digitalisierung/)).toBeVisible();
        await expect(row.getByText('Sonder-AfA')).toBeVisible();
        await expect(row.getByText('Elektroauto')).toBeVisible();
    });
});
