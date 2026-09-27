import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { withGlobalSettingsLock, SHOOTING_CALCULATOR_SETTINGS_LOCK } from '../helpers/GlobalSettingsLock';
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
            data: { type: 'item', name: `E2E Product ${uniqueSuffix}`, description: 'E2E Leistung', price: 15000 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const prodData = await prodRes.json();
        if (prodData?.product?.id) helper.trackProduct(prodData.product.id);

        const discRes = await request.post('/api/management/products', {
            data: { type: 'discount_fixed', name: `E2E Discount ${uniqueSuffix}`, description: 'E2E Rabatt', price: 2000 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const discData = await discRes.json();
        if (discData?.product?.id) helper.trackProduct(discData.product.id);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin uses CRM autocomplete, Tiptap shortcuts and generates PDF Offer', { tag: ['@feature:admin:documents'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        await sidebar.navigateTo('Manuelles Angebot');
        await expect(page).toHaveURL(/.*\/admin-manual-offer/);

        // 1. Test CRM Autocomplete (mit Delay für Meilisearch)
        const nameInput = page.locator('.form-control').filter({ hasText: 'Name / Ansprechpartner' }).locator('input');
        await expect(nameInput).toBeVisible({ timeout: 10000 });
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
        const productOption = page.locator(`li:has-text("E2E Product ${uniqueSuffix}")`).first();
        await expect(productOption).toBeVisible({ timeout: 10000 });
        await productOption.click();

        await expect(page.locator('.form-control').filter({ hasText: 'Preis / Stück' }).locator('input').first()).toHaveValue('150');
        await page.locator('.form-control').filter({ hasText: 'Menge' }).locator('input').first().fill('2');

        // 4. Rabatt hinzufügen
        await page.getByRole('button', { name: '+ Rabatt hinzufügen' }).click();
        const discountInput = page.locator('.form-control').filter({ hasText: 'Titel / Beschreibung' }).locator('input').last();
        await discountInput.fill(`E2E Discount ${uniqueSuffix}`);
        const discountOption = page.locator(`li:has-text("E2E Discount ${uniqueSuffix}")`).first();
        await expect(discountOption).toBeVisible({ timeout: 10000 });
        await discountOption.click();

        // Validierung der Gesamtsumme (150 * 2 - 20 = 280)
        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('280,00 €');

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
        expect(fileContent, 'Die heruntergeladene PDF hat kein Smart-Doc-Payload! (Backend/UI Fehler)').toMatch(/%SMART_DOC:|%OFFER_JWT:/);

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
        await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('280,00 €');
        
        // Nutze den bereits oben deklarierten 'editor' Locator
        await expect(editor).toContainText(`Magic${uniqueSuffix}Content`);
    });

    test('Super Admin can use the Shooting Package Calculator with 50% OG discount', { tag: ['@feature:admin:documents'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        // The calculator reads brand-global pricing factors, and the portal has a
        // single brand — so every worker of the run shares one `settings` row.
        // `package-calculator-config.spec.ts` saves its own values there, and
        // under `fullyParallel` with several workers that save can land between
        // this test reading the factors and asserting the total. It did: with
        // its hourly rate of 95 the total is 119,00 € instead of 105,00 €.
        //
        // The lock closes that window instead of narrowing it — no other worker
        // can touch these settings between establishing them and asserting — and
        // the factors are established here, so the expected number no longer
        // depends on whatever state the run happens to start from. Waiting for
        // the lock is charged to the test timeout, so only the shared-state
        // access is inside it: the write has to precede the navigation, because
        // `ShootingCalculatorModal` fetches the terms when the page mounts.
        await withGlobalSettingsLock(SHOOTING_CALCULATOR_SETTINGS_LOCK, async () => {
            const effective = await helper.setShootingCalculatorSettings({
                calc_base_price: 50,
                calc_hourly_rate: 80,
                calc_images_per_hour: 6,
                calc_outdoor_images_per_hour: 8,
                calc_flatrate_multiplier: 1.2,
            });
            // Guard the premise of the arithmetic asserted below: these are the
            // factors the calculator modal will consume, not merely what was sent.
            expect(effective).toEqual({
                calc_base_price: '50',
                calc_hourly_rate: '80',
                calc_images_per_hour: '6',
                calc_outdoor_images_per_hour: '8',
                calc_flatrate_multiplier: '1.2',
            });

            await sidebar.navigateTo('Manuelles Angebot');

            // Kalkulator Modal öffnen
            await page.locator('button:has-text("Paket-Kalkulator")').click();
            const calcModal = page.locator('.modal-open');
            await expect(calcModal).toBeVisible();

            // Werte über eindeutige Landmarken/Labels eintragen, um Verschiebungen im Mobil-Layout zu verhindern
            await calcModal.locator('.form-control', { hasText: 'Dauer (Min.)' }).locator('input').fill('60');
            await calcModal.locator('.form-control', { hasText: 'Inkl. Bilder' }).locator('input').fill('6');

            // 50% OG Rabatt auswählen
            await calcModal.locator('.form-control', { hasText: 'Rabatt-Stufe' }).locator('select').selectOption('50');

            // Berechnen & Hinzufügen klicken
            await calcModal.getByRole('button', { name: 'Berechnen & Hinzufügen' }).click();
            await expect(calcModal).toBeHidden();

            // Validierung in der Haupt-Tabelle (jetzt div-basiert nach Refactoring)
            // React setzt den Value als DOM-Property, nicht als HTML-Attribut → toHaveValue statt CSS-Selector
            const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
            await expect(itemTitleInput).toHaveValue('Individuelles Shooting-Paket', { timeout: 10000 });

            // Gesamtsumme prüfen. Mit den oben gesetzten Faktoren:
            // Basis 50 + Zeit (1 h × 80) + Bilder ((80/6) × 6) = 210 → psychologisch 209;
            // davon 50 % Rabatt: 104,50 → psychologisch gerundet 105.
            // Der Rabattposten im Beleg ist 209 − 105 = 104, die Summe also 105,00 €.
            await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('105,00 €');
        });
    });
});
