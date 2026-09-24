import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Project board clear-field regression', () => {
    test.describe.configure({ mode: 'serial' });

    let helper: E2ESessionHelper;
    let admin = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        admin = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin kann Preis und Zuweisung im Projekt explizit leeren', { tag: ['@regression', '@feature:kanban'] }, async ({ page, request }) => {
        const clientName = `Clear Projekt ${Math.random().toString(36).substring(2, 8)}`;
        const cookie = await helper.loginAs(admin.email, admin.password);
        const createResponse = await request.post('/api/management/projects', {
            data: {
                client_name: clientName,
                assignee_id: admin.id,
                price_cents: 12345,
            },
            headers: { Accept: 'application/json', Cookie: cookie },
        });
        expect(createResponse.ok()).toBeTruthy();
        const { project } = await createResponse.json() as { project: { id: string } };

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(admin.email, admin.password);
        await sidebar.navigateTo('Projekte');
        await expect(page.locator('main h1')).toContainText('Projekte');

        const card = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.getByRole('button', { name: 'Details' }).click();

        const modal = page.locator('main .modal-open');
        const priceInput = modal.locator('.form-control').filter({ hasText: 'Preis (€)' }).locator('input');
        const assigneeSelect = modal.locator('.form-control').filter({ hasText: 'Zuständig' }).locator('select');
        await expect(priceInput).toHaveValue('123.45');
        await expect(assigneeSelect.locator(`option[value="${admin.id}"]`)).toHaveCount(1);
        await expect(assigneeSelect).toHaveValue(admin.id);
        await priceInput.fill('');
        await assigneeSelect.selectOption('');

        const responsePromise = page.waitForResponse(response =>
            response.url().includes(`/api/management/projects/${project.id}`)
            && response.request().method() === 'PUT',
        );
        await modal.getByRole('button', { name: 'Speichern' }).click();
        const response = await responsePromise;
        expect(response.ok()).toBeTruthy();
        const payload = response.request().postDataJSON() as { price_cents: unknown; assignee_id: unknown };
        expect(payload.price_cents).toBeNull();
        expect(payload.assignee_id).toBeNull();
        await expect(page.locator('main .modal-open')).toHaveCount(0);

        await page.reload();
        const reloadedCard = page.locator('main').getByText(clientName, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await reloadedCard.getByRole('button', { name: 'Details' }).click();
        const reloadedModal = page.locator('main .modal-open');
        await expect(reloadedModal.locator('.form-control').filter({ hasText: 'Preis (€)' }).locator('input')).toHaveValue('');
        await expect(reloadedModal.locator('.form-control').filter({ hasText: 'Zuständig' }).locator('select')).toHaveValue('');
    });
});
