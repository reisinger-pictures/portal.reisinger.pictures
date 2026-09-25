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

    test('Customer modal exposes labeled, uniquely identified combobox keyboard behavior', { tag: ['@regression', '@feature:admin:management'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(admin.email, admin.password);
        await sidebar.navigateTo('Kunden (CRM)');

        const main = page.getByRole('main');
        await main.getByRole('button', { name: /Neuer Kunde/ }).click();
        const modal = main.locator('.modal-open');
        await expect(modal).toBeVisible();

        const zipInput = modal.getByRole('combobox', { name: 'PLZ' });
        const cityInput = modal.getByRole('combobox', { name: 'Stadt' });
        const countryInput = modal.getByRole('combobox', { name: 'Land' });
        const labeledFields = [
            modal.getByLabel('Name / Ansprechpartner'),
            modal.getByLabel('Firma'),
            modal.getByLabel('E-Mail Adresse'),
            modal.getByLabel('Geburtsdatum'),
            modal.getByLabel('U-ID (Umsatzsteuer-ID)'),
            modal.getByLabel('Straße & Hausnummer'),
            zipInput,
            cityInput,
            countryInput,
        ];

        for (const field of labeledFields) {
            await expect(field).toHaveAttribute('id', /.+/);
        }
        const fieldIds = await Promise.all(labeledFields.map(field => field.getAttribute('id')));
        expect(new Set(fieldIds).size).toBe(labeledFields.length);

        const locationGroup = modal.getByRole('group', { name: 'PLZ & Stadt' });
        const locationGroupLabelId = await locationGroup.getAttribute('aria-labelledby');
        if (!locationGroupLabelId) throw new Error('PLZ & Stadt group is missing its label reference');
        const locationGroupLabel = modal.locator(`[id="${locationGroupLabelId}"]`);
        await expect(locationGroup).toBeVisible();
        await expect(locationGroupLabel).toHaveText('PLZ & Stadt');
        await expect(locationGroup.getByRole('combobox', { name: 'PLZ' })).toHaveCount(1);
        await expect(locationGroup.getByRole('combobox', { name: 'Stadt' })).toHaveCount(1);
        await expect(zipInput).toHaveAttribute('aria-label', 'PLZ');
        await expect(cityInput).toHaveAttribute('aria-label', 'Stadt');

        const zipId = await zipInput.getAttribute('id');
        if (!zipId) throw new Error('PLZ combobox did not receive an id');

        await expect(zipInput).toHaveAttribute('aria-expanded', 'false');
        await zipInput.focus();
        await expect(zipInput).toBeFocused();
        await zipInput.fill('8010');
        await expect(zipInput).toHaveAttribute('aria-expanded', 'true', { timeout: 10000 });
        await zipInput.press('ArrowDown');

        const listboxId = await zipInput.getAttribute('aria-controls');
        const activeDescendant = await zipInput.getAttribute('aria-activedescendant');
        if (!listboxId || !activeDescendant) {
            throw new Error('PLZ combobox did not expose its keyboard listbox relationship');
        }
        expect(listboxId).toBe(`${zipId}-listbox`);
        await expect(modal.getByRole('listbox')).toHaveAttribute('id', listboxId);
        await expect(modal.getByRole('option').first()).toHaveAttribute('id', activeDescendant);
        await expect(zipInput).toHaveAttribute('aria-activedescendant', activeDescendant);

        await zipInput.press('Enter');
        await expect(zipInput).toHaveAttribute('aria-expanded', 'false');
        await expect(zipInput).toBeFocused();
        expect(await zipInput.getAttribute('aria-activedescendant')).toBeNull();
    });
});
