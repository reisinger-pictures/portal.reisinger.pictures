import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

test.describe('EAR Rechnungskorrektur', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    // Eine automatisch übernommene Rechnung lässt sich nicht direkt aus der UI
    // erzeugen. Der Test prüft daher die verdrahtete UI inklusive des
    // serverseitigen Fehlerpfads für eine nicht vorhandene Rechnungsnummer.
    test('Buchungen-Tab zeigt die Rechnungskorrektur und meldet eine unbekannte Rechnung', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await expect(page).toHaveURL(/\/steuern/);
        await page.getByRole('tab', { name: 'Buchungen', exact: true }).click();

        const panel = main.getByTestId('eur-invoice-correction-panel');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText('negative Einnahme (Erlösminderung)');

        await panel.getByLabel('Rechnungsnummer').fill('P-9999-0001-9999');
        await panel.getByLabel('Rückzahlung (€)').fill('70,00');
        await panel.getByLabel('Grund').fill('Extern erstattet');
        await panel.getByLabel('Jahr').fill('2026');
        await panel.getByLabel('Monat').fill('5');
        await panel.getByLabel('Tag').fill('4');
        await panel.getByRole('button', { name: 'Rechnungskorrektur buchen' }).click();

        await expect(panel.getByRole('alert')).toHaveText('Rechnung nicht gefunden.');
    });
});
