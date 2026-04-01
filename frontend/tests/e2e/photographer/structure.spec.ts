import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';

// 🧹 Clean-Up Hook: Räumt alle Galerien und Gruppen auf, bevor der E2E-User gelöscht wird.
test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupE2EData(request);
    await E2EUserHelper.cleanupTrackedUsers(request);
});

test.describe.serial('Management Structure View (Tree)', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    test.beforeEach(async ({ page }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Photographer can create nested groups and toggle tree nodes', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const uniqueId = Math.random().toString(36).substring(2, 10);
        const groupName = `Tree Group ${uniqueId}`;
        const subGroupName = `Sub Group ${uniqueId}`;

        await sidebar.navigateTo('Galerien');
        await expect(page.locator('h1:has-text("Galerien")')).toBeVisible();

        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', groupName);
        await modal.submitModal('Speichern');

        const rootGroupNode = page.locator('summary').filter({ hasText: groupName }).first();
        await expect(async () => {
            if (await rootGroupNode.isHidden()) await page.reload();
            await expect(rootGroupNode).toBeVisible();
        }).toPass();

        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', subGroupName);
        await modal.selectByLabel('Übergeordnete Meta-Galerie', groupName);
        await modal.submitModal('Speichern');

        await page.getByRole('button', { name: 'Alle ausklappen' }).click();
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible();

        await page.getByRole('button', { name: 'Alle einklappen' }).click();
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeHidden({ timeout: 10000 });

        await rootGroupNode.locator('.font-bold').first().click({ force: true });
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible({ timeout: 10000 });
        await expect(page.locator(`summary:has-text("${subGroupName}")`)).toBeVisible();
    });

    test('Photographer can use inline add buttons, edit nested galleries, and type trailing dashes in slug', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const inlineGroupName = `Inline Test Group ${Math.random().toString(36).substring(2, 10)}`;
        const dashGalName = `Dash Test ${Math.random().toString(36).substring(2, 10)}`;
        const dashGalSlug = `dash-test-${Math.random().toString(36).substring(2, 10)}-`;
        
        await sidebar.navigateTo('Galerien');
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', inlineGroupName);
        await modal.submitModal('Speichern');

        const rootGroupNode = page.locator('summary').filter({ hasText: inlineGroupName }).first();
        await expect(async () => {
            if (await rootGroupNode.isHidden()) await page.reload();
            await expect(rootGroupNode).toBeVisible();
        }).toPass();

        await rootGroupNode.locator('button[data-tip="Galerie hier erstellen"]').click();
        await expect(modal.activeModal.locator('h3:has-text("Neue Galerie")')).toBeVisible();
        
        const dropdown = modal.activeModal.locator('.form-control').filter({ hasText: 'Ordner' }).locator('select');
        await expect(dropdown).not.toHaveValue('');
        
        const nameInput = modal.activeModal.locator('.form-control').filter({ hasText: 'Name der Galerie' }).locator('input');
        const slugInput = modal.activeModal.locator('.form-control').filter({ hasText: 'URL Slug' }).locator('input');
        
        await nameInput.fill(dashGalName);
        await slugInput.fill(dashGalSlug);
        await expect(slugInput).toHaveValue(dashGalSlug);

        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.submitModal('Speichern');

        const galNode = page.locator('a').filter({ hasText: dashGalName });
        await expect(async () => {
            if (await galNode.isHidden()) { await rootGroupNode.click(); }
            await expect(galNode).toBeVisible();
        }).toPass();

        await galNode.locator('..').locator('button[data-tip="Bearbeiten"]').click();
        await expect(modal.activeModal.locator('h3:has-text("Galerie bearbeiten")')).toBeVisible();
        await modal.clickButton('Abbrechen');
    });

    test('Flow C: Deleting a group cascades nested galleries to root', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const flowCGroupName = `Flow C Group ${Math.random().toString(36).substring(2, 10)}`;
        const flowCGalName = `Flow C Gallery ${Math.random().toString(36).substring(2, 10)}`;

        await sidebar.navigateTo('Galerien');
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await modal.fillInputByLabel('Name', flowCGroupName);
        await modal.submitModal('Speichern');

        const groupNode = page.locator('summary').filter({ hasText: flowCGroupName }).first();
        await expect(async () => {
            if (await groupNode.isHidden()) await page.reload();
            await expect(groupNode).toBeVisible();
        }).toPass();

        await groupNode.locator('button[data-tip="Galerie hier erstellen"]').click();
        await modal.fillInputByLabel('Name der Galerie', flowCGalName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await modal.submitModal('Speichern');

        await expect(async () => {
            if (await groupNode.locator('..').getAttribute('open') === null) {
                await groupNode.locator('.font-bold').first().click({ force: true });
            }
            await expect(page.locator('a').filter({ hasText: flowCGalName }).first()).toBeVisible();
        }).toPass();

        await groupNode.locator('button[data-tip="Ordner bearbeiten"]').click();
        await modal.activeModal.getByRole('button', { name: 'Löschen' }).click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();
        
        // Assert: Group is gone, Gallery is at root
        await expect(async () => {
            if (await groupNode.isVisible()) await page.reload();
            await expect(groupNode).toBeHidden();
        }).toPass();

        await expect(async () => {
            await page.reload();
            const rootGalNode = page.locator('a').filter({ hasText: flowCGalName }).first();
            await expect(rootGalNode).toBeVisible();
            await expect(rootGalNode.locator('xpath=ancestor::details')).toHaveCount(0);
        }).toPass();
    });
});
