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
        await page.locator('#model-filter-q').fill(unique);

        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();

        await expect(page.getByTestId('model-access-link-create')).toBeVisible();
        await page.getByTestId('model-access-link-create').click();

        const accessInput = page.getByTestId('model-access-link-url');
        await expect(accessInput).toBeVisible({ timeout: 15000 });
        const accessLink = await accessInput.inputValue();
        expect(accessLink).toContain('/model-profil/');

        // Scope to the dialog and match the name exactly: the sidebar's mobile
        // close button is labelled "Menü schließen" and is only rendered on
        // narrow viewports, so an unscoped substring match becomes a strict-mode
        // violation on mobile.
        // The shell renders a labelled header close button and the footer has its
        // own, so an unscoped 'Schließen' matches two. Scope to the footer.
        await page.getByRole('dialog').locator('.modal-action').getByRole('button', { name: 'Schließen', exact: true }).click();
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

    test('Owner stuft das einzige öffentliche Hauptbild auf intern zurück (Primary-Clear statt 422) und wählt danach ein neues Hauptbild', { tag: ['@feature:model-access'] }, async ({ page }) => {
        // Fixture via the sanctioned API helper: 2 photos, first public + primary.
        const model = await helper.createRegisteredModel({ photoCount: 2 });
        if (!model.customerId) throw new Error('Registered model has no customer id');
        const access = await helper.createModelAccessLink(model.customerId);
        expect(access.link).toContain('/model-profil/');

        await page.goto(access.link);
        await expect(page.getByTestId('model-profile-access')).toBeVisible({ timeout: 15000 });

        const photos = page.getByTestId('profile-photos');
        await expect(photos).toBeVisible();
        const visibility = photos.getByLabel('Sichtbarkeit');
        await expect(visibility).toHaveCount(2);
        await expect(visibility.nth(0)).toHaveValue('public');
        await expect(photos.getByRole('radio').nth(0)).toBeChecked();

        // Demote the only public (primary) photo to internal without a replacement.
        // The frontend must send `is_primary: false` so the backend clears the
        // election instead of rejecting the update with a 422.
        await visibility.nth(0).selectOption('internal');
        await expect(photos.getByRole('radio').nth(0)).toBeDisabled();
        await page.getByTestId('model-profile-save').click();
        await expect(page.locator('.toast')).toContainText('Profil wurde gespeichert');
        await expect(page.getByTestId('profile-photos-error')).toHaveCount(0);

        // Reload proves persistence: photo internal, no primary left.
        await page.reload();
        await expect(page.getByTestId('profile-photos')).toBeVisible({ timeout: 15000 });
        await expect(page.getByTestId('profile-photos').getByLabel('Sichtbarkeit').nth(0)).toHaveValue('internal');
        await expect(page.getByTestId('profile-photos').getByRole('radio').nth(0)).not.toBeChecked();

        // Promote the second photo: make it public, then elect it as main image.
        await page.getByTestId('profile-photos').getByLabel('Sichtbarkeit').nth(1).selectOption('public');
        await page.getByTestId('profile-photos').getByRole('radio').nth(1).check();
        await page.getByTestId('model-profile-save').click();
        await expect(page.locator('.toast')).toContainText('Profil wurde gespeichert');

        await page.reload();
        await expect(page.getByTestId('profile-photos').getByLabel('Sichtbarkeit').nth(1)).toHaveValue('public', { timeout: 15000 });
        await expect(page.getByTestId('profile-photos').getByRole('radio').nth(1)).toBeChecked();
    });

    test('Manager gibt die Verwaltung im Profil an ein Mitglied ab', { tag: ['@feature:model-access'] }, async ({ page }) => {
        // Two-person group via the sanctioned API helper (person 0 = manager).
        const group = await helper.createRegisteredGroup();
        if (!group.manager.customerId) throw new Error('Registered group manager has no customer id');
        const access = await helper.createModelAccessLink(group.manager.customerId);
        expect(access.link).toContain('/model-profil/');

        await page.goto(access.link);
        await expect(page.getByTestId('model-profile-access')).toBeVisible({ timeout: 15000 });

        const main = page.locator('main');
        const managerSection = main.getByTestId('model-profile-manager');
        await expect(managerSection).toBeVisible();

        const actBlock = managerSection.locator('[data-testid^="model-profile-act-"]').first();
        await expect(actBlock).toContainText(`${group.manager.firstName} Gruppe`);

        await actBlock.getByLabel('Verwaltung abgeben an').selectOption({ label: `${group.member.firstName} Gruppe` });
        await actBlock.getByRole('button', { name: 'Verwaltung abgeben' }).click();
        await page.locator('.modal-global').getByRole('button', { name: 'Bestätigen', exact: true }).click();
        await expect(page.locator('.toast')).toContainText('Verwaltung wurde abgegeben');

        // Reload proves persistence: the member is now the manager and the
        // owner (former manager) no longer sees the hand-over control.
        await page.reload();
        await expect(main.getByTestId('model-profile-manager')).toBeVisible({ timeout: 15000 });
        const reloadedAct = main.getByTestId('model-profile-manager').locator('[data-testid^="model-profile-act-"]').first();
        await expect(reloadedAct).toContainText(`${group.member.firstName} Gruppe`);
        await expect(reloadedAct.getByLabel('Verwaltung abgeben an')).toHaveCount(0);
    });

    test('Unbekannter Profil-Token zeigt 404', { tag: ['@feature:model-access'] }, async ({ page }) => {
        await page.goto('/model-profil/' + 'a'.repeat(64));
        await expect(page.getByTestId('model-profile-error')).toContainText('Profil-Link nicht gefunden', { timeout: 15000 });
    });
});
