import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import path from 'path';

interface PersonData {
    firstName: string;
    lastName: string;
    birthdate: string;
    email: string;
    phone: string;
    city: string;
    /** Experience is a slider now; this is the ordinal step (0..4). */
    experienceIndex: string;
    withAgeProof: boolean;
}

/**
 * Server text of the file-level 422 (`ModelFileStore::storeUpload()` rejects
 * with a bare `file` key). The message is user-visible and must reach the
 * general error area of the registration form.
 */
const FILE_LEVEL_ERROR_MESSAGE = 'Das Bild überschreitet nach der EXIF-Bereinigung die erlaubte Dateigröße. Bitte eine kleinere Datei hochladen.';

function personBlock(page: Page, index: number) {
    return page.locator('main').getByTestId(`model-person-${index}`);
}

async function fillPerson(page: Page, index: number, data: PersonData) {
    const block = personBlock(page, index);

    await block.getByLabel('Vorname').fill(data.firstName);
    await block.getByLabel('Nachname').fill(data.lastName);
    await block.locator('input[type="date"]').fill(data.birthdate);
    await block.locator('input[type="email"]').fill(data.email);
    await block.locator('input[type="tel"]').fill(data.phone);
    await block.getByLabel('Straße & Nr.').fill('Teststraße 1');
    await block.getByLabel('PLZ', { exact: true }).fill('4020');
    await block.getByLabel('Ort', { exact: true }).fill(data.city);
    await block.getByLabel('Land', { exact: true }).fill('Österreich');
    // Experience renders as a slider (step index 0..4).
    await block.getByLabel('Erfahrung: Bikini').fill(data.experienceIndex);

    // v2 renders the consent statements as full sentences (§2.9).
    await block.getByLabel(/Ich habe die Datenschutzerklärung gelesen/).check();
    await block.getByLabel(/Ich versichere die Richtigkeit/).check();
    await block.getByLabel(/Ich stimme der Kontaktaufnahme zu/).check();
    await block.getByLabel(/Ich stimme der Speicherung der Personen-Fotos/).check();

    // `consent_all_persons` is only visible for the manager of a multi-person
    // act (v2 `multiple_persons`), so it is checked after adding person 2.

    if (data.withAgeProof) {
        await block.getByLabel('Altersnachweis (Ausweis)').setInputFiles(
            path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'),
        );
    }
}

test.describe('Model-Registrierung (öffentlicher Flow)', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin lädt ein, Manager registriert 2 Personen mit Altersnachweis; zweiter Aufruf schlägt fehl', { tag: ['@feature:model-registration'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const mailpit = new MailpitHelper(request);

        const unique = Math.random().toString(36).substring(2, 10);
        const inviteEmail = `model-manager-${unique}@example.com`;

        // 1. Admin legt die Einladung im Dialog auf der Models-Seite an.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByTestId('model-invite-open').click();

        await page.getByLabel('E-Mail der eingeladenen Person').fill(inviteEmail);
        await page.getByTestId('model-invite-dialog').getByRole('button', { name: 'Einladung erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Einladung wurde angelegt');
        await expect(page.getByTestId('model-invite-created')).toContainText('Zusätzlich per E-Mail versendet');

        // 2. Token aus der Einladungsmail lesen.
        const token = await mailpit.extractModelRegistrationToken(inviteEmail);
        expect(token).toBeTruthy();

        await auth.logout();

        // 3. Öffentlicher Flow (Gast, kein Login). Token-Link = externe Einladung.
        await page.goto(`/model-registrierung/${token}`);
        await expect(page.locator('main').getByRole('heading', { name: 'Model-Registrierung' })).toBeVisible({ timeout: 15000 });

        await fillPerson(page, 0, {
            firstName: 'Maria',
            lastName: 'Muster',
            birthdate: '1995-05-05',
            email: `maria-${unique}@example.com`,
            phone: '+43 660 1234567',
            city: 'Linz',
            experienceIndex: '3',
            withAgeProof: true,
        });

        // Zweite Person hinzufügen und ausfüllen.
        await page.getByTestId('add-person').click();
        await expect(personBlock(page, 1)).toBeVisible();
        await fillPerson(page, 1, {
            firstName: 'Anna',
            lastName: 'Beispiel',
            birthdate: '1998-03-03',
            email: `anna-${unique}@example.com`,
            phone: '+43 660 7654321',
            city: 'Wien',
            experienceIndex: '0',
            // v2: the age proof is mandatory for every person (§2.8).
            withAgeProof: true,
        });

        // Manager consent is only rendered once a second person exists.
        await personBlock(page, 0).getByLabel(/Ich versichere, dass alle erfassten Personen/).check();

        // 4. Absenden → Erfolgszustand.
        await page.getByRole('button', { name: 'Registrierung absenden' }).click();
        await expect(page.locator('main').getByRole('heading', { name: 'Registrierung erfolgreich' })).toBeVisible({ timeout: 20000 });
        await expect(page.locator('main')).toContainText('2');

        // 5. Zweiter Aufruf desselben Links schlägt fehl (Token verbraucht → 410).
        await page.goto(`/model-registrierung/${token}`);
        await expect(page.locator('main')).toContainText('bereits verwendet', { timeout: 15000 });
        await expect(page.locator('main').getByRole('heading', { name: 'Model-Registrierung' })).toHaveCount(0);

        // 6. Cleanup: Die im öffentlichen Flow erzeugten CRM-Customers entfernen
        // (beide Personen teilen den `unique`-Suffix in der E-Mail).
        const modelsRes = await request.get('/api/management/models?q=' + encodeURIComponent(unique), {
            headers: { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() },
        });
        const createdModels = await modelsRes.json() as Array<{ customer_id: string }>;
        for (const model of createdModels) {
            await helper.deleteModelCustomer(model.customer_id);
        }
    });

    test('Datei-Level-422 zeigt die Server-Meldung im allgemeinen Fehlerbereich und erlaubt einen Retry', { tag: ['@feature:model-registration'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const unique = Math.random().toString(36).substring(2, 10);

        // 1. Admin legt eine Einladung nur mit Label an → kopierbarer Magic Link.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByTestId('model-invite-open').click();

        await page.getByLabel('Name / Notiz').fill(`Dateigroesse ${unique}`);
        await page.getByTestId('model-invite-dialog').getByRole('button', { name: 'Einladung erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Einladung wurde angelegt');

        const link = await page.getByTestId('model-invite-link').inputValue();
        expect(link).toContain('/model-registrierung/');

        await auth.logout();

        // 2. Gast öffnet den Magic Link (externe Einladung) und füllt die Person.
        await page.goto(link);
        await expect(page.locator('main').getByRole('heading', { name: 'Model-Registrierung' })).toBeVisible({ timeout: 15000 });

        await fillPerson(page, 0, {
            firstName: 'Maria',
            lastName: 'Muster',
            birthdate: '1995-05-05',
            email: `maria-datei-${unique}@example.com`,
            phone: '+43 660 1234567',
            city: 'Linz',
            experienceIndex: '3',
            withAgeProof: true,
        });

        // 3. Der Submit wird mit einem Datei-Level-422 beantwortet. `file` hat im
        //    Formular keinen Renderer (der Store antwortet mit einem nackten
        //    `file`-Key), deshalb muss der Servertext im allgemeinen
        //    Fehlerbereich landen — sonst sieht die Person nur einen leeren
        //    Hinweis ohne markiertes Feld.
        await page.route('**/api/model-registration/**', async route => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            await route.fulfill({
                status: 422,
                contentType: 'application/json',
                body: JSON.stringify({
                    message: FILE_LEVEL_ERROR_MESSAGE,
                    errors: { file: [FILE_LEVEL_ERROR_MESSAGE] },
                }),
            });
        });

        await page.getByRole('button', { name: 'Registrierung absenden' }).click();

        const generalError = page.locator('main').getByTestId('model-registration-general-error');
        await expect(generalError).toContainText(FILE_LEVEL_ERROR_MESSAGE, { timeout: 15000 });
        // Das Formular bleibt nutzbar: der Fehler steht außerhalb der Felder.
        await expect(page.locator('main').getByRole('button', { name: 'Registrierung absenden' })).toBeVisible();

        // 4. Mock entfernen und erneut absenden → die Registrierung läuft durch.
        await page.unroute('**/api/model-registration/**');
        await page.getByRole('button', { name: 'Registrierung absenden' }).click();
        await expect(page.locator('main').getByRole('heading', { name: 'Registrierung erfolgreich' })).toBeVisible({ timeout: 20000 });

        // 5. Cleanup: die im öffentlichen Flow erzeugte CRM-Customer entfernen.
        const modelsRes = await request.get('/api/management/models?q=' + encodeURIComponent(unique), {
            headers: { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() },
        });
        const createdModels = await modelsRes.json() as Array<{ customer_id: string }>;
        for (const model of createdModels) {
            await helper.deleteModelCustomer(model.customer_id);
        }
    });

    test('Admin erstellt Einladung nur mit Label (Magic-Link-Flow) und Gast öffnet den Link', { tag: ['@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const unique = Math.random().toString(36).substring(2, 10);
        const label = `WhatsApp-Kontakt ${unique}`;

        // 1. Admin legt die Einladung nur mit Label an (keine E-Mail).
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByTestId('model-invite-open').click();

        await page.getByLabel('Name / Notiz').fill(label);
        await page.getByTestId('model-invite-dialog').getByRole('button', { name: 'Einladung erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Einladung wurde angelegt');

        // 2. Der kopierbare Magic Link wird prominent angezeigt und ist in der Liste sichtbar.
        const link = await page.getByTestId('model-invite-link').inputValue();
        expect(link).toContain('/model-registrierung/');
        await expect(page.getByTestId('model-invite-created')).not.toContainText('Zusätzlich per E-Mail versendet');
        // Scope to the invite dialog: the Models list renders model-card matrices
        // (`main table`) as well, which made the bare locator ambiguous.
        await expect(page.getByTestId('model-invite-dialog').locator('table')).toContainText(label);

        await auth.logout();

        // 3. Gast öffnet den Magic Link (externe Einladung) → Formular rendert.
        await page.goto(link);
        await expect(page.locator('main').getByRole('heading', { name: 'Model-Registrierung' })).toBeVisible({ timeout: 15000 });
    });
});

test.describe('Model-Registrierung Admin-Gate', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Nur Admins sehen die Model-Registrierung und können Einladungen anlegen', { tag: ['@smoke', '@feature:model-registration'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const adminUser = await helper.createIsolatedUser('admin');
        const photographer = await helper.createIsolatedUser('photographer');
        const unique = Math.random().toString(36).substring(2, 10);

        // Admin: ein Sidebar-Eintrag "Models", Einladung im Dialog.
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.getByTestId('model-invite-open').click();
        await expect(page.getByTestId('model-invite-dialog')).toBeVisible();

        const inviteEmail = `gate-${unique}@example.com`;
        await page.getByLabel('E-Mail der eingeladenen Person').fill(inviteEmail);
        await page.getByTestId('model-invite-dialog').getByRole('button', { name: 'Einladung erstellen' }).click();
        await expect(page.locator('.toast')).toContainText('Einladung wurde angelegt');
        await expect(page.getByTestId('model-invite-dialog').locator('table')).toContainText(inviteEmail);
        await page.getByTestId('model-invite-close').click();

        // Admin sieht die Model-Suche mit dem Geschlechts-Filter.
        await expect(page.getByLabel('Geschlecht')).toBeVisible();

        await auth.logout();

        // Photographer: kein Models-Eintrag; direkter URL-Zugriff erzeugt kein Formular.
        await auth.login(photographer.email, photographer.password);
        await expect(page.locator('aside ul.menu').getByText('Models', { exact: true })).toHaveCount(0);
        await expect(page.locator('aside ul.menu').getByText('Model-Registrierungen')).toHaveCount(0);

        await page.goto('/admin-model-invites');
        await expect(page.getByTestId('model-invite-open')).toHaveCount(0);
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toHaveCount(0);
    });
});
