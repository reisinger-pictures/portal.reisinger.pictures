import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

test.describe('EAR sub-application', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin moves through the EAR tabs, books an entry, calculates travel and VAT and compares the depreciation', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);

        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await expect(page).toHaveURL(/\/steuern/);
        await expect(page.getByRole('heading', { name: /Einnahmen Ausgaben Rechnung ·/, level: 1 })).toBeVisible();
        await expect(page.getByRole('tablist')).toBeVisible();
        await expect(main.getByRole('heading', { name: 'Übersicht 2026' })).toBeVisible();

        // Stammdaten: gepinnte Kataloge und Reise-Pauschale Inland.
        await page.getByRole('tab', { name: 'Einstellungen' }).click();
        await expect(main.getByRole('heading', { name: 'Stammdaten und Kataloge' })).toBeVisible();
        await expect(main).toContainText('Inland: 30,00 € Tag und 17,00 € Nächtigung.');
        await expect(main.getByRole('heading', { name: 'Feiertage' })).toBeVisible();

        // Buchung anlegen (Dialog aus der Liste öffnen).
        await page.getByRole('tab', { name: 'Buchungen' }).click();
        await main.getByRole('button', { name: '+ Buchung' }).click();
        const dialog = main.getByRole('dialog', { name: 'Neue Buchung' });
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Monat').selectOption({ label: 'Mai' });
        await dialog.getByLabel('Art').selectOption({ label: 'Software' });
        await dialog.getByLabel('Betrag (€)').fill('100');
        await dialog.getByLabel('Betriebsanteil (%)').fill('60');
        await dialog.getByRole('button', { name: 'Buchung speichern' }).click();
        await expect(dialog).toHaveCount(0);

        // EUR entries are brand-scoped; a super-admin shares the default brand
        // with the parallel Mobile project, so scope to the newest matching row
        // instead of relying on a single table-wide cell.
        const bookedRow = main.getByRole('row').filter({ hasText: 'Software' }).first();
        await expect(bookedRow.getByRole('cell', { name: 'Software' })).toBeVisible();
        await expect(bookedRow.getByRole('cell', { name: '60 %' })).toBeVisible();
        await expect(bookedRow.getByRole('cell', { name: '60,00 €' })).toBeVisible();

        // Reisen: Kilometer, Tagesgeld und Nächtigung.
        await page.getByRole('tab', { name: 'Reisen' }).click();
        await main.getByRole('button', { name: 'Reisekosten berechnen' }).click();
        await expect(main).toContainText('Kilometergeld');
        await expect(main.getByText('50,00 €')).toBeVisible();

        // Umsatzsteuer: acht Kennzahlen und Zahllast.
        await page.getByRole('tab', { name: 'USt' }).click();
        await main.getByLabel('Kleinunternehmer-Umsatz (€)').fill('1000');
        await main.getByRole('button', { name: 'Kennzahlen berechnen' }).click();
        await expect(main.getByRole('cell', { name: 'KZ 016' })).toBeVisible();
        await expect(main.getByRole('cell', { name: '1.000,00 €' }).first()).toBeVisible();

        // Anlagen: DEG-012 mit dem dokumentierten Beispiel.
        await page.getByRole('tab', { name: 'Anlagen' }).click();
        await main.getByLabel('Anschaffungskosten (€)').fill('20000');
        await main.getByLabel('Nutzungsdauer (Jahre)').fill('10');
        await main.getByLabel('Degressiver Satz (%)').fill('30');
        await main.getByLabel('Anschaffungsmonat').selectOption({ label: 'Jänner' });
        await main.getByRole('button', { name: 'Vergleich berechnen' }).click();

        await expect(main).toContainText('Empfohlener Wechsel zur linearen Abschreibung: Jahr 8');
        await expect(main).toContainText('258,04 €');
        await expect(main.getByRole('cell', { name: '6.000,00 €' })).toBeVisible();

        await page.getByRole('banner').getByRole('link', { name: 'Zurück zum Portal' }).click();

        await expect(page).toHaveURL('/');
        await expect(page.getByRole('heading', { name: /Einnahmen Ausgaben Rechnung ·/, level: 1 })).toHaveCount(0);
        await expect(page.getByRole('complementary').getByRole('link', { name: 'EA Rechnung' })).toBeVisible();
    });
});
