import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * D-85: two asset directories (business/private) plus the combined view, the
 * private Eigenbeleg instead of a journal booking, the idempotent carry-over
 * and receipts attached to the asset master. All assets and the receipt are
 * created through the API — the SPA has no asset dialog.
 *
 * Navigation is SPA-only (sidebar + tabs); all locators are scoped to <main>
 * and the `eur-assets-panel` testid (owner decision D-10). Playwright cannot
 * run on the development host (RAM policy), so CI is the gate for this spec.
 */
test.describe('EAR · Anlagenverzeichnisse je Jahr (D-85)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const year = 2026;

    async function login(page: Page) {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        // SWR revalidiert nicht beim Fokuswechsel; alle Fixtures müssen daher
        // vor dem Mounten des Panels angelegt werden (Pattern der EUR-Specs).
        return {...await browserAuthHeadersFromPage(page), 'Accept': 'application/json'};
    }

    async function openAssets(page: Page) {
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('EA Rechnung');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();
        const panel = page.getByRole('main').getByTestId('eur-assets-panel');
        await expect(panel.getByRole('heading', { name: 'Anlagen und Abschreibung' })).toBeVisible();
        return panel;
    }

    async function createAsset(page: Page, headers: Record<string, string>, name: string, businessShare: number, year: number) {
        const response = await page.request.post('/api/management/eur/assets', {
            headers,
            data: {
                name,
                invoice_no: `R-${name}`,
                supplier: 'Lieferant',
                purchase_date: `${year}-01-15`,
                cost_cents: 240000,
                useful_life_years: 3,
                business_share: businessShare,
                method: 'linear',
            },
        });
        expect(response.status()).toBe(201);
        return (await response.json() as { asset: { id: number; scope: string } }).asset;
    }

    test('Verzeichnisse, Eigenbeleg und Jahrübernahme', { tag: ['@feature:eur-asset-directories'] }, async ({ page }) => {
        // Ohne Accept: application/json rendert Laravel 422/401 als HTML; die
        // Mutationen brauchen zusätzlich Origin + XSRF wie die SPA selbst.
        const headers = await login(page);
        const suffix = Date.now();
        const businessName = `V071-Betrieb-${suffix}`;
        const privateName = `V071-Privat-${suffix}`;

        const business = await createAsset(page, headers, businessName, 100, year);
        const privateAsset = await createAsset(page, headers, privateName, 30, year);
        expect(business.scope).toBe('business');
        expect(privateAsset.scope).toBe('private');

        const panel = await openAssets(page);
        await expect(panel.getByRole('button', { name: 'Alle Anlagen' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Betriebsanlagen' })).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Privateinlagen' })).toBeVisible();

        await panel.getByRole('button', { name: 'Betriebsanlagen' }).click();
        await expect(panel.getByRole('row').filter({ hasText: businessName })).toBeVisible();
        await expect(panel.getByRole('row').filter({ hasText: privateName })).toHaveCount(0);

        await panel.getByRole('button', { name: 'Privateinlagen' }).click();
        const privateRow = panel.getByRole('row').filter({ hasText: privateName });
        await expect(privateRow).toBeVisible();
        await expect(panel.getByRole('row').filter({ hasText: businessName })).toHaveCount(0);

        // EUE-055: der Eigenbeleg entsteht automatisch ohne Benutzeraktion.
        // Der Button „Eigenbeleg erstellen" ist deshalb nicht mehr gerendert.
        await expect(privateRow.getByText(/Eigenbeleg EB-/)).toBeVisible();
        await expect(privateRow.getByRole('button', { name: 'Eigenbeleg erstellen' })).toHaveCount(0);

        const planResponse = await page.request.get(`/api/management/eur/assets/${privateAsset.id}/plan`, { headers });
        expect(planResponse.ok(), `asset plan failed: ${await planResponse.text()}`).toBe(true);
        const planBody = await planResponse.json() as {
            asset: { years: Array<{ eigenbeleg_no: string | null; eigenbeleg_cents: number | null }> };
        };
        expect(planBody.asset.years).toHaveLength(3);
        expect(planBody.asset.years.every((row) => row.eigenbeleg_no?.startsWith('EB-') === true)).toBe(true);
        expect(planBody.asset.years.every((row) => row.eigenbeleg_cents !== null)).toBe(true);

        // D-85: der Eigenbeleg erzeugt keine Buchung für dieses Anlagegut.
        // Der E2E-Brand enthält weitere Buchungen aus der automatischen
        // Rechnungsübernahme, daher wird nur die eigene Belegnummer geprüft.
        const entriesResponse = await page.request.get(`/api/management/eur/entries?year=${year}`, { headers });
        expect(entriesResponse.ok()).toBe(true);
        const entriesBody = await entriesResponse.json() as { entries: Array<{ receipt_no: string | null }> };
        const ownEntries = entriesBody.entries.filter((entry) =>
            entry.receipt_no?.startsWith(`AV-${privateAsset.id}-`) === true);
        expect(ownEntries).toHaveLength(0);
        await expect(privateRow).not.toHaveText(/Gebucht|Buchung/);

        // Idempotent: der zweite Klick darf nicht fehlschlagen.
        const carryOverResponse = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith('/api/management/eur/assets/carry-over')
            && [200, 201].includes(response.status()));
        await panel.getByRole('button', { name: 'Jahr übernehmen' }).click();
        expect((await carryOverResponse).ok()).toBe(true);
        await expect(panel.getByRole('status').filter({ hasText: /übernommen/ })).toBeVisible();

        const tryAgain = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith('/api/management/eur/assets/carry-over'));
        await panel.getByRole('button', { name: 'Jahr übernehmen' }).click();
        expect((await tryAgain).ok()).toBe(true);
        await expect(panel.getByRole('status').filter({ hasText: /bereits vollständig übernommen/ })).toBeVisible();
    });

    test('Private Einlage läuft linear über die Nutzungsdauer hinaus (EUE-055)', { tag: ['@feature:eur-asset-directories'] }, async ({ page }) => {
        const headers = await login(page);
        const name = `EUE055-Fortlauf-${Date.now()}`;
        const created = await page.request.post('/api/management/eur/assets', {
            headers,
            data: {
                name,
                invoice_no: `R-${name}`,
                supplier: 'Lieferant',
                purchase_date: '2024-01-15',
                cost_cents: 100000,
                useful_life_years: 2,
                business_share: 25,
                method: 'linear',
            },
        });
        expect(created.status()).toBe(201);
        const asset = (await created.json() as { asset: { id: number; scope: string } }).asset;
        expect(asset.scope).toBe('private');

        const firstPlan = await page.request.get(`/api/management/eur/assets/${asset.id}/plan`, { headers });
        expect(firstPlan.ok()).toBe(true);
        const firstBody = await firstPlan.json() as {
            asset: { years: Array<{ year: number; eigenbeleg_cents: number | null }> };
        };
        expect(firstBody.asset.years.map((row) => row.year)).toEqual([2024, 2025]);
        // 100000 ct / 2 Jahre = 50000 ct theoretisch; 25 % betrieblich = 12500 ct.
        expect(firstBody.asset.years.every((row) => row.eigenbeleg_cents === 12500)).toBe(true);

        const panel = await openAssets(page);
        await panel.getByRole('button', { name: 'Privateinlagen' }).click();
        const row = panel.getByRole('row').filter({ hasText: name });
        await expect(row).toBeVisible();
        await expect(row.getByText('Eigenbeleg offen')).toBeVisible();

        const carryOver = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith('/api/management/eur/assets/carry-over')
            && response.ok());
        await panel.getByRole('button', { name: 'Jahr übernehmen' }).click();
        await carryOver;

        const nextPlan = await page.request.get(`/api/management/eur/assets/${asset.id}/plan`, { headers });
        expect(nextPlan.ok()).toBe(true);
        const nextBody = await nextPlan.json() as {
            asset: { years: Array<{ year: number; eigenbeleg_cents: number | null; eigenbeleg_no: string | null }> };
        };
        const continuation = nextBody.asset.years.find((entry) => entry.year === 2026);
        expect(continuation).toBeDefined();
        expect(continuation?.eigenbeleg_cents).toBe(12500);
        expect(continuation?.eigenbeleg_no).toMatch(/^EB-/);
        await expect(row.getByText(/Eigenbeleg EB-/)).toBeVisible();
    });

    test('Beleg am Stamm anhängen und lösen', { tag: ['@feature:eur-asset-directories'] }, async ({ page }) => {
        const headers = await login(page);
        const name = `V071-Beleg-${Date.now()}`;
        const asset = await createAsset(page, headers, name, 100, year);

        const correction = await page.request.post('/api/management/eur/documents/corrections', {
            headers,
            data: { kind: 'assets', year },
        });
        expect(correction.status()).toBe(201);
        const document = (await correction.json() as { document: { id: number; original_name: string; sha256: string } }).document;
        // Die API liefert nur Metadaten — nie einen Speicherpfad.
        expect(document.original_name).toBeTruthy();
        expect(document.sha256).toBeTruthy();

        // Erst nach allen Fixtures mounten: SWR lädt die Listen genau einmal.
        const panel = await openAssets(page);
        const row = panel.getByRole('row').filter({ hasText: name });
        await expect(row).toBeVisible();
        await row.getByRole('combobox').selectOption({ label: document.original_name });

        const attached = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith(`/api/management/eur/assets/${asset.id}/documents`)
            && [200, 201].includes(response.status()));
        await row.getByRole('button', { name: 'Beleg anhängen' }).click();
        expect((await attached).ok()).toBe(true);
        await expect(panel.getByRole('status').filter({ hasText: document.original_name })).toBeVisible();

        const attachedList = row.getByTestId('eur-asset-documents');
        await expect(attachedList.getByText(document.original_name)).toBeVisible({ timeout: 10_000 });
        const detached = page.waitForResponse((response) =>
            response.request().method() === 'DELETE'
            && response.url().endsWith(`/api/management/eur/assets/${asset.id}/documents/${document.id}`));
        await row.getByRole('button', { name: 'Lösen' }).click();
        expect((await detached).ok()).toBe(true);
        await expect(panel.getByRole('status').filter({ hasText: /gelöst/ })).toBeVisible();
    });
});
