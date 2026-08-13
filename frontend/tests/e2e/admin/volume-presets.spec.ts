import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Volume-Licensing Presets Admin Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super Admin can create, edit, set default and delete a preset', { tag: ['@feature:admin:volume-pricing', '@smoke'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        // Lizenz-Katalog ist Default-Tab → zu Volume-Pricing wechseln
        await page.getByRole('radio', { name: 'Volume-Pricing' }).check();

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const presetName = `E2E Preset ${uniqueSuffix}`;

        // 1. Create preset with two tiers
        await page.getByRole('button', { name: 'Neues Preset' }).click();
        const modal = page.locator('.modal-open').last();
        await expect(modal).toBeVisible();
        await modal.locator('input[type="text"]').fill(presetName);

        // Basispreis (ab 0 Bildern) → 50.00 €
        await modal.locator('input[type="number"]').nth(0).fill('50.00');

        // Staffel hinzufügen: ab 5 Bildern → 40.00 €
        await modal.getByRole('button', { name: 'Staffel hinzufügen' }).click();
        await modal.locator('input[type="number"]').nth(1).fill('5');
        await modal.locator('input[type="number"]').nth(2).fill('40.00');

        await modal.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Preset gespeichert');
        await expect(page.locator('tr').filter({ hasText: presetName })).toBeVisible();

        // 2. Create a second preset and promote it to default
        await page.getByRole('button', { name: 'Neues Preset' }).click();
        const modal2 = page.locator('.modal-open').last();
        await modal2.locator('input[type="text"]').fill(`${presetName} 2`);
        await modal2.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Preset gespeichert');

        const secondRow = page.locator('tr').filter({ hasText: `${presetName} 2` });
        await secondRow.getByRole('button', { name: 'Als Standard' }).click();
        await expect(page.locator('.toast')).toContainText('Als Standard gesetzt');
        await expect(secondRow.locator('.badge-primary')).toContainText('Standard');

        // 3. Edit the first preset (now non-default)
        const firstRow2 = page.locator('tr').filter({ has: page.getByText(presetName, { exact: true }) }).first();
        await firstRow2.getByRole('button', { name: 'Bearbeiten' }).click();
        const modal3 = page.locator('.modal-open').last();
        await expect(modal3.locator('input[type="text"]')).toHaveValue(presetName);
        await modal3.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Preset gespeichert');

        // 4. Delete the first preset (non-default)
        await firstRow2.getByRole('button').filter({ has: page.locator('span.mdi--delete') }).click();
        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Bestätigen' }).click();
        await expect(page.locator('.toast')).toContainText('Preset gelöscht');
        await expect(firstRow2).toBeHidden();
    });

    test('Gallery modal offers preset selection in volume mode and persists it', { tag: ['@feature:admin:volume-pricing'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        testUser = await helper.createIsolatedUser('photographer');

        // Create a preset via API for deterministic assignment
        const preset = await helper.createVolumePreset({
            name: `E2E Gallery Preset ${Math.random().toString(36).substring(2, 8)}`,
            tiers: [
                { min_quantity: 0, price_cents: 6000 },
                { min_quantity: 6, price_cents: 4500 },
            ],
        });
        helper.trackPreset(String(preset.id));

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien');

        const uniqueName = `E2E Volume Gallery ${Math.random().toString(36).substring(2, 8)}`;

        // Create gallery with volume licensing + preset
        await page.getByRole('button', { name: 'Neue Galerie' }).click();
        const galleryModal = page.locator('.modal-open').last();
        await expect(galleryModal).toBeVisible();
        await galleryModal.locator('input[name="name"]').fill(uniqueName);
        await galleryModal.locator('select[name="licensing_mode"]').selectOption('volume_licensing');

        const presetSelect = galleryModal.locator('select[name="volume_preset_id"]');
        await expect(presetSelect).toBeVisible();
        await presetSelect.selectOption({ label: preset.name });

        await galleryModal.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Galerie erfolgreich erstellt');

        // Re-open the gallery edit modal and verify the preset is persisted
        await page.locator('a').filter({ hasText: uniqueName }).locator('..').locator('button[data-tip="Bearbeiten"]').click();
        const editModal = page.locator('.modal-open').last();
        await expect(editModal).toBeVisible();
        await expect(editModal.locator('select[name="licensing_mode"]')).toHaveValue('volume_licensing');
        await expect(editModal.locator('select[name="volume_preset_id"]')).toHaveValue(String(preset.id));
    });
});
