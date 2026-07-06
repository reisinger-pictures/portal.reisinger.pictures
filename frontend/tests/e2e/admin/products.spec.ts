import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Product Batch Edit Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };
    let uniqueId = '';

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
        uniqueId = Math.random().toString(36).substring(2, 10);
        
        // Seed two products
        const p1 = await request.post('/api/management/products', {
            data: { type: 'item', name: `Batch Item 1 ${uniqueId}`, description: 'Desc 1', price: 100 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const d1 = await p1.json();
        helper.trackProduct(d1.product.id);

        const p2 = await request.post('/api/management/products', {
            data: { type: 'item', name: `Batch Item 2 ${uniqueId}`, description: 'Desc 2', price: 200 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const d2 = await p2.json();
        helper.trackProduct(d2.product.id);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin can search and batch edit products', { tag: ['@feature:admin:products'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Produkte & Leistungen');
        
        // Lokale Suche eingeben (gefiltert auf sichtbares Element wg. Responsive-Design)
        const searchInput = page.locator('input[placeholder="In Leistungen & Produkte suchen..."]:visible').first();
        await searchInput.fill('Batch Item');

        // Batch Edit Modus für "Leistungen & Produkte" starten
        const batchEditBtn = page.locator('button:has-text("Batch Edit"):visible').first();
        await batchEditBtn.click();
        
        // Beide Outputs modifizieren (nur die sichtbaren Input-Felder)
        const descInputs = page.locator('input[placeholder="Optional"]:visible');
        const priceInputs = page.locator('input[type="number"]:visible');
        
        await descInputs.nth(0).fill('Updated Desc 1');
        await priceInputs.nth(0).fill('150');
        
        await descInputs.nth(1).fill('Updated Desc 2');
        await priceInputs.nth(1).fill('250');
        
        // Unten in der Tabelle Speichern klicken
        await page.locator('button:has-text("Speichern"):visible').last().click();
        
        // Erfolgs-Toast validieren
        await expect(page.locator('.toast')).toContainText('Einträge erfolgreich aktualisiert');
        
        // Sicherstellen, dass Batch-Modus sich geschlossen hat und die neuen Werte da sind
        await expect(page.getByText('Updated Desc 1').and(page.locator(':visible')).first()).toBeVisible();
        await expect(page.getByText('150.00 €').and(page.locator(':visible')).first()).toBeVisible();
    });
});
