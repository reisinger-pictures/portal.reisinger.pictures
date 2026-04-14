import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Manual Documents & CRM Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };
    let createdCustomerId = '';
    let createdSnippetId = '';
    let uniqueSuffix = '';
    let snippetShortcut = '';

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
        
        uniqueSuffix = Math.random().toString(36).substring(2, 10);
        snippetShortcut = `snip${uniqueSuffix}`;

        const custRes = await request.post('/api/management/customers', {
            data: { name: `E2E VIP ${uniqueSuffix}`, zip: '1010', city: 'Wien' },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const custData = await custRes.json();
        if (custData?.customer?.id) createdCustomerId = custData.customer.id;

        const snipRes = await request.post('/api/management/text-snippets', {
            data: { title: `E2E Snippet ${uniqueSuffix}`, shortcut: snippetShortcut, content_html: `<p>Magic${uniqueSuffix}Content</p>` },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const snipData = await snipRes.json();
        if (snipData?.snippet?.id) createdSnippetId = snipData.snippet.id;
    });

    test.afterEach(async ({ request }) => {
        if (createdCustomerId) {
            await request.delete(`/api/management/customers/${createdCustomerId}`, {
                headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
            }).catch(() => {});
        }
        if (createdSnippetId) {
            await request.delete(`/api/management/text-snippets/${createdSnippetId}`, {
                headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
            }).catch(() => {});
        }
        if (helper) await helper.teardown();
    });

    test('Super Admin uses CRM autocomplete, Tiptap shortcuts and generates PDF Offer', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Manuelles Angebot');
        await expect(page).toHaveURL(/.*\/admin-manual-offer/);
        await expect(page.locator('h1:has-text("Manuelles Angebot")')).toBeVisible();

        // 1. Test CRM Autocomplete
        const nameInput = page.locator('.form-control').filter({ hasText: 'Name / Ansprechpartner' }).locator('input');
        await nameInput.fill(`E2E VIP ${uniqueSuffix}`);
        const autocompleteDropdown = page.locator(`li:has-text("E2E VIP ${uniqueSuffix}")`).first();
        await expect(autocompleteDropdown).toBeVisible({ timeout: 15000 });
        await autocompleteDropdown.click();

        const zipInput = page.locator('.form-control').filter({ hasText: 'PLZ & Stadt' }).locator('input').nth(0);
        const cityInput = page.locator('.form-control').filter({ hasText: 'PLZ & Stadt' }).locator('input').nth(1);
        await expect(zipInput).toHaveValue('1010');
        await expect(cityInput).toHaveValue('Wien');

        // 2. Test Tiptap Shortcut Injection (Slash Command Menu)
        const editor = page.locator('.ProseMirror').first();
        await editor.click();
        await editor.pressSequentially(`/${snippetShortcut}`, { delay: 50 }); 
        
        // Auf das Aufploppen des Autocomplete-Menüs warten
        const slashMenu = page.locator('.menu').filter({ hasText: 'Textbaustein einfügen' });
        await expect(slashMenu).toBeVisible();
        await expect(slashMenu.locator('a').first()).toBeVisible();

        // Echte Nutzer-Interaktion: Mit Enter bestätigen
        await page.keyboard.press('Enter'); 
        
        await expect(editor).toContainText(`Magic${uniqueSuffix}Content`, { timeout: 10000 });

        // 3. Leistungen befüllen
        await page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first().fill('E2E Test Position');
        await page.locator('.form-control').filter({ hasText: 'Menge' }).locator('input').first().fill('2');
        await page.locator('.form-control').filter({ hasText: 'Preis / Stück' }).locator('input').first().fill('250');

        // 4. PDF Generierung & Download Interception
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'PDF Generieren' }).click()
        ]);

        expect(download.suggestedFilename()).toMatch(/^Angebot-.*\.pdf$/);
        
        const downloadPath = await download.path();
        const buffer = fs.readFileSync(downloadPath!);
        
        // Check for PDF Magic Bytes (0x25 0x50 0x44 0x46 -> %PDF)
        expect(buffer.subarray(0, 4).toString('hex')).toBe('25504446');
    });

    test('Mobile UI: CRM Autocomplete and Tiptap Shortcuts work on small touch screens', async ({ page }) => {
        // Setze Viewport explizit auf Mobile (z.B. iPhone 12)
        await page.setViewportSize({ width: 390, height: 844 });
        
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Manuelles Angebot');
        await expect(page).toHaveURL(/.*\/admin-manual-offer/);

        // 1. Mobile CRM Autocomplete Touch-Test
        const nameInput = page.locator('.form-control').filter({ hasText: 'Name / Ansprechpartner' }).locator('input');
        await nameInput.click(); // Touch Simulierung
        await nameInput.fill(`E2E VIP ${uniqueSuffix}`);
        const autocompleteDropdown = page.locator(`li:has-text("E2E VIP ${uniqueSuffix}")`).first();
        await expect(autocompleteDropdown).toBeVisible({ timeout: 15000 });
        await autocompleteDropdown.click();

        // 2. Mobile Tiptap & Slash Menu Check
        const editor = page.locator('.ProseMirror').first();
        await editor.click();
        await editor.pressSequentially(`/${snippetShortcut}`, { delay: 50 });
        
        const slashMenu = page.locator('.menu').filter({ hasText: 'Textbaustein einfügen' });
        await expect(slashMenu).toBeVisible();
        await page.keyboard.press('Enter');
        await expect(editor).toContainText(`Magic${uniqueSuffix}Content`, { timeout: 10000 });
    });
});
