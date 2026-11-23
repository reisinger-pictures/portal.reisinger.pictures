import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * DEG-013: Die Wechsel-Erinnerung ist ein Hinweis, kein Fehler. Das Anlagegut
 * wird über die API angelegt — die SPA hat bewusst keinen Anlage-Dialog.
 */
test.describe('EAR · DEG-013 Wechsel-Erinnerung', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Zeigt eine nicht blockierende Wechsel-Erinnerung für ein degressives Anlagegut', { tag: ['@feature:eur-degression'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(superAdmin.email, superAdmin.password);

        const created = await page.request.post('/api/management/eur/assets', {
            headers: await browserAuthHeadersFromPage(page),
            data: {
                name: 'Degressives E2E-Gut',
                invoice_no: 'R-DEG-013',
                supplier: 'Lieferant',
                purchase_date: '2026-01-15',
                cost_cents: 240000,
                useful_life_years: 3,
                business_share: 100,
                method: 'degressive',
                degressive_rate: 3000,
            },
        });
        expect(created.status()).toBe(201);

        await sidebar.navigateTo('EA Rechnung');
        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();

        // Server-derived: 240.000 ct / 3 Jahre, 30 % degressiv, Jan 2026 →
        // Wechseljahr 2027, Vorteil 336,00 € (DEG-011/013).
        await expect(main.getByText(/Wechsel zur linearen AfA ab Jahr 2027 empfohlen/)).toBeVisible();
        await expect(main.getByText(/336,00/)).toBeVisible();
        // Der Hinweis blockiert keine Buchung und ist ausdrücklich kein Fehler.
        await expect(main.getByRole('alert')).toHaveCount(0);
    });
});
