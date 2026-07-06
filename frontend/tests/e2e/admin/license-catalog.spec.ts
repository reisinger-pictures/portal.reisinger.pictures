import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('License Catalog Admin Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin can add and edit license modifiers', { tag: ['@feature:admin:licenses'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        
        // Warte bis die Einstellungsseite geladen ist
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const modName = `E2E Mod ${uniqueSuffix}`;

        // 1. Modifikator hinzufügen
        const modContainer = page.locator('h3', { hasText: 'Zuschläge' }).locator('..');
        await modContainer.locator('input[placeholder*="Titelseite"]').fill(modName);
        await modContainer.locator('input[type="number"]').fill('35');
        // Checkbox "In Flatrates inkludiert" aktivieren
        await modContainer.locator('input[type="checkbox"]').check();
        
        await modContainer.getByRole('button', { name: 'Hinzufügen' }).click();
        await expect(page.locator('.toast')).toContainText('Zuschlag hinzugefügt');

        // 2. Inline Editieren (Dies testet genau die Route, die bei dir gecrasht ist)
        const row = modContainer.locator('tr').filter({ hasText: modName });
        await row.locator('button').filter({ has: page.locator('span.mdi--pencil') }).first().click();
        
        // Checkbox im Edit-Modus DEAKTIVIEREN
        // Wenn die Zeile im Edit-Modus ist, befindet sich der Text im Input-Value, daher funktioniert "hasText" auf "row" nicht mehr.
        // Da immer nur eine Zeile bearbeitet wird, greifen wir direkt auf die Zeile mit den aktiven Inputs in der Tabelle zu.
        const editRow = modContainer.locator('table tr').filter({ has: page.locator('input[type="checkbox"]') });
        await editRow.locator('input[type="checkbox"]').uncheck();
        await editRow.locator('input[type="number"]').fill('45');
        
        await editRow.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Zuschlag aktualisiert');
        
        // Validieren, dass die Änderung übernommen wurde (45% und nicht mehr inkludiert)
        const updatedRow = modContainer.locator('tr').filter({ hasText: modName });
        await expect(updatedRow).toContainText('+45 %');
        await expect(updatedRow).toContainText('Kostenpflichtig');
    
        // 3. Löschen zum Aufräumen
        await updatedRow.locator('button').filter({ has: page.locator('span.mdi--trash-can') }).last().click();
        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Bestätigen' }).click();
        await expect(page.locator('.toast')).toContainText('Zuschlag gelöscht');
        await expect(updatedRow).toBeHidden();
    });
});
