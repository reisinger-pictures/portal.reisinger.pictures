import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeaders } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * EUE-121 — Projekte × Monat. Das Panel wird über den bestehenden
 * Auswertungs-Tab erreicht (D-10: alle Panel-Locators auf <main> gescopt).
 *
 * Flaky-Historie (dieser Test, nicht das Feature):
 * - CI 45361498710: absolute Erwartung "1.234,56 €" war falsch, weil der
 *   geteilte E2E-Brand im November bereits eine 395,00-€-Buchung ohne Projekt
 *   enthält (Zelle zeigte 1.629,56 €). Jeder Retry addierte die Buchung erneut.
 * - CI 45363332767: Tab-Wechsel löste keine SWR-Revalidierung aus, die Zelle
 *   blieb bei 39.500 ct.
 * Fix: Buchung per API anlegen, erwarteten Wert aus demselben Endpunkt lesen,
 * den das Panel nutzt, und die Seite danach frisch laden. Die Buchung wird im
 * finally-Block gelöscht, damit Wiederholungen nichts aufsummieren.
 */

/** de-DE-Betrag mit zwei Nachkommastellen, ohne Symbol (formatEuro nutzt NBSP vor €). */
const amount = (cents: number): string =>
    (cents / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type ByProject = {
    projects: Array<{
        project: string;
        months: Array<{ month: number; income_cents: number }>;
    }>;
};

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
        { tag: ['@feature:ea-project-analytics'] },
        async ({ page, request }) => {
            const cookie = await helper.loginAs(user.email, user.password);
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Cookie: cookie,
                ...browserAuthHeaders(cookie),
            };

            const created = await request.post('/api/management/ea/entries', {
                data: {
                    year: 2026, month: 11, day: 10, direction: 'income',
                    kind_type: 'Honorar', e1a_code: '9040',
                    business_share: 100, amount_cents: 123456,
                },
                headers,
            });
            expect(created.ok(), `EUR entry create failed: ${await created.text()}`).toBeTruthy();
            const entryId = (await created.json() as { entry: { id: number } }).entry.id;

            try {
                // Erwarteter Wert aus demselben Endpunkt, den das Panel rendert.
                const analyticsResponse = await request.get('/api/management/ea/analytics/by-project?year=2026', { headers });
                expect(analyticsResponse.ok(), `Analytics request failed: ${await analyticsResponse.text()}`).toBeTruthy();
                const analytics = await analyticsResponse.json() as ByProject;
                const expectedCents = analytics.projects
                    .find(row => row.project === 'Ohne Projekt')?.months
                    .find(cell => cell.month === 11)?.income_cents ?? 0;
                expect(expectedCents, 'the created income entry must be in the November total').toBeGreaterThanOrEqual(123456);

                await new AuthHelper(page).login(user.email, user.password);
                await new SidebarHelper(page).navigateTo('EA Rechnung');

                const main = page.getByRole('main');
                await page.getByRole('tab', { name: 'Auswertungen', exact: true }).click();

                const panel = main.getByTestId('ea-project-analytics-panel');
                await expect(panel.getByRole('heading', { name: /Projekte-Auswertung/ })).toBeVisible({ timeout: 20_000 });
                await expect(panel.getByRole('columnheader', { name: 'Projekt' })).toBeVisible();
                await expect(panel.getByRole('columnheader', { name: 'Nov' })).toBeVisible();
                await expect(panel.getByRole('columnheader', { name: 'Cent pro Minute' })).toBeVisible();

                await expect(panel.getByTestId('ea-project-cell-Ohne Projekt-11')).toContainText(amount(expectedCents));
                await expect(panel.getByRole('status').filter({ hasText: /Abgleich Projekte|Abweichung Projekte/ }))
                    .toBeVisible();
            } finally {
                const removed = await request.delete(`/api/management/ea/entries/${entryId}`, { headers });
                expect(removed.ok(), `EUR entry cleanup failed: ${await removed.text()}`).toBeTruthy();
            }
        });
});
