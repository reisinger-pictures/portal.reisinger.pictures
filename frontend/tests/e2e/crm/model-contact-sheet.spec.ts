import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Model Contact Sheet Export', () => {
    let helper: E2ESessionHelper;
    let adminUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        adminUser = await helper.createIsolatedUser('admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin exportiert internes und externes Contact Sheet als PDF', { tag: ['@feature:model-export', '@smoke'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Fixture via the sanctioned API helper: one public + one internal photo.
        const model = await helper.createRegisteredModel({ photoCount: 2 });

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        // The Models filter shares its accessible name "Suche" with the header
        // gallery search and that search's submit button, so getByLabel('Suche')
        // is a strict-mode violation. The `main` landmark cannot disambiguate
        // either: it wraps the sticky header as well. Target the filter via the
        // id its <label htmlFor> points at.
        await page.locator('#model-filter-q').fill(model.firstName);

        // The prefix matches the card button only: the wrapper around it uses
        // the `model-frame-` test id, so one element per model is hit.
        const card = page.locator('[data-testid^="model-card-"]').first();
        await expect(card).toBeVisible({ timeout: 15000 });
        await card.click();

        const detail = page.locator('main');
        const internalButton = detail.getByTestId('model-contact-sheet-internal');
        await expect(internalButton).toBeVisible();
        await expect(detail.getByTestId('model-contact-sheet-external')).toBeVisible();

        // --- Internal variant -------------------------------------------------
        const internalResponsePromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet') && response.url().includes('variant=internal'),
        );
        const internalDownloadPromise = page.waitForEvent('download');
        await internalButton.click();

        const internalResponse = await internalResponsePromise;
        expect(internalResponse.status()).toBe(200);
        expect(internalResponse.headers()['content-type']).toContain('application/pdf');
        expect(internalResponse.headers()['content-disposition']).toContain('attachment');
        expect(internalResponse.headers()['content-disposition']).toMatch(/filename="?model-.*-internal-\d{8}\.pdf"?/);

        const internalDownload = await internalDownloadPromise;
        expect(internalDownload.suggestedFilename()).toMatch(/-internal-\d{8}\.pdf$/);
        await expect(page.locator('.toast')).toContainText('Contact Sheet wurde heruntergeladen');

        // --- External variant -------------------------------------------------
        const externalResponsePromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet') && response.url().includes('variant=external'),
        );
        const externalDownloadPromise = page.waitForEvent('download');
        await detail.getByTestId('model-contact-sheet-external').click();

        const externalResponse = await externalResponsePromise;
        expect(externalResponse.status()).toBe(200);
        expect(externalResponse.headers()['content-type']).toContain('application/pdf');

        const externalDownload = await externalDownloadPromise;
        expect(externalDownload.suggestedFilename()).toMatch(/-external-\d{8}\.pdf$/);
    });

    test('Admin exportiert die Auswahl der Ergebnisliste als Contact Sheet', { tag: ['@feature:model-export'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Zwei Modelle mit einem gemeinsamen, einmaligen Präfix: die Liste
        // lässt sich dadurch deterministisch auf genau diese zwei filtern.
        const prefix = `E2Ebulk${Math.random().toString(36).substring(2, 8)}`;
        await helper.createRegisteredModel({ photoCount: 1, namePrefix: prefix });
        await helper.createRegisteredModel({ photoCount: 1, namePrefix: prefix });

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(prefix);

        const main = page.locator('main');
        const checkboxes = main.getByTestId(/^model-select-/);
        await expect(checkboxes).toHaveCount(2, { timeout: 15000 });

        // Der Checkbox darf das Detail-Dialog nicht öffnen.
        await checkboxes.nth(0).check();
        await checkboxes.nth(1).check();
        await expect(main.getByTestId('model-selection-toolbar')).toBeVisible();
        await expect(main.getByTestId('model-selection-count')).toContainText('2');
        await expect(main.getByRole('dialog')).toHaveCount(0);

        const responsePromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet') && response.request().method() === 'POST',
        );
        const downloadPromise = page.waitForEvent('download');
        await main.getByTestId('model-bulk-contact-sheet-external').click();

        const response = await responsePromise;
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/pdf');
        expect(response.headers()['content-disposition']).toContain('attachment');
        expect(response.headers()['content-disposition']).toMatch(/filename="?models-contact-sheet-external-\d{8}\.pdf"?/);

        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/^models-contact-sheet-external-\d{8}\.pdf$/);
        await expect(page.locator('.toast')).toContainText('Contact Sheet wurde heruntergeladen');
    });

    test('Admin erstellt einen Extern-Link, der ohne Login zum Download führt', { tag: ['@feature:model-export'] }, async ({ page, browser }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const prefix = `E2Eshare${Math.random().toString(36).substring(2, 8)}`;
        await helper.createRegisteredModel({ photoCount: 1, namePrefix: prefix });

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(prefix);

        const main = page.locator('main');
        const checkboxes = main.getByTestId(/^model-select-/);
        await expect(checkboxes).toHaveCount(1, { timeout: 15000 });
        await checkboxes.nth(0).check();
        await expect(main.getByTestId('model-selection-toolbar')).toBeVisible();

        const createPromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet/share') && response.request().method() === 'POST',
        );
        await main.getByTestId('model-bulk-contact-sheet-share').click();

        const createResponse = await createPromise;
        expect(createResponse.status()).toBe(201);

        const shareUrl = await main.getByTestId('model-bulk-share-link-url').inputValue();
        expect(shareUrl).toContain('/kontaktbogen/');

        // Ein eigener Browser-Kontext: kein Login, keine Cookies, keine Session.
        const guestContext = await browser.newContext();
        const guestPage = await guestContext.newPage();
        await guestPage.goto(shareUrl);

        const downloadButton = guestPage.getByTestId('contact-sheet-share-download');
        await expect(downloadButton).toBeVisible();

        const responsePromise = guestPage.waitForResponse(
            response => response.url().includes('/api/public/model-contact-sheet/'),
        );
        const downloadPromise = guestPage.waitForEvent('download');
        await downloadButton.click();

        const response = await responsePromise;
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/pdf');
        expect(response.headers()['content-disposition']).toMatch(/filename="?models-contact-sheet-external-\d{8}\.pdf"?/);

        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/^models-contact-sheet-external-\d{8}\.pdf$/);

        await guestContext.close();
    });

    /**
     * Der **Dialog-Pfad** des Extern-Links — der Pfad, den vorher kein Spec
     * fuhr (die Auswahlleiste der Ergebnisliste war der einzige abgedeckte).
     *
     * Er ist der gefährlichere: der Dialog zeigt den erzeugten Link im State
     * des Dialogs. Überlebte der State den Profilwechsel, zeigte der Block
     * „Extern-Link" nach dem Öffnen eines **anderen** Profils weiterhin die
     * URL des ersten — wer sie kopierte, schickte das falsche Blatt
     * (Künstlername, Ort, Foto eines anderen Models) an den Empfänger. Der
     * Spec fährt deshalb beide Hälften: der Gast lädt das Blatt ohne
     * Session, und der Dialog zeigt nach dem Wechsel **keinen** Link mehr.
     */
    test('Admin erstellt den Extern-Link im Detail-Dialog; der Gast lädt das Blatt ohne Login', { tag: ['@feature:model-export'] }, async ({ page, browser }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // Zwei Modelle mit einem gemeinsamen, einmaligen Präfix: die Liste
        // lässt sich dadurch deterministisch auf genau diese zwei filtern.
        const prefix = `E2Edetail${Math.random().toString(36).substring(2, 8)}`;
        const first = await helper.createRegisteredModel({ photoCount: 1, namePrefix: prefix });
        const second = await helper.createRegisteredModel({ photoCount: 1, namePrefix: prefix });

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Models');
        await expect(page.locator('main').getByRole('heading', { name: 'Models', exact: true })).toBeVisible({ timeout: 15000 });
        await page.locator('#model-filter-q').fill(prefix);

        const main = page.locator('main');
        const cards = main.getByTestId(/^model-card-/);
        await expect(cards).toHaveCount(2, { timeout: 15000 });

        // Die Karte über ihren Anzeigenamen zu wählen statt über ihre
        // Position: die Liste sortiert nach Übereinstimmung, und ein Klick
        // auf "die erste Karte" wäre nach einem Filterwechsel nicht
        // deterministisch.
        const cardOf = (name: string) => cards.filter({ hasText: name });

        // --- Das Detail des ersten Profils öffnen --------------------------
        await cardOf(first.firstName).click();
        const dialog = main.getByRole('dialog');
        await expect(dialog).toContainText(first.firstName);

        const createPromise = page.waitForResponse(
            response => response.url().includes('/contact-sheet/share') && response.request().method() === 'POST',
        );
        await dialog.getByTestId('model-contact-sheet-share').click();

        const createResponse = await createPromise;
        expect(createResponse.status()).toBe(201);

        const shareUrl = await dialog.getByTestId('model-contact-sheet-share-url').inputValue();
        expect(shareUrl).toContain('/kontaktbogen/');
        await expect(dialog.getByTestId('model-contact-sheet-share-expiry')).toBeVisible();
        await expect(page.locator('.toast').first()).toContainText('Extern-Link wurde erstellt.');

        // --- Der Gast öffnet den Link ohne Session --------------------------
        const guestContext = await browser.newContext();
        const guestPage = await guestContext.newPage();
        await guestPage.goto(shareUrl);

        const downloadButton = guestPage.getByTestId('contact-sheet-share-download');
        await expect(downloadButton).toBeVisible();

        const responsePromise = guestPage.waitForResponse(
            response => response.url().includes('/api/public/model-contact-sheet/'),
        );
        const downloadPromise = guestPage.waitForEvent('download');
        await downloadButton.click();

        const response = await responsePromise;
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('application/pdf');
        expect(response.headers()['content-disposition']).toMatch(/filename="?models-contact-sheet-external-\d{8}\.pdf"?/);

        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/^models-contact-sheet-external-\d{8}\.pdf$/);

        await guestContext.close();

        // --- Ein anderes Profil darf den alten Link nicht zeigen ------------
        // Der Header-Schließer und der Fuß-Button heißen beide "Schließen" —
        // der Footer ist der eindeutige.
        await dialog.locator('.modal-action').getByRole('button', { name: 'Schließen', exact: true }).click();
        await expect(main.getByRole('dialog')).toHaveCount(0);

        await cardOf(second.firstName).click();
        const secondDialog = main.getByRole('dialog');
        await expect(secondDialog).toContainText(second.firstName);

        // Der Link des **ersten** Profils darf hier nicht mehr stehen: er
        // gehört zu einem anderen Profil und würde das falsche Blatt
        // verschicken.
        const sharePanel = secondDialog.getByTestId('model-contact-sheet-share-panel');
        await expect(sharePanel.getByTestId('model-contact-sheet-share-url')).toHaveCount(0);
        await expect(sharePanel).toContainText('Noch kein Extern-Link erstellt.');
    });
});
