import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Digital Contracts Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };
    let uniqueSuffix = '';

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
        uniqueSuffix = Math.random().toString(36).substring(2, 10);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin creates, opens a contract via UI and sees join link', { tag: ['@feature:admin:contracts'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);

        // Navigate to Verträge
        await sidebar.navigateTo('Verträge');
        await expect(page).toHaveURL(/.*\/admin-contracts/);

        // Click "Neuer Vertrag" to show the form
        await page.getByRole('button', { name: 'Neuer Vertrag' }).click();
        await expect(page.locator('h2').filter({ hasText: 'Rollen' })).toBeVisible({ timeout: 5000 });

        // Add a role
        const roleInput = page.getByPlaceholder('z.B. Fotograf, Model, Agentur');
        await expect(roleInput).toBeVisible({ timeout: 5000 });
        await roleInput.fill('Model');
        await page.getByRole('button', { name: '+ Hinzufügen' }).click();

        // Verify the role badge appears
        await expect(page.locator('.badge').filter({ hasText: 'Model' })).toBeVisible({ timeout: 5000 });

        // Fill the default item
        const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
        await expect(itemTitleInput).toBeVisible({ timeout: 5000 });
        await itemTitleInput.fill(`Fotoshooting ${uniqueSuffix}`);

        // Fill in price
        const priceInput = page.locator('.form-control').filter({ hasText: 'Preis / Stück' }).locator('input').first();
        await priceInput.fill('250');

        // Fill in terms via Tiptap editor
        const editor = page.locator('.ProseMirror').first();
        await expect(editor).toBeVisible({ timeout: 5000 });
        await editor.click();
        await editor.fill(`Testvertragsinhalt ${uniqueSuffix}`);

        // Save the contract
        await page.getByRole('button', { name: 'Vertrag erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Vertrag wurde erstellt', { timeout: 15000 });

        // Open the signing period
        await page.getByRole('button', { name: 'Vertragsperiode starten' }).click();

        // Confirm the modal dialog
        await page.getByRole('button', { name: 'Starten', exact: true }).click();

        // Verify join link is displayed
        await expect(page.locator('input[readonly]')).toHaveValue(/\/contracts\/join\//, { timeout: 10000 });
    });

    test('Two clients join and sign a contract via API, then admin closes it', { tag: ['@feature:admin:contracts'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);

        await auth.login(testUser.email, testUser.password);

        // Create contract via API
        const createRes = await request.post('/api/management/contracts', {
            data: {
                available_roles: ['Model', 'Fotograf'],
                allow_multiple_roles_per_signer: true,
                terms_html: `<p>Testvertrag ${uniqueSuffix}</p>`,
                items: [
                    { type: 'item', description: 'Fotoshooting', qty: 1, price: 20000, notes: '' },
                ],
                discounts: [],
            },
            headers: {
                'Cookie': helper.getAdminToken(),
                'Accept': 'application/json',
            },
        });
        expect(createRes.ok()).toBeTruthy();
        const contractData = await createRes.json();
        const contractId = contractData.contract?.id;
        expect(contractId).toBeDefined();
        helper.trackContract(contractId);

        // Open the contract
        const openRes = await request.post(`/api/management/contracts/${contractId}/open`, {
            headers: {
                'Cookie': helper.getAdminToken(),
                'Accept': 'application/json',
            },
        });
        expect(openRes.ok()).toBeTruthy();
        const openData = await openRes.json();
        const joinLink = openData.join_link;
        expect(joinLink).toBeDefined();

        // Extract join token from link
        const joinToken = joinLink.split('/contracts/join/')[1];
        expect(joinToken).toBeDefined();

        // CLIENT 1: Join and sign
        const join1Res = await request.post(`/api/contracts/join/${joinToken}`, {
            data: {
                name: 'Anna Model',
                email: `anna-${uniqueSuffix}@example.com`,
                roles: ['Model'],
            },
            headers: { 'Accept': 'application/json' },
        });
        expect(join1Res.ok()).toBeTruthy();
        const join1Data = await join1Res.json();
        const token1 = join1Data.personal_token;
        expect(token1).toBeDefined();

        // Fetch content_version before signing
        const content1Res = await request.get(`/api/contracts/sign/${token1}`, {
            headers: { 'Accept': 'application/json' },
        });
        expect(content1Res.ok()).toBeTruthy();
        const content1Data = await content1Res.json();
        const contentVersion1 = content1Data.contract.content_version;

        const sign1Res = await request.post(`/api/contracts/sign/${token1}`, {
            data: { accept_contract: true, content_version: contentVersion1 },
            headers: { 'Accept': 'application/json' },
        });
        expect(sign1Res.ok()).toBeTruthy();

        // CLIENT 2: Join and sign
        const join2Res = await request.post(`/api/contracts/join/${joinToken}`, {
            data: {
                name: 'Ben Fotograf',
                email: `ben-${uniqueSuffix}@example.com`,
                roles: ['Fotograf'],
            },
            headers: { 'Accept': 'application/json' },
        });
        expect(join2Res.ok()).toBeTruthy();
        const join2Data = await join2Res.json();
        const token2 = join2Data.personal_token;
        expect(token2).toBeDefined();

        // Fetch content_version before signing
        const content2Res = await request.get(`/api/contracts/sign/${token2}`, {
            headers: { 'Accept': 'application/json' },
        });
        expect(content2Res.ok()).toBeTruthy();
        const content2Data = await content2Res.json();
        const contentVersion2 = content2Data.contract.content_version;

        const sign2Res = await request.post(`/api/contracts/sign/${token2}`, {
            data: { accept_contract: true, content_version: contentVersion2 },
            headers: { 'Accept': 'application/json' },
        });
        expect(sign2Res.ok()).toBeTruthy();

        // ADMIN: Close the contract
        const closeRes = await request.post(`/api/management/contracts/${contractId}/close`, {
            headers: {
                'Cookie': helper.getAdminToken(),
                'Accept': 'application/json',
            },
        });
        expect(closeRes.ok()).toBeTruthy();
        const closeData = await closeRes.json();
        expect(closeData.success).toBe(true);
        expect(closeData.contract.status).toBe('closed');
    });

test('Admin edits active contract, signer is blocked from signing stale version', { tag: ['@feature:admin:contracts'] }, async ({ page, request }) => {
    const auth = new AuthHelper(page);
    await auth.login(testUser.email, testUser.password);

    // Create contract via API
    const createRes = await request.post('/api/management/contracts', {
        data: {
            available_roles: ['Model'],
            allow_multiple_roles_per_signer: false,
            terms_html: `<p>Version 1 - ${uniqueSuffix}</p>`,
            items: [],
            discounts: [],
        },
        headers: {
            'Cookie': helper.getAdminToken(),
            'Accept': 'application/json',
        },
    });
    expect(createRes.ok()).toBeTruthy();
    const contractData = await createRes.json();
    const contractId = contractData.contract?.id;
    expect(contractId).toBeDefined();
    helper.trackContract(contractId);

    // Open the contract (start signing period)
    const openRes = await request.post(`/api/management/contracts/${contractId}/open`, {
        headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' },
    });
    expect(openRes.ok()).toBeTruthy();
    const openData = await openRes.json();
    const joinLink = openData.join_link;
    expect(joinLink).toBeDefined();
    const joinToken = joinLink.split('/contracts/join/')[1];

    // Client joins
    const joinRes = await request.post(`/api/contracts/join/${joinToken}`, {
        data: { name: 'Test User', email: `test-${uniqueSuffix}@example.com`, roles: ['Model'] },
        headers: { 'Accept': 'application/json' },
    });
    expect(joinRes.ok()).toBeTruthy();
    const joinData = await joinRes.json();
    const personalToken = joinData.personal_token;

    // Client opens sign page
    await page.goto(`/contracts/sign/${personalToken}`);
    await expect(page.locator('h1').filter({ hasText: 'Vertrag' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.editor-content')).toContainText(`Version 1 - ${uniqueSuffix}`, { timeout: 10000 });

    // Admin edits the contract while signer has it open (increments content_version)
    const editRes = await request.put(`/api/management/contracts/${contractId}`, {
        data: { terms_html: `<p>Version 2 - ${uniqueSuffix}</p>` },
        headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' },
    });
    expect(editRes.ok()).toBeTruthy();

    // Wait for heartbeat (5s interval) to detect staleness
    await expect(page.locator('.alert.alert-warning')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.alert.alert-warning')).toContainText('Vertrag wurde geändert');

    // Verify sign button is disabled while stale
    await expect(page.getByRole('button', { name: 'Vertrag verbindlich abschließen' })).toBeDisabled();

    // Reload the page to get updated content
    await page.goto(`/contracts/sign/${personalToken}`);
    await expect(page.locator('.editor-content')).toContainText(`Version 2 - ${uniqueSuffix}`, { timeout: 10000 });

    // Stale warning should be gone after reload
    await expect(page.locator('.alert.alert-warning')).toBeHidden({ timeout: 5000 });

    // Sign with the new version works
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Vertrag verbindlich abschließen' }).click();
    await expect(page.locator('h2').filter({ hasText: 'Vertrag unterschrieben!' })).toBeVisible({ timeout: 10000 });
});
});
