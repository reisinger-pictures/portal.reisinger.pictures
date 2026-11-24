import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeaders } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * EUE-121 — Projekte × Monat. Das Panel wird über den bestehenden
 * Auswertungs-Tab erreicht (D-10: alle Locators auf <main> gescopt).
 * Die Buchung wird vor dem Öffnen des Tabs per API angelegt, damit der
 * erste SWR-Ladevorgang den Wert bereits enthält.
 */
test.describe('EUE-121 Projekte-Auswertung', () => {
    let helper: E2ESessionHelper;
    let user: { email: string; password: string; id: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        user = await helper.createIsolatedUser('admin', { brand: 'rp' });
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Auswertungen zeigen die Projekts-Matrix mit gebuchtem Monat und Abgleich',
        { tag: ['@feature:eur-project-analytics'] },
        async ({ page, request }) => {
            const cookie = await helper.loginAs(user.email, user.password);
            const created = await request.post('/api/management/eur/entries', {
                data: {
                    year: 2026, month: 11, day: 10, direction: 'income',
                    kind_type: 'Honorar', e1a_code: '9040',
                    business_share: 100, amount_cents: 123456,
                },
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    Cookie: cookie,
                    ...browserAuthHeaders(cookie),
                },
            });
            expect(created.ok(), `EUR entry create failed: ${await created.text()}`).toBeTruthy();

            const auth = new AuthHelper(page);
            const sidebar = new SidebarHelper(page);
            await auth.login(user.email, user.password);
            await sidebar.navigateTo('EA Rechnung');

            const main = page.getByRole('main');
            await page.getByRole('tab', { name: 'Auswertungen', exact: true }).click();

            const panel = main.getByTestId('eur-project-analytics-panel');
            await expect(panel.getByRole('heading', { name: /Projekte-Auswertung/ })).toBeVisible({ timeout: 20_000 });
            await expect(panel.getByRole('columnheader', { name: 'Projekt' })).toBeVisible();
            await expect(panel.getByRole('columnheader', { name: 'Nov' })).toBeVisible();
            await expect(panel.getByRole('columnheader', { name: 'Cent pro Minute' })).toBeVisible();

            // Die Buchung ohne Projekt landet in der Zeile "Ohne Projekt" und im Nov-Feld.
            await expect(panel.getByTestId('eur-project-cell-Ohne Projekt-11')).toContainText('1.234,56 €');

            await expect(panel.getByText(/^Vorjahres-AfA/)).toBeVisible();
            await expect(panel.getByText(/SV-Bereinigung/)).toBeVisible();
            await expect(panel.getByRole('status').filter({ hasText: /Abgleich Projekte|Abweichung Projekte/ }))
                .toBeVisible();
        });
});
