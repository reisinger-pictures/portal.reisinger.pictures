import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { KanbanHelper } from '../helpers/KanbanHelper';

test.describe('Projekte-Board (Admin)', () => {
    let helper: E2ESessionHelper;
    let admin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        admin = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function setup(page: Page, user: { email: string; password: string }, menuText = 'Projekte', headerText = 'Projekte') {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const kanban = new KanbanHelper(page);
        await auth.login(user.email, user.password);
        await sidebar.navigateTo(menuText);
        await expect(page.locator('main h1')).toContainText(headerText, { timeout: 15000 });
        return { kanban };
    }

    test('Admin wird das Projekte-Board mit allen Spalten angezeigt', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        await kanban.expectColumn('Anfrage');
        await kanban.expectColumn('Angebot');
        await kanban.expectColumn('Beauftragt');
        await kanban.expectColumn('Rechnung');
        await kanban.expectColumn('Bezahlt');
        await kanban.expectColumn('Storniert');
    });

    test('Pflichtfeld-Validierung: Kundenname ist erforderlich', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.submit();
        await kanban.expectFieldError('Kundenname', 'Kundenname ist erforderlich');
    });

    test('Admin legt ein neues Projekt an (client_name required)', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        const clientName = `E2E Projekt ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.fillField('Preis', '150.00');
        await kanban.submit();

        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('.toast')).toContainText('Projekt angelegt');
        await kanban.modalIsClosed();
        await kanban.expectColumn('Anfrage');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();
    });

    test('Admin löscht ein Projekt mit Bestätigung', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        const clientName = `Lösch Projekt ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button').first().click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();

        await kanban.waitForDelete('/api/management/projects');
        await expect(page.locator('.toast')).toContainText('Projekt gelöscht');
        await expect(page.locator('main').getByText(clientName, { exact: false })).toHaveCount(0, { timeout: 10000 });
    });

    test('Admin verschiebt ein Projekt per Drag & Drop in eine andere Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        test.skip(test.info().project.name === 'Mobile Chrome', 'Drag & Drop ist nur am Desktop verfügbar');
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const clientName = `Drag Projekt ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(clientName, 'Beauftragt');

        await kanban.expectColumn('Beauftragt');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();
    });

    test('Admin verschiebt ein Projekt in die Storniert-Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        test.skip(test.info().project.name === 'Mobile Chrome', 'Drag & Drop ist nur am Desktop verfügbar');
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const clientName = `Storno ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(clientName, 'Storniert');

        await kanban.expectColumn('Storniert');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();
    });

    test('Admin reordert eine Karte innerhalb derselben Spalte in die Mitte (nicht ans Ende)', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        test.skip(test.info().project.name === 'Mobile Chrome', 'Drag & Drop ist nur am Desktop verfügbar');
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const names = ['Reorder A', 'Reorder B', 'Reorder C'].map(n => `${n} ${Math.random().toString(36).substring(2, 6)}`);

        for (const name of names) {
            await kanban.openCreateModal('Anfrage', 'Neues Projekt');
            await kanban.fillField('Kundenname', name);
            await kanban.submit();
            await kanban.waitForCreate('/api/management/projects');
            await kanban.modalIsClosed();
        }

        await kanban.expectCardOrder('Anfrage', names);

        await kanban.dragCard(names[2], 'Anfrage', { position: 'top' });

        await kanban.expectCardOrder('Anfrage', [names[2], names[0], names[1]]);
    });

    test('Admin verschiebt ein Projekt über das Karten-Status-Select (Mobile-Fallback, funktioniert auf allen Geräten)', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        const clientName = `Select Projekt ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(clientName, 'Beauftragt');

        await kanban.expectColumn('Beauftragt');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();
    });

    test('Admin löscht ein storniertes Projekt mit Bestätigung', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        test.skip(test.info().project.name === 'Mobile Chrome', 'Drag & Drop ist nur am Desktop verfügbar');
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const clientName = `Storno Del ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.dragCard(clientName, 'Storniert');

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button').first().click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();

        await kanban.waitForDelete('/api/management/projects');
        await expect(page.locator('.toast')).toContainText('Projekt gelöscht');
        await expect(page.locator('main').getByText(clientName, { exact: false })).toHaveCount(0, { timeout: 10000 });
    });

    test('Admin legt ein Projekt mit Notiz an und sieht die Vorschau mit Tooltip auf der Karte', { tag: ['@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        const clientName = `Notiz Projekt ${Math.random().toString(36).substring(2, 8)}`;
        const note = 'Interne Notiz für die Kartenvorschau';

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.fillField('Notiz', note);
        await kanban.submit();

        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('.toast')).toContainText('Projekt angelegt');
        await kanban.modalIsClosed();

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        const notePreview = card.getByText(note, { exact: true });
        await expect(notePreview).toBeVisible();
        await expect(notePreview).toHaveAttribute('data-tip', note);
        await expect(notePreview).toHaveClass(/tooltip/);
    });

    test('Admin sieht auf der Karte das Zugewiesen-Badge mit dem Owner-Namen', { tag: ['@feature:kanban'] }, async ({ page }) => {
        const { kanban } = await setup(page, admin);
        const clientName = `Badge Projekt ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();

        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('.toast')).toContainText('Projekt angelegt');
        await kanban.modalIsClosed();

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        const assigneeBadge = card.locator('.badge.badge-outline');
        await expect(assigneeBadge).toHaveCount(1);
        await expect(assigneeBadge).toHaveText(/E2E admin/);
    });
});
