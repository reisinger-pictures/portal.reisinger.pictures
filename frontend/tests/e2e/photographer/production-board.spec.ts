import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { KanbanHelper } from '../helpers/KanbanHelper';

test.describe('Bildbearbeitungs-Board (Photographer)', () => {
    let helper: E2ESessionHelper;
    let photographer = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photographer = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setup(page: Page, user: { email: string; password: string }) {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const kanban = new KanbanHelper(page);
        await auth.login(user.email, user.password);
        await sidebar.navigateTo('Bildbearbeitung');
        await expect(page.locator('main h1')).toContainText('Bildbearbeitung', { timeout: 15000 });
        return { kanban };
    }

    test('Photographer wird das Bildbearbeitungs-Board mit allen Spalten angezeigt', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        await kanban.expectColumn('Shooting');
        await kanban.expectColumn('Culling');
        await kanban.expectColumn('Bearbeitung');
        await kanban.expectColumn('Export');
        await kanban.expectColumn('Veröffentlicht');
        await kanban.expectColumn('Abgebrochen');
    });

    test('Pflichtfeld-Validierung: Titel ist erforderlich', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.submit();
        await kanban.expectFieldError('Titel', 'Titel ist erforderlich');
    });

    test('Photographer legt einen neuen Auftrag an', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        const title = `E2E Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.fillField('Bilder gesamt', '24');
        await kanban.submit();

        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('.toast')).toContainText('Auftrag angelegt');
        await kanban.modalIsClosed();
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Admin verschiebt einen Auftrag per Drag & Drop', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Drag Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(title, 'Bearbeitung');

        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Admin verschiebt einen Auftrag in die Abgebrochen-Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Abbruch ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(title, 'Abgebrochen');

        await kanban.expectColumn('Abgebrochen');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Admin löscht einen abgebrochenen Auftrag', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Abbruch Del ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(title, 'Abgebrochen');

        const card = page.locator('main').getByText(title, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button').first().click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();

        await kanban.waitForDelete('/api/management/photo-jobs');
        await expect(page.locator('.toast')).toContainText('Auftrag gelöscht');
        await expect(page.locator('main').getByText(title, { exact: false })).toHaveCount(0, { timeout: 10000 });
    });

    test('Mobile: Touch-Drag verschiebt einen Auftrag zwischen Spalten', { tag: ['@mobile', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Touch Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(title, 'Culling', { touch: true });

        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });
});
