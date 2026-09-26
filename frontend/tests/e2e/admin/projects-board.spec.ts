import { test, expect, type Page } from '@playwright/test';
import { extractCookieHeader } from '../helpers/E2ECookieJar';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { KanbanHelper } from '../helpers/KanbanHelper';

test.describe('Projekte-Board (Admin)', () => {
    // Board-Mutationen verändern das Board live (Karten werden eingefügt/verschoben) und die
    // Tests teilen sich dieselbe (dirty) DB + dieselbe Anfrage-Spalte. Der dedizierte DnD-Test
    // darf deshalb nicht parallel zu anderen Board-Mutationen laufen — siehe
    // features/b2b/11-kanban-board.md (DnD). Datei seriell ausführen.
    test.describe.configure({ mode: 'serial' });

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
        // Promise VOR dem Klick registrieren — sonst verpasst waitForResponse die
        // (schnelle) DELETE-Response (Race: Board leer, aber Response schon weg).
        const deletePromise = kanban.waitForDelete('/api/management/projects');
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();
        await deletePromise;

        await expect(page.locator('.toast')).toContainText('Projekt gelöscht');
        await expect(page.locator('main').getByText(clientName, { exact: false })).toHaveCount(0, { timeout: 10000 });
    });

    // This is the sole browser-level native DnD regression. All other status
    // transitions intentionally use the semantic status select below.
    test('Admin verschiebt ein Projekt per Drag & Drop in eine andere Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        test.skip(test.info().project.name !== 'Desktop Chrome', 'Drag & Drop ist nur am Desktop verfügbar');
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

    test('Admin verschiebt ein Projekt semantisch in die Storniert-Spalte', { tag: ['@regression', '@feature:kanban'] }, async ({ page }) => {
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const clientName = `Storno ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(clientName, 'Storniert');

        await kanban.expectColumn('Storniert');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();
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
        const superAdmin = await helper.createIsolatedUser('super_admin');
        const { kanban } = await setup(page, superAdmin, 'Workflow', 'Workflow');
        const clientName = `Storno Del ${Math.random().toString(36).substring(2, 8)}`;

        await kanban.openCreateModal('Anfrage', 'Neues Projekt');
        await kanban.fillField('Kundenname', clientName);
        await kanban.submit();
        await kanban.waitForCreate('/api/management/projects');
        await expect(page.locator('main').getByText(clientName, { exact: false }).first()).toBeVisible();

        await kanban.selectCardStatus(clientName, 'Storniert');

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button').first().click();

        const confirmModal = page.locator('.modal-global');
        await expect(confirmModal).toBeVisible();
        // Promise VOR dem Klick registrieren (Race: DELETE-Response schneller als Listener).
        const deletePromise = kanban.waitForDelete('/api/management/projects');
        await confirmModal.getByRole('button', { name: 'Löschen' }).click();
        await deletePromise;

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

    test('Super-Admin dropt ein Angebot-PDF auf das Board und das Projekt-Formular wird mit Kundenname & E-Mail vorbefüllt', { tag: ['@feature:admin:projects'] }, async ({ page, request }) => {
        // extract-offer (Drop-Extraktion) + invoices/manual (PDF-Erzeugung) erfordern is_super_admin.
        // Ein super_admin sieht den Board-Eintrag als "Workflow" (Sidebar.tsx), nicht "Projekte".
        const superAdmin = await helper.createIsolatedUser('super_admin');
        await setup(page, superAdmin, 'Workflow', 'Workflow');

        // 1) Echtes Angebot-PDF mit eingebettetem Kundenname/-E-Mail über das Backend erzeugen.
        const loginApi = await request.post('/api/auth/login', {
            data: { email: superAdmin.email, password: superAdmin.password },
            headers: { 'Accept': 'application/json' },
        });
        if (!loginApi.ok()) throw new Error(`Admin login failed: ${await loginApi.text()}`);
        const cookie = extractCookieHeader(loginApi);
        if (!cookie) throw new Error('Admin login response did not contain an auth cookie');

        const customerName = `Drop Kunde ${Math.random().toString(36).substring(2, 8)}`;
        const customerEmail = `drop-kunde-${Math.random().toString(36).substring(2, 8)}@example.com`;

        const pdfRes = await request.post('/api/management/invoices/manual', {
            data: {
                type: 'offer',
                invoice_number: 'O-DROP-001',
                date: '2026-08-19',
                due_date: '2026-12-31',
                customer_name: customerName,
                customer_email: customerEmail,
                items: [{ type: 'item', description: 'Fotografie', price: 250, qty: 1 }],
            },
            headers: { 'Accept': 'application/pdf', 'Cookie': cookie },
        });
        expect(pdfRes.status()).toBe(200);
        const pdfBase64 = (await pdfRes.body()).toString('base64');

        // 2) Native Drop-Simulation: Playwright kennt KEIN dataTransfer in dispatchEvent,
        //    daher echtes DragEvent + DataTransfer im Browser-Kontext aufbauen.
        await page.evaluate(({ selector, fileName, fileBase64 }) => {
            const bytes = Uint8Array.from(atob(fileBase64), (c) => c.charCodeAt(0));
            const file = new File([bytes], fileName, { type: 'application/pdf' });
            const dt = new DataTransfer();
            dt.items.add(file);
            const target = document.querySelector(selector);
            if (!target) throw new Error(`Drop-Target ${selector} nicht gefunden`);
            target.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
        }, { selector: 'main .kanban-grid', fileName: 'angebot.pdf', fileBase64: pdfBase64 });

        // 3) Modal öffnet sich und ist mit den aus dem PDF extrahierten Feldern vorbefüllt.
        const modal = page.locator('.modal-open');
        await expect(modal).toBeVisible({ timeout: 20000 });

        const nameInput = modal.locator('.form-control:has-text("Kundenname")').first().locator('input');
        const emailInput = modal.locator('.form-control:has-text("E-Mail")').first().locator('input');

        await expect(nameInput).toHaveValue(customerName, { timeout: 10000 });
        await expect(emailInput).toHaveValue(customerEmail, { timeout: 10000 });

        await modal.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(modal).toHaveCount(0, { timeout: 5000 });
    });
});
