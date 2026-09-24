import {test, expect} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

test.describe('Management save error handling', () => {
    let helper: E2ESessionHelper;
    let admin = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        admin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('CRM keeps entered customer data open when the real save request is rejected', {tag: ['@regression', '@feature:admin:management']}, async ({page}) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(admin.email, admin.password);
        await sidebar.navigateTo('Kunden (CRM)');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', {name: 'Kunden (CRM)'})).toBeVisible();

        await main.getByRole('button', {name: /Neuer Kunde/}).click();
        const modal = main.locator('.modal-open');
        await expect(modal).toBeVisible();
        const nameInput = modal.getByRole('textbox', {name: 'Name / Ansprechpartner'});
        const companyInput = modal.getByRole('textbox', {name: 'Firma'});
        const birthdateInput = modal.getByLabel('Geburtsdatum');
        const customerName = `Fehlerfall ${Math.random().toString(36).substring(2, 8)}`;
        await nameInput.fill(customerName);
        await companyInput.fill('Nicht gespeicherte Firma');
        // The browser-side form intentionally accepts this value; the real API
        // validation rejects it, exercising the modal's rejected-save state.
        await birthdateInput.fill('2099-01-01');

        const responsePromise = page.waitForResponse(response => {
            const url = new URL(response.url());
            return url.pathname === '/api/management/customers' && response.request().method() === 'POST';
        });
        await modal.getByRole('button', {name: 'Speichern'}).click();
        const response = await responsePromise;
        expect(response.status()).toBe(422);

        await expect(modal).toBeVisible();
        await expect(nameInput).toHaveValue(customerName);
        await expect(companyInput).toHaveValue('Nicht gespeicherte Firma');
        await expect(birthdateInput).toHaveValue('2099-01-01');
        await expect(page.getByRole('alert').filter({hasText: /birthdate|Geburtsdatum|before/i})).toBeVisible();
    });
});
