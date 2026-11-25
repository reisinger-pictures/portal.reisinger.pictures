import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * D-91 contract: "Werte für FinanzOnline" is a manual-entry aid. The portal
 * computes the values for a human to type into FinanzOnline; it never submits
 * them electronically and never fetches the PDF on the user's behalf. These
 * tests pin that boundary (D-91 notice, printable filling-aid link, no load
 * error) and the income-tax inputs behind the U23/U27/U25/U24/U29 allocation,
 * including U24's documented empty marker in "Nur Bruttolohn" (EUE-103/105).
 *
 * E2E runs only in CI: the portal's test/E2E suites exceed this host's RAM
 * budget, so local verification is limited to lint, tsc and `playwright --list`.
 */
test.describe('EAR · D-91 Ausfüllhilfe und Einkommensteuer-Eingaben', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('D-91: Werte für FinanzOnline zeigt Hinweis, E1A-Tabelle und PDF-Link', { tag: ['@feature:eur-ausfuellhilfe'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Steuer', exact: true }).click();

        await expect(main.getByRole('heading', { name: 'Werte für FinanzOnline', exact: true })).toBeVisible();

        // The live API sends "Manuelle Eingabe in FinanzOnline. Keine
        // elektronische Übermittlung."; the component unit fixture phrases the
        // same D-91 boundary as "...manuell in FinanzOnline eingetragen...".
        // Match the contract (manual FinanzOnline entry), not one wording.
        await expect(main.getByRole('status').filter({ hasText: /manuell.*finanzonline/i })).toBeVisible({ timeout: 20_000 });

        // Both phrasings of the first filling-aid section are covered: the API
        // uses "Einkommensteuererklärung E1A", the unit fixture "E1A-Kennzahlen".
        const e1aHeading = main.getByRole('heading', {
            name: /Einkommensteuererklärung E1A|E1A-Kennzahlen/,
            level: 3,
        });
        await expect(e1aHeading).toBeVisible();
        const e1aSection = e1aHeading.locator('..');
        await expect(e1aSection.getByRole('columnheader', { name: 'Reihenfolge', exact: true })).toBeVisible();
        await expect(e1aSection.getByRole('columnheader', { name: 'Formularfeld', exact: true })).toBeVisible();

        const pdfLink = main.getByRole('link', { name: 'Ausfüllhilfe als PDF herunterladen' });
        await expect(pdfLink).toBeVisible();
        await expect(pdfLink).toHaveAttribute('href', /\/api\/management\/eur\/pdf\/ausfuellhilfe\?year=\d{4}/);

        // A failed SWR load must show the alert, not an empty filling aid.
        await expect(main.getByRole('alert').filter({ hasText: 'Die Ausfüllhilfe konnte nicht geladen werden.' })).toHaveCount(0);
    });

    test('Einkommensteuer: sechs Modi und U23/U27/U25/U24/U29 mit leerem U24 im Bruttolohn-Modus', { tag: ['@feature:eur-tax-inputs'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Steuer', exact: true }).click();
        await expect(main.getByRole('heading', { name: 'Einkommensteuer', exact: true })).toBeVisible();

        const modeSelect = main.getByLabel('Rechenmodus', { exact: true });
        await expect(modeSelect).toBeVisible();
        for (const option of ['Gesamt', 'Nur Betrieb', 'Nur Vermietung', 'Prognose', 'Nur Bruttolohn', 'Nur andere Gewinne']) {
            await expect(modeSelect.getByRole('option', { name: option, exact: true })).toHaveCount(1);
        }

        await modeSelect.selectOption({ label: 'Nur Bruttolohn' });
        await main.getByLabel('Gewinn (€)', { exact: true }).fill('1000');
        await main.getByRole('button', { name: 'Steuer berechnen' }).click();

        // The allocation table only renders after the POST resolves; the
        // heading assertion auto-retries on that server-derived content.
        const allocationHeading = main.getByRole('heading', { name: 'Aufteilung der Einkommensteuer', exact: true });
        await expect(allocationHeading).toBeVisible({ timeout: 20_000 });
        const allocation = allocationHeading.locator('..');

        for (const [code, label] of [
            ['U23', 'Endsteuer'],
            ['U27', 'Lohnsteueranteil'],
            ['U25', 'Vermietungsanteil'],
            ['U24', 'Betriebsanteil'],
            ['U29', 'Zahllast'],
        ] as const) {
            await expect(allocation.getByRole('row').filter({ hasText: code }).filter({ hasText: label })).toBeVisible();
        }

        // EUE-103/105: business_tax_cents is null in "Nur Bruttolohn". The panel
        // renders null as the empty marker "—", never as a euro amount. No
        // assertion on the computed tax figures themselves.
        const u24Value = allocation.getByRole('row').filter({ hasText: 'U24' })
            .filter({ hasText: 'Betriebsanteil' }).getByRole('cell').last();
        await expect(u24Value).toHaveText('—');
        await expect(u24Value).not.toContainText('€');
    });
});
