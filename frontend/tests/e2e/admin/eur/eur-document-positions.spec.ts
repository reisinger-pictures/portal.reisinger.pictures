import { test, expect, type Page, type Locator } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * Portal #59 / D-90 — both directions of the document↔item link:
 * (1) SPLIT one receipt into two independently booked assets via the UI;
 * (2) MERGE two receipts into one asset group (one billing unit).
 *
 * All locators are scoped to <main> and the document's own table row (D-10).
 * Documents are created via the real upload endpoint with distinct byte
 * content (upload is idempotent by sha256). E2E runs in CI only on this host
 * (RAM policy).
 */
const PNG_A = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnU6n8AAAAASUVORK5CYII=',
    'base64',
);
const PNG_B = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

test.describe('EAR Belege — Positionen und Gruppen (#59)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function login(page: Page): Promise<Record<string, string>> {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        return { ...await browserAuthHeadersFromPage(page), 'Accept': 'application/json' };
    }

    async function openBelege(page: Page): Promise<Locator> {
        await new SidebarHelper(page).navigateTo('EA Rechnung');
        await page.getByRole('tab', { name: 'Belege', exact: true }).click();
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Belege', exact: true })).toBeVisible();
        return main;
    }

    async function upload(page: Page, headers: Record<string, string>, name: string, bytes: Buffer) {
        const response = await page.request.post('/api/management/eur/documents', {
            headers,
            multipart: {
                file: { name: `${name}.png`, mimeType: 'image/png', buffer: bytes },
                year: '2026',
                month: '3',
                receipt_no: name,
            },
        });
        expect(response.status()).toBe(201);
        return (await response.json() as { document: { id: number } }).document;
    }

    function documentCell(main: Locator, id: number): Locator {
        return main.getByTestId(`eur-document-positions-${id}`);
    }

    async function splitOnce(
        cell: Locator,
        name: string,
        amount: string,
        total: string,
        addSecond = false,
    ) {
        await cell.getByRole('spinbutton', { name: 'Belegbetrag in Cent', exact: true }).fill(total);
        const first = cell.locator('form fieldset > div').first();
        await first.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
        await first.getByRole('spinbutton', { name: 'Betrag in Cent', exact: true }).fill(amount);
        await first.getByRole('spinbutton', { name: 'Nutzungsdauer in Jahren', exact: true }).fill('3');
        if (addSecond) {
            await cell.getByRole('button', { name: 'Position hinzufügen' }).click();
            const second = cell.locator('form fieldset > div').nth(1);
            await second.getByRole('textbox', { name: 'Name', exact: true }).fill(`${name}-Zubehör`);
            await second.getByRole('spinbutton', { name: 'Betrag in Cent', exact: true }).fill('100000');
            await second.getByRole('spinbutton', { name: 'Nutzungsdauer in Jahren', exact: true }).fill('3');
        }
        await cell.getByRole('button', { name: 'Beleg aufteilen' }).click();
        await expect(cell.getByRole('status')).toContainText('aufgeteilt');
    }

    test('ein Beleg auf zwei unabhängige Positionen, zwei Belege in eine Gruppe', { tag: ['@feature:eur-document-positions'] }, async ({ page }) => {
        const headers = await login(page);
        const suffix = Date.now();
        const docA = await upload(page, headers, `Beleg-A-${suffix}`, PNG_A);
        const docB = await upload(page, headers, `Beleg-B-${suffix}`, PNG_B);

        const groupResponse = await page.request.post('/api/management/eur/asset-groups', {
            headers,
            data: { name: `Set-${suffix}`, business_share: 30 },
        });
        expect(groupResponse.status()).toBe(201);
        const groupId = (await groupResponse.json() as { group: { id: number } }).group.id;

        const main = await openBelege(page);

        // (1) SPLIT: ein Beleg → zwei Güter, Summe und Rest aus der API.
        const cellA = documentCell(main, docA.id);
        await expect(cellA).toBeVisible();
        await splitOnce(cellA, `Objektiv-${suffix}`, '200000', '300000', true);

        const positions = await page.request.get(`/api/management/eur/documents/${docA.id}/positions`, { headers });
        expect(positions.ok()).toBe(true);
        const split = await positions.json() as {
            document: { total_cents: number; allocated_cents: number; remaining_cents: number | null };
            positions: Array<{ asset_id: number; amount_cents: number }>;
        };
        expect(split.positions).toHaveLength(2);
        expect(split.document.total_cents).toBe(300000);
        expect(split.document.allocated_cents).toBe(300000);
        expect(split.document.remaining_cents).toBe(0);
        await expect(cellA.getByText(/verteilt:.*offen:/)).toBeVisible();

        // (2) MERGE: der zweite Beleg wird ebenfalls aufgeteilt und beide
        // Belege werden derselben Gruppe zugeordnet.
        const cellB = documentCell(main, docB.id);
        await expect(cellB).toBeVisible();
        await splitOnce(cellB, `Blende-${suffix}`, '240000', '240000');

        for (const cell of [cellA, cellB]) {
            await cell.getByLabel('Beleg einer Gruppe zuordnen').selectOption(String(groupId));
            await cell.getByRole('button', { name: 'Zuordnen' }).click();
            await expect(cell.getByRole('status')).toContainText('Gruppe zugeordnet');
        }

        const updated = await page.request.get('/api/management/eur/asset-groups', { headers });
        expect(updated.ok()).toBe(true);
        const group = (await updated.json() as {
            groups: Array<{ id: number; asset_ids: number[]; document_ids: number[]; years: Array<{ eigenbeleg_cents: number }> }>;
        }).groups.find((entry) => entry.id === groupId);
        expect(group?.asset_ids).toHaveLength(3);
        expect(group?.document_ids.sort()).toEqual([docA.id, docB.id].sort());
        expect(group?.years).toHaveLength(3);
        // (200000 + 100000 + 240000) / 3 Jahre × 30 % Gruppenanteil = 54000 ct.
        expect(group?.years.every((row) => row.eigenbeleg_cents === 54000)).toBe(true);
    });
});
