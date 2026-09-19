import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import path from 'path';

async function fillSinglePerson(page: Page, unique: string) {
    const block = page.locator('main').getByTestId('model-person-0');

    await block.getByLabel('Vorname').fill('Maria');
    await block.getByLabel('Nachname').fill('Muster');
    await block.locator('input[type="date"]').fill('1995-05-05');
    await block.locator('input[type="email"]').fill(`access-${unique}@example.com`);
    await block.locator('input[type="tel"]').fill('+43 660 1234567');
    await block.getByLabel('Straße & Nr.').fill('Teststraße 1');
    await block.getByLabel('PLZ', { exact: true }).fill('4020');
    await block.getByLabel('Ort', { exact: true }).fill('Linz');
    await block.getByLabel('Land', { exact: true }).fill('Österreich');
    await block.getByLabel('Erfahrung: Bikini').fill('3');

    await block.getByLabel(/Ich habe die Datenschutzerklärung gelesen/).check();
    await block.getByLabel(/Ich versichere die Richtigkeit/).check();
    await block.getByLabel(/Ich stimme der Kontaktaufnahme zu/).check();
    await block.getByLabel(/Ich stimme der Speicherung der Personen-Fotos/).check();
    await block.getByLabel('Altersnachweis (Ausweis)').setInputFiles(
        path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'),
    );
}

test.describe('Model-Profil-Zugang (Magic Link)', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin erstellt Profil-Link; Model aktualisiert und bestätigt', { tag: ['@feature:model-access'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const unique = Math.random().toString(36).substring(2, 10);

        // 1. Admin legt Einladung im Dialog auf der Models-Seite an und liest den Magic Link.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByTestId('model-invite-open').click();
        await page.getByLabel('Name / Notiz').fill(`Access ${unique}`);
        await page.getByTestId('model-invite-dialog').getByRole('button', { name: 'Einladung erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Einladung wurde angelegt');
        const inviteLink = await page.getByTestId('model-invite-link').inputValue();
        expect(inviteLink).toContain('/model-registrierung/');
        await auth.logout();

        // 2. Gast registriert ein Modell über den Einladungslink.
        await page.goto(inviteLink);
        await expect(page.locator('main').getByRole('heading', { name: 'Model-Registrierung' })).toBeVisible({ timeout: 15000 });
        await fillSinglePerson(page, unique);
        await page.getByRole('button', { name: 'Registrierung absenden' }).click();
        await expect(page.locator('main').getByRole('heading', { name: 'Registrierung erfolgreich' })).toBeVisible({ timeout: 20000 });

        // 3. Admin findet das Modell und erzeugt den Profil-Link.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByLabel('Suche').fill(unique);

        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();

        await expect(page.getByTestId('model-access-link-create')).toBeVisible();
        await page.getByTestId('model-access-link-create').click();

        const accessInput = page.getByTestId('model-access-link-url');
        await expect(accessInput).toBeVisible({ timeout: 15000 });
        const accessLink = await accessInput.inputValue();
        expect(accessLink).toContain('/model-profil/');

        await page.getByRole('button', { name: 'Schließen' }).click();
        await auth.logout();

        // 4. Model öffnet den Profil-Link (Gast), aktualisiert und bestätigt.
        await page.goto(accessLink);
        await expect(page.getByTestId('model-profile-access')).toBeVisible({ timeout: 15000 });
        // Profile already has an age proof, so the re-upload field is hidden
        // (the no-proof branch cannot be produced through any API).
        await expect(page.getByTestId('profile-age-proof')).toHaveCount(0);

        await page.getByLabel('Künstlername / Pseudonym').fill(`Alias-${unique}`);
        await page.getByTestId('model-profile-save').click();
        await expect(page.locator('.toast')).toContainText('Profil wurde gespeichert');

        await page.getByTestId('model-profile-confirm').click();
        await page.locator('.modal-global').getByRole('button', { name: 'Bestätigen', exact: true }).click();
        await expect(page.locator('.toast')).toContainText('Profil wurde bestätigt');

        // 5. Cleanup: die im Test erzeugten CRM-Customers entfernen.
        const modelsRes = await request.get('/api/management/models?q=' + encodeURIComponent(unique), {
            headers: { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() },
        });
        const createdModels = await modelsRes.json() as Array<{ customer_id: string }>;
        for (const model of createdModels) {
            await helper.deleteModelCustomer(model.customer_id);
        }
    });

    test('Unbekannter Profil-Token zeigt 404', { tag: ['@feature:model-access'] }, async ({ page }) => {
        await page.goto('/model-profil/' + 'a'.repeat(64));
        await expect(page.getByTestId('model-profile-error')).toContainText('Profil-Link nicht gefunden', { timeout: 15000 });
    });
});
