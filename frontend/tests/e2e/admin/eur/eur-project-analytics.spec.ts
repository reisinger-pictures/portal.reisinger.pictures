import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeaders } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/** Amount of the month cell is rendered as "1.234,56 € / 0,00 €…" (whole cents). */
const firstAmountCents = (text: string): number => {
    const match = text.match(/^([\d.]+),(\d{2})\s/);
    if (!match) throw new Error(`Unexpected project cell text: ${text}`);
    return Number(match[1].replaceAll('.', '')) * 100 + Number(match[2]);
};

/**
 * EUE-121 — Projekte × Monat. Das Panel wird über den bestehenden
 * Auswertungs-Tab erreicht (D-10: alle Panel-Locators auf <main> gescopt).
 * Der Test misst die Differenz gegen die schon gerenderte Zelle, weil der
 * E2E-Brand weitere Buchungen desselben Monats enthalten kann. Die eigene
 * Buchung wird am Ende gelöscht, damit Wiederholungen die Zelle nicht
 * aufsummieren.
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
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Cookie: cookie,
                ...browserAuthHeaders(cookie),
            };

            await new AuthHelper(page).login(user.email, user.password);
            const sidebar = new SidebarHelper(page);
            await sidebar.navigateTo('EA Rechnung');

            const main = page.getByRole('main');
            const tab = (name: string) => page.getByRole('tab', { name, exact: true });
            await tab('Auswertungen').click();

            const panel = main.getByTestId('eur-project-analytics-panel');
            await expect(panel.getByRole('heading', { name: /Projekte-Auswertung/ })).toBeVisible({ timeout: 20_000 });
            await expect(panel.getByRole('columnheader', { name: 'Projekt' })).toBeVisible();
            await expect(panel.getByRole('columnheader', { name: 'Nov' })).toBeVisible();
            await expect(panel.getByRole('columnheader', { name: 'Cent pro Minute' })).toBeVisible();

            const cell = panel.getByTestId('eur-project-cell-Ohne Projekt-11');
            await expect(cell).toBeVisible();
            const beforeCents = firstAmountCents(await cell.innerText());

            const created = await request.post('/api/management/eur/entries', {
                data: {
                    year: 2026, month: 11, day: 10, direction: 'income',
                    kind_type: 'Honorar', e1a_code: '9040',
                    business_share: 100, amount_cents: 123456,
                },
                headers,
            });
            expect(created.ok(), `EUR entry create failed: ${await created.text()}`).toBeTruthy();
            const entryId = (await created.json() as {entry: {id: number}}).entry.id;

            try {
                // Tab-Wechsel mountet das Panel neu und löst die SWR-Revalidierung aus.
                await tab('Monat').click();
                await tab('Auswertungen').click();
                await expect.poll(async () => firstAmountCents(await cell.innerText()), { timeout: 20_000 })
                    .toBe(beforeCents + 123456);

                await expect(panel.getByText(/^Vorjahres-AfA/)).toBeVisible();
                await expect(panel.getByText(/SV-Bereinigung/)).toBeVisible();
                await expect(panel.getByRole('status').filter({ hasText: /Abgleich Projekte|Abweichung Projekte/ }))
                    .toBeVisible();
            } finally {
                const removed = await request.delete(`/api/management/eur/entries/${entryId}`, { headers });
                expect(removed.ok(), `EUR entry cleanup failed: ${await removed.text()}`).toBeTruthy();
            }
        });
});
