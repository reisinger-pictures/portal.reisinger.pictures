import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * Portal #57 / D-89: eine Anlagegruppe ist eine Abrechnungseinheit. Der Test
 * legt zwei getrennt gekaufte Güter per API an, erstellt eine private Gruppe
 * über die UI, ordnet beide Güter zu und prüft den einen Eigenbeleg je Jahr.
 * Alle Locators sind auf <main> und den Panel-testid gescopt (D-10). E2E läuft
 * hostbedingt ausschließlich in CI (RAM-Policy).
 */
test.describe('EAR · Anlagegruppen (D-89)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function login(page: Page) {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        return {...await browserAuthHeadersFromPage(page), 'Accept': 'application/json'};
    }

    async function openGroups(page: Page) {
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('EA Rechnung');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();
        const panel = page.getByRole('main').getByTestId('eur-asset-groups-panel');
        await expect(panel.getByRole('heading', { name: 'Anlagegruppen' })).toBeVisible();
        return panel;
    }

    async function createAsset(page: Page, headers: Record<string, string>, name: string, costCents: number) {
        const response = await page.request.post('/api/management/eur/assets', {
            headers,
            data: {
                name,
                invoice_no: `R-${name}`,
                supplier: 'Lieferant',
                purchase_date: '2024-01-15',
                cost_cents: costCents,
                useful_life_years: 3,
                business_share: 100,
                method: 'linear',
            },
        });
        expect(response.status()).toBe(201);
        return (await response.json() as { asset: { id: number } }).asset;
    }

    test('private Gruppe bündelt zwei Güter zu einem Eigenbeleg je Jahr', { tag: ['@feature:eur-asset-groups'] }, async ({ page }) => {
        const headers = await login(page);
        const suffix = Date.now();
        const lens = await createAsset(page, headers, `Objektiv-${suffix}`, 240000);
        const hood = await createAsset(page, headers, `Streulichtblende-${suffix}`, 60000);

        const panel = await openGroups(page);
        const groupName = `Set-${suffix}`;
        // Der Label-Text enthält alle Options-Texte, daher ist der accessible
        // Name der Selects nie exakt "Betriebsanteil". Scope auf das einzige
        // <form> im Panel (die Karten-Selects liegen in keinem Formular).
        const createForm = panel.locator('form');
        await createForm.locator('input[type="text"]').fill(groupName);
        await createForm.locator('select').selectOption('30');
        await createForm.getByRole('button', { name: 'Gruppe anlegen' }).click();

        const groupsResponse = await page.request.get('/api/management/eur/asset-groups', { headers });
        expect(groupsResponse.ok()).toBe(true);
        const groups = await groupsResponse.json() as {
            groups: Array<{ id: number; name: string; scope: string }>;
        };
        const group = groups.groups.find((entry) => entry.name === groupName);
        expect(group).toBeDefined();
        expect(group?.scope).toBe('private');

        const card = panel.getByTestId(`eur-asset-group-${group?.id}`);
        await expect(card).toBeVisible();
        await expect(card.getByText('Privat')).toBeVisible();

        for (const asset of [lens, hood]) {
            await card.getByLabel('Anlagegut zuordnen').selectOption(String(asset.id));
            await card.getByRole('button', { name: 'Zuordnen' }).click();
        }

        await expect(card.getByText('2 Anlagegüter')).toBeVisible();
        await expect(card.getByText(/Eigenbeleg EBG-/).first()).toBeVisible();

        const after = await page.request.get('/api/management/eur/asset-groups', { headers });
        const afterBody = await after.json() as {
            groups: Array<{
                id: number;
                asset_ids: number[];
                years: Array<{ year: number; eigenbeleg_cents: number; usage_percent: number }>;
            }>;
        };
        const updated = afterBody.groups.find((entry) => entry.id === group?.id);
        expect(updated?.asset_ids.sort()).toEqual([lens.id, hood.id].sort());
        // (240000/3 + 60000/3) × 30 % = 30000 ct je Jahr, drei Jahre (2024–2026).
        expect(updated?.years).toHaveLength(3);
        expect(updated?.years.every((row) => row.eigenbeleg_cents === 30000)).toBe(true);
        expect(updated?.years.every((row) => row.usage_percent === 30)).toBe(true);
    });
});
