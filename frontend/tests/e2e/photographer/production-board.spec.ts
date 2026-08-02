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

    async function setup(page: Page) {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const kanban = new KanbanHelper(page);
        await auth.login(photographer.email, photographer.password);
        await sidebar.navigateTo('Bildbearbeitung');
        await expect(page.locator('main h1')).toContainText('Bildbearbeitung', { timeout: 15000 });
        return { kanban };
    }

    test('Photographer wird das Bildbearbeitungs-Board mit allen Spalten angezeigt', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page);
        await kanban.expectColumn('Shooting');
        await kanban.expectColumn('Culling');
        await kanban.expectColumn('Bearbeitung');
        await kanban.expectColumn('Export');
        await kanban.expectColumn('Veröffentlicht');
    });

    test('Pflichtfeld-Validierung: Titel ist erforderlich', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page);
        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.submit();
        await kanban.expectFieldError('Titel', 'Titel ist erforderlich');
    });

    test('Photographer legt einen neuen Auftrag an', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page);
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

    test('Photographer verschiebt einen Auftrag per Drag & Drop', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page);
        const title = `Drag Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Shooting', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(title, 'Bearbeitung');

        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Mobile: Touch-Drag verschiebt einen Auftrag zwischen Spalten', { tag: ['@mobile', '@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page);
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