import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Manual Documents & CRM Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };
    let uniqueSuffix = '';
    let snippetShortcut = '';

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
        
        uniqueSuffix = Math.random().toString(36).substring(2, 10);
        snippetShortcut = `snip${uniqueSuffix}`;

        // CRM Kunde vorbereiten
        const custRes = await request.post('/api/management/customers', {
            data: { name: `E2E VIP ${uniqueSuffix}`, zip: '1010', city: 'Wien' },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const custData = await custRes.json();
        if (custData?.customer?.id) helper.trackCustomer(custData.customer.id);

        // Textbaustein vorbereiten
        const snipRes = await request.post('/api/management/text-snippets', {
            data: { title: `E2E Snippet ${uniqueSuffix}`, shortcut: snippetShortcut, content_html: `<p>Magic${uniqueSuffix}Content</p>` },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const snipData = await snipRes.json();
        if (snipData?.snippet?.id) helper.trackSnippet(snipData.snippet.id);

        // Katalog-Einträge (Produkt & Rabatt) vorbereiten
        const prodRes = await request.post('/api/management/products', {
            data: { type: 'item', name: `E2E Product ${uniqueSuffix}`, description: 'E2E Leistung', price: 150 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const prodData = await prodRes.json();
        if (prodData?.product?.id) helper.trackProduct(prodData.product.id);

        const discRes = await request.post('/api/management/products', {
            data: { type: 'discount_fixed', name: `E2E Discount ${uniqueSuffix}`, description: 'E2E Rabatt', price: 20 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const discData = await discRes.json();
        if (discData?.product?.id) helper.trackProduct(discData.product.id);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin uses CRM autocomplete, Tiptap shortcuts and generates PDF Offer', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Manuelles Angebot');
        await expect(page).toHaveURL(/.*\/admin-manual-offer/);

        // 1. Test CRM Autocomplete (mit Delay für Meilisearch)
        await page.waitForTimeout(2000); 
        const nameInput = page.locator('.form-control').filter({ hasText: 'Name / Ansprechpartner' }).locator('input');
        await nameInput.click();
        await nameInput.clear();
        await nameInput.pressSequentially(`E2E VIP ${uniqueSuffix}`, { delay: 100 });
        
        const dropdown = page.locator(`li:has-text("E2E VIP ${uniqueSuffix}")`).first();
        await expect(dropdown).toBeVisible({ timeout: 15000 });
        await dropdown.click();

        // 2. Test Tiptap Shortcut Injection
        const editor = page.locator('.ProseMirror').first();
        await editor.click();
        await editor.pressSequentially(`/${snippetShortcut}`, { delay: 50 }); 
        await page.locator('.menu').filter({ hasText: 'Textbaustein einfügen' }).waitFor({ state: 'visible' });
        await page.keyboard.press('Enter'); 
        await expect(editor).toContainText(`Magic${uniqueSuffix}Content`, { timeout: 10000 });

        // 3. Leistungen befüllen (via Autocomplete)
        const itemInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
        await itemInput.fill(`E2E Product ${uniqueSuffix}`);
        await page.locator(`li:has-text("E2E Product ${uniqueSuffix}")`).first().click();

        await expect(page.locator('.form-control').filter({ hasText: 'Preis / Stück' }).locator('input').first()).toHaveValue('150');
        await page.locator('.form-control').filter({ hasText: 'Menge' }).locator('input').first().fill('2');

        // 4. Rabatt hinzufügen
        await page.getByRole('button', { name: '+ Rabatt hinzufügen' }).click();
        const discountInput = page.locator('.form-control').filter({ hasText: 'Titel / Beschreibung' }).locator('input').last();
        await discountInput.fill(`E2E Discount ${uniqueSuffix}`);
        await page.locator(`li:has-text("E2E Discount ${uniqueSuffix}")`).first().click();

        // Validierung der Gesamtsumme (150 * 2 - 20 = 280)
        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('280.00 €');

        // 5. PDF Generierung
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'PDF Generieren' }).click()
        ]);
        expect(download.suggestedFilename()).toMatch(/^Angebot-.*\.pdf$/);
        const downloadPath = await download.path();

        // 6. Smart Documents: Import PDF as Invoice
        await sidebar.navigateTo('Manuelle Rechnung');
        await expect(page).toHaveURL(/.*\/admin-manual-invoice/);

        // Upload the previously downloaded PDF
        const fileChooserPromise = page.waitForEvent('filechooser');
        await page.locator('label').filter({ hasText: 'Angebot importieren (.pdf)' }).click();
        const fileChooser = await fileChooserPromise;
        const savedPdfPath = downloadPath + '.pdf';
        await download.saveAs(savedPdfPath);
        
        // Datei inhaltlich validieren (Prüfung, ob das Backend das Smart Doc Payload angehängt hat)
        const fileContent = fs.readFileSync(savedPdfPath, 'utf8');
        expect(fileContent, 'Die heruntergeladene PDF hat kein %SMART_DOC: Payload! (Backend/UI Fehler)').toContain('%SMART_DOC:');

        // We relaxed the MIME validation on the backend, so we can just pass the pristine file path
        await fileChooser.setFiles(savedPdfPath);

        // Wait for the success toast from the API response
        await expect(page.locator('.toast')).toContainText('Angebotsdaten erfolgreich übernommen!', { timeout: 15000 });

        // 7. Validate restored data
        await expect(page.locator('.form-control').filter({ hasText: 'Name / Ansprechpartner' }).locator('input')).toHaveValue(`E2E VIP ${uniqueSuffix}`);
        await expect(page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first()).toHaveValue(`E2E Product ${uniqueSuffix}`);
        await expect(page.locator('.form-control').filter({ hasText: 'Preis / Stück' }).locator('input').first()).toHaveValue('150');
        await expect(page.locator('.form-control').filter({ hasText: 'Menge' }).locator('input').first()).toHaveValue('2');
        
        await expect(page.locator('.form-control').filter({ hasText: 'Titel / Beschreibung' }).locator('input').last()).toHaveValue(`E2E Discount ${uniqueSuffix}`);
        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('280.00 €');
        
        // Nutze den bereits oben deklarierten 'editor' Locator
        await expect(editor).toContainText(`Magic${uniqueSuffix}Content`);
    });

    });