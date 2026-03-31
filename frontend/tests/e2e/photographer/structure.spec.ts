import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Management Structure View (Tree)', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const groupName = `Tree Group ${uniqueId}`;
    const subGroupName = `Sub Group ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can create nested groups and toggle tree nodes', async ({ page }) => {
        // 1. Zur Verwaltung navigieren
        await sidebar.navigateTo('Galerien');
        await expect(page.locator('h1:has-text("Galerien")')).toBeVisible();

        // 2. Übergeordneten Ordner erstellen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', groupName);
        await modal.submitModal('Speichern');

        // Warten bis der Ordner im DOM gerendert ist (Geduldiges Assert)
        const rootGroupNode = page.locator('summary').filter({ hasText: groupName }).first();
        await expect(rootGroupNode).toBeVisible({ timeout: 15000 });

        // 3. Unterordner im erstellten Ordner anlegen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', subGroupName);
        await modal.selectByLabel('Übergeordnete Meta-Galerie', groupName);
        await modal.submitModal('Speichern');

        // 4. Verifizieren: "Alle ausklappen" Button testen
        await page.getByRole('button', { name: 'Alle ausklappen' }).click();
        
        // Der Unterordner muss nun zwingend sichtbar sein
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible({ timeout: 15000 });

        // 5. Verifizieren: "Alle einklappen" Button testen
        await page.getByRole('button', { name: 'Alle einklappen' }).click();
        
        // Das <details> Element darf nun nicht mehr das 'open' Attribut besitzen
        await expect(rootGroupNode.locator('..')).not.toHaveAttribute('open', '');

        // 6. Manuellen Toggle (Klick auf den Ordner) testen
        await rootGroupNode.click();
        await expect(rootGroupNode.locator('..')).toHaveAttribute('open', '');
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible();
    });


    test('Photographer can use inline add buttons, edit nested galleries, and type trailing dashes in slug', async ({ page }) => {
        const inlineGroupName = `Inline Test Group ${Date.now()}`;
        const dashGalName = `Dash Test ${Date.now()}`;
        const dashGalSlug = `dash-test-${Date.now()}-`;
        // 1. Create a parent group to anchor our inline buttons
        await sidebar.navigateTo('Galerien');
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', inlineGroupName);
        await modal.submitModal('Speichern');

        const rootGroupNode = page.locator('summary').filter({ hasText: inlineGroupName }).first();
        await expect(rootGroupNode).toBeVisible({ timeout: 15000 });

        // 2. Click inline "+ Galerie" button directly on the group node
        await rootGroupNode.locator('button[data-tip="Galerie hier erstellen"]').click();
        await expect(modal.activeModal.locator('h3:has-text("Neue Galerie")')).toBeVisible({ timeout: 5000 });
        
        // 3. Verify Prefill: The select should have a valid UUID as value (not the default empty string)
        const dropdown = modal.activeModal.locator('.form-control').filter({ hasText: 'Ordner' }).locator('select');
        await expect(dropdown).not.toHaveValue('');
        
        // 4. Test trailing dash in slug (Regex UX Bug)
        const nameInput = modal.activeModal.locator('.form-control').filter({ hasText: 'Name der Galerie' }).locator('input');
        const slugInput = modal.activeModal.locator('.form-control').filter({ hasText: 'URL Slug' }).locator('input');
        
        await nameInput.fill(dashGalName);
        // Manually type a dash at the end
        await slugInput.fill(dashGalSlug);
        // The input should retain the dash while typing
        await expect(slugInput).toHaveValue(dashGalSlug);

        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.submitModal('Speichern');

        // 5. Expand group and click "Edit" on the nested gallery
        const galNode = page.locator('a').filter({ hasText: dashGalName });
        // Ein echter User sucht den Link. Ist er versteckt, klickt er den Ordner an und wartet geduldig.
        await expect(async () => {
            if (await galNode.isHidden()) {
                await rootGroupNode.locator('.font-bold').first().click();
            }
            await expect(galNode).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 15000 });

        // Click the edit button located right next to the gallery link
        await galNode.locator('..').locator('button[data-tip="Bearbeiten"]').click();
        
        // 6. Verify the correct modal opens (Gallery, not Group!)
        await expect(modal.activeModal.locator('h3:has-text("Galerie bearbeiten")')).toBeVisible({ timeout: 5000 });
        await modal.clickButton('Abbrechen');
    });
});
