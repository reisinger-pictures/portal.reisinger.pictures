import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { KanbanHelper } from '../helpers/KanbanHelper';

test.describe('Bildbearbeitungs-Board (Photographer)', () => {
    // Status-Select-Transitions verändern das Board live; die Tests teilen sich
    // dieselbe Spalte. `fullyParallel: true` (playwright.config) würde die Tests
    // dieser Datei parallel ausführen -> konkurrierende Board-Mutationen.
    // Datei seriell ausführen (analog projects-board.spec.ts).
    test.describe.configure({ mode: 'serial' });

    let helper: E2ESessionHelper;
    let photographer = { email: '', password: '', id: '' };

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
        await expect(page).toHaveURL(/\/boards\?tab=production/);
        await expect(page.locator('main .kanban-grid')).toBeVisible({ timeout: 15000 });
        return { kanban };
    }

    test('Photographer wird das Bildbearbeitungs-Board mit allen Spalten angezeigt', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        await kanban.expectColumn('Importiert');
        await kanban.expectColumn('Culling');
        await kanban.expectColumn('Bearbeitung');
        await kanban.expectColumn('Exportiert');
        await kanban.expectColumn('Abgebrochen');
    });

    test('R3: Photographer, der /boards ohne Tab-Parameter direkt aufruft, landet auf dem Bildbearbeitungs-Board (kein Dead-End)', { tag: ['@feature:board', '@regression'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const kanban = new KanbanHelper(page);
        await auth.login(photographer.email, photographer.password);
        await page.goto('/boards');
        await expect(page).toHaveURL(/\/boards\?tab=production$/);
        await expect(page.locator('main .kanban-grid')).toBeVisible({ timeout: 15000 });
        await expect(page.getByText('Kein Zugriff auf dieses Board.')).toHaveCount(0);
        await kanban.expectColumn('Importiert');
        await kanban.expectColumn('Bearbeitung');
    });

    test('R4: Super-Admin sieht den Katalog-Badge auch ohne eigenen Lightroom-Katalog', { tag: ['@feature:board', '@regression'] }, async ({ page, request }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const catalogName = `Fremd-Katalog-${Math.random().toString(36).substring(2, 8)}`;
        const title = `R4-Katalog-Badge ${catalogName}`;

        // Job per API anlegen: Super-Admin besitzt keinen Katalog → Flag muss false bleiben
        // (Display-Convenience, keine Server-Secrecy), der Rohwert bleibt im Payload erhalten.
        const adminCookie = await helper.loginAs(superAdmin.email, superAdmin.password);
        const createRes = await request.post('/api/management/photo-jobs', {
            data: { title, lightroom_catalog: catalogName },
            headers: { 'Accept': 'application/json', 'Cookie': adminCookie },
        });
        expect(createRes.ok()).toBeTruthy();
        expect((await createRes.json()).photo_job.lightroom_catalog_is_mine).toBe(false);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const kanban = new KanbanHelper(page);
        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('Bildbearbeitung');
        await expect(page).toHaveURL(/\/boards\?tab=production/);
        await expect(page.locator('main .kanban-grid')).toBeVisible({ timeout: 15000 });

        const card = page.locator('main').getByText(title, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await expect(card).toBeVisible({ timeout: 10000 });
        await expect(card.locator('.badge').filter({ hasText: catalogName })).toBeVisible();
        await kanban.expectColumn('Importiert');
    });

    test('Pflichtfeld-Validierung: Titel ist erforderlich', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.submit();
        await kanban.expectFieldError('Titel', 'Titel ist erforderlich');
    });

    test('Photographer legt einen neuen Auftrag an', { tag: ['@smoke'] }, async ({ page }) => {
        const { kanban } = await setup(page, photographer);
        const title = `E2E Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.fillField('Bilder gesamt', '24');
        await kanban.submit();

        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('.toast')).toContainText('Auftrag angelegt');
        await kanban.modalIsClosed();
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Photographer kann Bildzahlen und Zuweisung im Auftrag explizit leeren', { tag: ['@regression', '@feature:kanban'] }, async ({ page, request }) => {
        const title = `Clear Auftrag ${Math.random().toString(36).substring(2, 8)}`;
        const cookie = await helper.loginAs(photographer.email, photographer.password);
        const createResponse = await request.post('/api/management/photo-jobs', {
            data: {
                title,
                assignee_id: photographer.id,
                total_count: 24,
                selected_count: 12,
            },
            headers: { Accept: 'application/json', Cookie: cookie },
        });
        expect(createResponse.ok()).toBeTruthy();
        const { photo_job: photoJob } = await createResponse.json() as { photo_job: { id: string } };

        const { kanban } = await setup(page, photographer);
        const card = page.locator('main').getByText(title, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button', { name: 'Details' }).click();

        const modal = page.locator('main .modal-open');
        const totalInput = modal.locator('.form-control').filter({ hasText: 'Bilder gesamt' }).locator('input');
        const selectedInput = modal.locator('.form-control').filter({ hasText: 'Bilder selektiert' }).locator('input');
        const assigneeSelect = modal.locator('.form-control').filter({ hasText: 'Zuständig' }).locator('select');
        await expect(totalInput).toHaveValue('24');
        await expect(selectedInput).toHaveValue('12');
        await expect(assigneeSelect.locator(`option[value="${photographer.id}"]`)).toHaveCount(1);
        await expect(assigneeSelect).toHaveValue(photographer.id);
        await totalInput.fill('');
        await selectedInput.fill('');
        await assigneeSelect.selectOption('');

        const responsePromise = page.waitForResponse(response =>
            response.url().includes(`/api/management/photo-jobs/${photoJob.id}`)
            && response.request().method() === 'PUT',
        );
        await modal.getByRole('button', { name: 'Speichern' }).click();
        const response = await responsePromise;
        expect(response.ok()).toBeTruthy();
        const payload = response.request().postDataJSON() as { total_count: unknown; selected_count: unknown; assignee_id: unknown };
        expect(payload.total_count).toBe(0);
        expect(payload.selected_count).toBe(0);
        expect(payload.assignee_id).toBeNull();
        await kanban.modalIsClosed();

        await page.reload();
        const reloadedCard = page.locator('main').getByText(title, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await reloadedCard.getByRole('button', { name: 'Details' }).click();
        const reloadedModal = page.locator('main .modal-open');
        await expect(reloadedModal.locator('.form-control').filter({ hasText: 'Bilder gesamt' }).locator('input')).toHaveValue('0');
        await expect(reloadedModal.locator('.form-control').filter({ hasText: 'Bilder selektiert' }).locator('input')).toHaveValue('0');
        await expect(reloadedModal.locator('.form-control').filter({ hasText: 'Zuständig' }).locator('select')).toHaveValue('');
    });

    test('Admin verschiebt einen Auftrag semantisch in die Bearbeitung-Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Status Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(title, 'Bearbeitung');

        await kanban.expectColumn('Bearbeitung');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Admin verschiebt einen Auftrag semantisch in die Abgebrochen-Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Abbruch ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(title, 'Abgebrochen');

        await kanban.expectColumn('Abgebrochen');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });

    test('Admin löscht einen abgebrochenen Auftrag', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Abbruch Del ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(title, 'Abgebrochen');

        const card = page.locator('main').getByText(title, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button').first().click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        // Promise VOR dem Klick registrieren (Race: DELETE-Response schneller als
        // Listener -> waitForResponse Timeout). Analog projects-board.spec.ts.
        const deletePromise = kanban.waitForDelete('/api/management/photo-jobs');
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();
        await deletePromise;
        await expect(page.locator('.toast')).toContainText('Auftrag gelöscht');
        await expect(page.locator('main').getByText(title, { exact: false })).toHaveCount(0, { timeout: 10000 });
    });

    test('Mobile: Karten-Status-Select verschiebt einen Auftrag zwischen Spalten (Mobile-Fallback)', { tag: ['@mobile', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin);
        const title = `Select Auftrag ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Importiert', 'Neuer Auftrag');
        await kanban.fillField('Titel', title);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/photo-jobs');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(title, 'Culling');

        await kanban.expectColumn('Culling');
        await expect(page.locator('main').getByText(title, { exact: false }).first()).toBeVisible();
    });
});
