import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

test.describe('EAR cockpit navigation', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin besucht alle zwölf EAR-Tabs und prüft je ein semantisches Landmark', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await expect(page).toHaveURL(/\/steuern/);
        await expect(page.getByRole('heading', { name: /Einnahmen Ausgaben Rechnung ·/, level: 1 })).toBeVisible();
        await expect(page.getByRole('tablist')).toBeVisible();

        // Reihenfolge und heading-Texte entsprechen TABS/tabLabel in ManagementEurView.tsx.
        const tabLandmarks: { id: string; tab: string; heading: string }[] = [
            { id: 'buchungen', tab: 'Buchungen', heading: 'Buchungen' },
            { id: 'wareneingang', tab: 'Wareneingang', heading: 'Wareneingang' },
            { id: 'belege', tab: 'Belege', heading: 'Belege' },
            { id: 'anlagen', tab: 'Anlagen', heading: 'Abschreibung vergleichen' },
            { id: 'monat', tab: 'Monat', heading: 'Monatsabschluss' },
            { id: 'auswertungen', tab: 'Auswertungen', heading: 'Auswertungen' },
            { id: 'reisen', tab: 'Reisen', heading: 'Reisekosten' },
            { id: 'steuer', tab: 'Steuer', heading: 'Einkommensteuer' },
            { id: 'ust', tab: 'USt', heading: 'Umsatzsteuer' },
            { id: 'antraege', tab: 'Anträge', heading: 'Anträge' },
            { id: 'signatur', tab: 'Signatur', heading: 'Signatur' },
            { id: 'einstellungen', tab: 'Einstellungen', heading: 'Unternehmensdaten' },
        ];

        for (const { id, tab, heading } of tabLandmarks) {
            await test.step(`Tab ${tab}`, async () => {
                const tabButton = page.getByRole('tab', { name: tab, exact: true });
                // The Anlagen panel is tall; after its heading assertion the
                // page can still be settling at a lower scroll position. Bring
                // the tab back into view so the click cannot land on a neighbour
                // in the 12-tab strip (deterministic "Monat" miss seen in CI).
                await tabButton.scrollIntoViewIfNeeded();
                await tabButton.click();
                // The tab is URL-driven (selectTab → `bereich`). Waiting for the
                // URL first both proves the click registered and gives React a
                // reliable point to re-render before checking aria-selected.
                await expect(page).toHaveURL(new RegExp(`bereich=${id}(?:&|$)`));
                await expect(tabButton).toHaveAttribute('aria-selected', 'true');
                // D-10: Panel-Inhalte bleiben auf das main-Landmark gescopt.
                await expect(main.getByRole('heading', { name: heading, exact: true })).toBeVisible();
            });
        }
    });

    test('Anlagen-Tab zeigt die AfA-Anzeige mit Privatfilter', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();

        // D-10: Panel-Inhalte bleiben auf das main-Landmark gescopt.
        await expect(main.getByRole('heading', { name: 'Anlagen und Abschreibung' })).toBeVisible();
        const panel = main.getByTestId('eur-assets-panel');
        await expect(panel.getByRole('button', { name: 'Alle Anlagen' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Privateinlagen' })).toBeVisible();
    });

    test('Monatssteuerung legt Monat und Jahr idempotent an', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        // Die Steuerelemente liegen im aside-Landmark der EAR-Sub-App, nicht in
        // main; gescopt wird hier ebenfalls über ein semantisches Landmark (D-10).
        const monthControls = page.getByRole('complementary');
        const monthStatus = monthControls.getByRole('status').filter({ hasText: 'Der Monat' });
        const yearStatus = monthControls.getByRole('status').filter({ hasText: 'Das Jahr' });
        const waitForMonthPost = () => page.waitForResponse((response) =>
            response.request().method() === 'POST' && response.url().endsWith('/api/management/eur/months'));
        const waitForYearPost = () => page.waitForResponse((response) =>
            response.request().method() === 'POST' && response.url().endsWith('/api/management/eur/months/year'));

        let post = waitForMonthPost();
        await monthControls.getByRole('button', { name: 'Neuer Monat' }).click();
        await post;
        await expect(monthStatus).toContainText(/Der Monat \d+\/\d+ (wurde angelegt|war bereits vorhanden)\./, { timeout: 10000 });

        // Zweiter Klick: der Monat existiert bereits und bleibt erfolgreich.
        post = waitForMonthPost();
        await monthControls.getByRole('button', { name: 'Neuer Monat' }).click();
        await post;
        await expect(monthStatus).toContainText(/Der Monat \d+\/\d+ war bereits vorhanden\./, { timeout: 10000 });

        post = waitForYearPost();
        await monthControls.getByRole('button', { name: 'Ganzes Jahr anlegen' }).click();
        await post;
        await expect(yearStatus).toContainText(/Das Jahr \d{4} ist angelegt \(\d+ Monate\)\./, { timeout: 10000 });

        // Zweiter Klick: kein Fehler, weiterhin deutscher Erfolgsstatus.
        post = waitForYearPost();
        await monthControls.getByRole('button', { name: 'Ganzes Jahr anlegen' }).click();
        await post;
        await expect(yearStatus).toContainText(/Das Jahr \d{4} ist angelegt \(\d+ Monate\)\./, { timeout: 10000 });
        await expect(monthControls.getByRole('alert')).toHaveCount(0);
    });

    test('CSV-Import der Belege zeigt deutsches Datei-Label und ein Import-Ergebnis', { tag: ['@feature:eur'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');

        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Belege', exact: true }).click();
        await expect(main.getByRole('heading', { name: 'Belege', exact: true })).toBeVisible();

        await expect(main.getByText('CSV-Import', { exact: true })).toBeVisible();
        await expect(main.getByText('CSV-Datei', { exact: true })).toBeVisible();
        await expect(main.getByText('CSV-Datei auswählen', { exact: true })).toBeVisible();

        // Nur die Kopfzeile: der Import erzeugt keine Buchungen und kollidiert
        // damit nicht mit den parallelen Desktop-/Mobile-Projekten.
        const csv = 'Datum;Art;Kategorie;Beschreibung;Betrag;Betriebsanteil\n';
        await main.getByLabel('CSV-Datei auswählen').setInputFiles({
            name: 'e2e-import.csv',
            mimeType: 'text/csv',
            buffer: Buffer.from(csv, 'utf-8'),
        });
        await expect(main.getByText('e2e-import.csv', { exact: true })).toBeVisible();

        await main.getByRole('button', { name: 'CSV importieren' }).click();
        await expect(main.getByRole('status').filter({ hasText: 'Import abgeschlossen' }))
            .toContainText('Import abgeschlossen: 0 angelegt, 0 übersprungen.');
    });
});
