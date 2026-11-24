import { test, expect, type Page, type Locator } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * Portal #59 / D-90 — both directions of the document↔item link, driven through
 * the real row editor:
 * (1) SPLIT one receipt into two independently booked assets (two positions,
 *     different amounts and business shares);
 * (2) MERGE two receipts into one asset group (one billing unit).
 *
 * History: CI 45454744485 failed this spec because the row editor's accessible
 * field names carry the CSS `*` of the required-marker (`Name *`), so
 * `getByRole(..., {name: 'Name', exact: true})` never resolved; CI 45457039372
 * then failed on the success message, which never appeared although the form was
 * filled and submitted. Cause (found in `EurDocumentsPanel`): `onSplit` calls
 * `createEurDocumentPositions`, whose `revalidateEur()` runs
 * `globalMutate(filter, undefined, {revalidate: true})`. SWR writes that
 * `undefined` into the cache, so every EAR hook has no data while the refetch is
 * in flight and the panel replaced itself with the loading card — unmounting the
 * row (and the `message` state it had just set). The panel now keeps the last
 * loaded list on screen during a background revalidation. The form locators are
 * the react-hook-form field names, the share selects are set explicitly, and the
 * submit is anchored on the POST that must result from it.
 *
 * All locators are scoped to <main> and the document's own row (D-10).
 * Documents are created via the real upload endpoint with distinct byte content
 * (upload is idempotent by sha256), the group via the real group endpoint.
 * E2E runs in CI only on this host (RAM policy).
 */
const PNG_A = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9WnU6n8AAAAASUVORK5CYII=',
    'base64',
);
const PNG_B = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

interface SplitPositionInput {
    name: string;
    amountCents: string;
    businessShare: string;
    usefulLifeYears: string;
    label?: string;
}

interface PositionsResponse {
    document: {id: number; total_cents: number | null; allocated_cents: number; remaining_cents: number | null};
    positions: Array<{asset_id: number; amount_cents: number | null; ordinal: number | null}>;
}

test.describe('EAR Belege — Positionen und Gruppen (#59)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = {email: '', password: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function login(page: Page): Promise<Record<string, string>> {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        return {...await browserAuthHeadersFromPage(page), 'Accept': 'application/json'};
    }

    async function openBelege(page: Page): Promise<Locator> {
        await new SidebarHelper(page).navigateTo('EA Rechnung');
        await page.getByRole('tab', {name: 'Belege', exact: true}).click();
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Belege', exact: true})).toBeVisible();
        return main;
    }

    async function upload(page: Page, headers: Record<string, string>, name: string, bytes: Buffer) {
        // Unique bytes per attempt: the upload is idempotent by sha256, so a
        // retry with identical bytes would return the previous document (200)
        // instead of a fresh one (201). Both statuses are valid.
        const unique = Buffer.concat([bytes, Buffer.from(`\nnonce-${Date.now()}-${Math.random()}`)]);
        const response = await page.request.post('/api/management/eur/documents', {
            headers,
            multipart: {
                file: {name: `${name}.png`, mimeType: 'image/png', buffer: unique},
                year: '2026',
                month: '3',
                receipt_no: name,
            },
        });
        expect([200, 201]).toContain(response.status());
        return (await response.json() as {document: {id: number}}).document;
    }

    function documentCell(main: Locator, id: number): Locator {
        return main.getByTestId(`eur-document-positions-${id}`);
    }

    /** The row editor's confirmation line (`role="status"`) of one document. */
    function rowStatus(cell: Locator): Locator {
        return cell.getByTestId('eur-document-positions-status');
    }

    async function fillPosition(cell: Locator, index: number, position: SplitPositionInput) {
        const row = cell.getByTestId(`eur-document-position-${index}`);
        await expect(row).toBeVisible();
        await row.locator(`input[name="positions.${index}.name"]`).fill(position.name);
        await row.locator(`input[name="positions.${index}.amountCents"]`).fill(position.amountCents);
        // Explicit, like a user picking the share — never rely on the default.
        const share = row.locator(`select[name="positions.${index}.businessShare"]`);
        await share.selectOption(position.businessShare);
        await expect(share).toHaveValue(position.businessShare);
        await row.locator(`input[name="positions.${index}.usefulLifeYears"]`).fill(position.usefulLifeYears);
        if (position.label !== undefined) {
            await row.locator(`input[name="positions.${index}.label"]`).fill(position.label);
        }
    }

    /**
     * Drives the real row form and anchors the click on the POST it must cause:
     * if the form silently refuses to submit, the failure names the endpoint
     * instead of only reporting a missing message.
     */
    async function splitDocument(
        page: Page,
        cell: Locator,
        documentId: number,
        totalCents: string,
        positions: SplitPositionInput[],
    ) {
        await cell.locator('input[name="totalCents"]').fill(totalCents);
        for (const [index, position] of positions.entries()) {
            if (index > 0) {
                await cell.getByRole('button', {name: 'Position hinzufügen'}).click();
            }
            await fillPosition(cell, index, position);
        }

        const submitted = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && new URL(response.url()).pathname === `/api/management/eur/documents/${documentId}/positions`, {timeout: 20_000});
        await cell.getByRole('button', {name: 'Beleg aufteilen'}).click();
        expect((await submitted).status()).toBe(201);

        // The confirmation is the row's live region — assert both its role (it
        // must be announced) and its text.
        await expect(rowStatus(cell)).toHaveAttribute('role', 'status');
        await expect(rowStatus(cell)).toContainText('aufgeteilt');
        if (positions.length > 1) {
            // The count is part of the message; the singular form is not
            // asserted because the current German string does not inflect.
            await expect(rowStatus(cell)).toContainText(`in ${positions.length} Positionen aufgeteilt`);
        }
        // The row switches to the already-split state once the refetch lands.
        await expect(cell.getByText(/Dieser Beleg ist bereits aufgeteilt/)).toBeVisible();
        const list = cell.getByTestId(`eur-document-position-list-${documentId}`);
        await expect(list.locator('li')).toHaveCount(positions.length);
        for (const position of positions) {
            // The list shows the label when one was entered, the asset name otherwise.
            await expect(list).toContainText(position.label ?? position.name);
        }
    }

    async function assignToGroup(cell: Locator, groupId: number, groupName: string) {
        const select = cell.getByTestId('eur-document-group-select');
        await expect(select).toContainText(groupName);
        await select.selectOption(String(groupId));
        await cell.getByRole('button', {name: 'Zuordnen', exact: true}).click();
        await expect(rowStatus(cell)).toContainText('der Gruppe zugeordnet');
    }

    test('ein Beleg auf zwei unabhängige Positionen, zwei Belege in eine Gruppe', {tag: ['@feature:eur-document-positions']}, async ({page}) => {
        const headers = await login(page);
        const suffix = Date.now();
        const docA = await upload(page, headers, `Beleg-A-${suffix}`, PNG_A);
        const docB = await upload(page, headers, `Beleg-B-${suffix}`, PNG_B);

        const groupName = `Set-${suffix}`;
        const groupResponse = await page.request.post('/api/management/eur/asset-groups', {
            headers,
            data: {name: groupName, business_share: 30},
        });
        expect(groupResponse.status()).toBe(201);
        const groupId = (await groupResponse.json() as {group: {id: number}}).group.id;

        const main = await openBelege(page);
        const cellA = documentCell(main, docA.id);
        const cellB = documentCell(main, docB.id);
        await expect(cellA).toBeVisible();
        await expect(cellB).toBeVisible();

        // (1) SPLIT: one receipt → two independently booked assets.
        const objective = `Objektiv-${suffix}`;
        const accessory = `Zubehör-${suffix}`;
        await splitDocument(page, cellA, docA.id, '300000', [
            {name: objective, amountCents: '200000', businessShare: '100', usefulLifeYears: '3', label: 'Body'},
            {name: accessory, amountCents: '100000', businessShare: '75', usefulLifeYears: '3'},
        ]);
        await expect(cellA).toContainText(/verteilt:\s*3\.000,00/);
        await expect(cellA).toContainText(/offen:\s*0,00/);

        const positionsA = await page.request.get(`/api/management/eur/documents/${docA.id}/positions`, {headers});
        expect(positionsA.ok()).toBe(true);
        const splitA = await positionsA.json() as PositionsResponse;
        expect(splitA.positions).toHaveLength(2);
        expect(splitA.positions.map((position) => position.amount_cents)).toEqual([200000, 100000]);
        expect(splitA.document.total_cents).toBe(300000);
        expect(splitA.document.allocated_cents).toBe(300000);
        expect(splitA.document.remaining_cents).toBe(0);

        // The share the user picked in each row is what the assets carry.
        const assetsResponse = await page.request.get('/api/management/eur/assets', {headers});
        expect(assetsResponse.ok()).toBe(true);
        const assets = (await assetsResponse.json() as {
            assets: Array<{name: string; business_share: number; cost_cents: number}>;
        }).assets;
        const splitAssets = assets.filter((asset) => asset.name === objective || asset.name === accessory);
        expect(splitAssets).toHaveLength(2);
        expect(splitAssets.find((asset) => asset.name === objective)?.business_share).toBe(100);
        expect(splitAssets.find((asset) => asset.name === accessory)?.business_share).toBe(75);

        // (2) MERGE: the second receipt is split as well, then both receipts
        // are assigned to the same group through the row editor.
        const shutter = `Blende-${suffix}`;
        await splitDocument(page, cellB, docB.id, '240000', [
            {name: shutter, amountCents: '240000', businessShare: '60', usefulLifeYears: '3'},
        ]);
        const positionsB = await page.request.get(`/api/management/eur/documents/${docB.id}/positions`, {headers});
        expect(positionsB.ok()).toBe(true);
        const splitB = await positionsB.json() as PositionsResponse;
        expect(splitB.positions).toHaveLength(1);
        expect(splitB.document.allocated_cents).toBe(240000);
        expect(splitB.document.remaining_cents).toBe(0);

        await assignToGroup(cellA, groupId, groupName);
        await assignToGroup(cellB, groupId, groupName);
        // The row keeps working: the message is still readable after the
        // post-write refetch (the regression that removed this spec).
        await expect(rowStatus(cellB)).toContainText('der Gruppe zugeordnet');

        const updated = await page.request.get('/api/management/eur/asset-groups', {headers});
        expect(updated.ok()).toBe(true);
        const matched = (await updated.json() as {
            groups: Array<{
                id: number;
                name: string;
                assets: Array<{id: number; name: string; cost_cents: number}>;
                asset_ids: number[];
                document_ids?: number[];
                years: Array<{year: number; eigenbeleg_cents: number | null; usage_percent: number | null}>;
            }>;
        }).groups.find((entry) => entry.id === groupId);
        if (!matched) throw new Error(`group ${groupName} is missing from GET /api/management/eur/asset-groups`);
        expect(matched.name).toBe(groupName);
        // One billing unit: the three assets of both receipts.
        expect(matched.assets.map((asset) => asset.name).sort()).toEqual([objective, accessory, shutter].sort());
        expect(matched.asset_ids).toHaveLength(3);
        const ascending = (a: number, b: number): number => a - b;
        expect([...(matched.document_ids ?? [])].sort(ascending)).toEqual([docA.id, docB.id].sort(ascending));
        // Private group (< 50 % share) bills one Eigenbeleg per supported year;
        // the receipt is filed for 2026, so that is the single 2026 row.
        // (200000 + 100000 + 240000) / 3 years × 30 % group share = 54000 ct.
        expect(matched.years).toHaveLength(1);
        expect(matched.years[0]).toMatchObject({year: 2026, eigenbeleg_cents: 54000, usage_percent: 30});
    });
});
