import { test, expect, type Page, type Request, type Response } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ModalHelper } from '../../helpers/ModalHelper';
import { FormHelper } from '../../helpers/FormHelper';

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * The one request the FTP inbox reads its card from.
 *
 * `useFtp` switches SWR's own mount revalidation off and reads the status
 * once for the mount it belongs to, so this GET is the only status request a
 * mount produces — and the only one a route mock has to answer.
 */
function isFtpStatusRead(response: Response): boolean {
    const url = new URL(response.url());
    return url.pathname === '/api/management/ftp/status' && response.request().method() === 'GET';
}

/**
 * The import request the card must not start on its own.
 *
 * This is the network-side twin of the phase list below: the list records
 * the mocked requests, this predicate watches the network for a request that
 * would have to appear in a window in which no import is allowed.
 */
function isFtpProcessRequest(request: Request): boolean {
    const url = new URL(request.url());
    return url.pathname === '/api/management/ftp/process' && request.method() === 'POST';
}

/**
 * The window in which an unwanted import would have to show up.
 *
 * `waitForRequest` only sees requests that START after the call, so the window
 * has to open after the state it watches for has settled — otherwise a request
 * that was already in flight would be invisible to it. The rejection on
 * timeout is the proof that nothing happened; it is caught here and turned
 * into `true`, because an unhandled rejection would fail the test for the
 * wrong reason.
 */
async function noImportRequestWithin(page: Page, windowMs = 1000): Promise<boolean> {
    const request = await page.waitForRequest(isFtpProcessRequest, { timeout: windowMs }).catch(() => null);
    return request === null;
}

/**
 * The phase an import request was made in.
 *
 * The phase tells the *expected* import apart from an *unwanted* one:
 *  - `before-manual`: the request was made before the test asked for the
 *    manual import — the window in which an automatic catch-up run (D-36)
 *    would break the contract;
 *  - `manual`: the request was made in the window the manual import was
 *    requested in;
 *  - `after-manual`: the request was made after the manual import had
 *    settled — the window of the catch-up run of the next mount.
 *
 * The recorded list is the proof for the "no automatic import" contract: it
 * holds exactly one entry per expected import, so an unwanted run cannot pass
 * unnoticed, and the phase says which window it came from.
 */
type ImportPhase = 'before-manual' | 'manual' | 'after-manual';

test.describe('G1: FTP Inbox Feature', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('FTP Inbox is visible for photographers on Dashboard', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.locator('main');
        await expect(main.locator('h2', { hasText: 'FTP Inbox' })).toBeVisible();
        await expect(main.locator('code.font-bold.font-mono')).toBeVisible();
    });

    test('FTP Inbox shows quarantined files and their SFTP folder', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        let quarantinePath = '';

        // The mock is registered BEFORE the login, i.e. before the first page
        // load of this test — and that is the only place it can work.
        //
        // The FTP inbox is mounted on the Dashboard, the photographer lands
        // exactly there after the login, and the card reads the status once per
        // mount: `useFtp` switches SWR's own mount revalidation off and reads
        // the status for the mount it belongs to. The status request of this
        // test therefore happens *during* the login. A mock registered
        // afterwards would never answer it, and the click on "Dashboard" would
        // not help either: the photographer is already on that route, so the
        // card is not remounted and no second read is produced.
        await page.route('**/api/management/ftp/status', async route => {
            const response = await route.fetch();
            if (!response.ok()) {
                throw new Error(`The FTP status request failed with status ${response.status()}.`);
            }
            const responseBody: unknown = await response.json();
            if (!isRecord(responseBody)) {
                throw new Error('FTP status response is not an object.');
            }

            const status = responseBody;
            if (typeof status.ftp_folder !== 'string') {
                throw new Error('FTP status response does not contain an upload folder.');
            }
            if (!isRecord(status.connection)) {
                throw new Error('FTP status response does not contain connection details.');
            }
            // The card builds the quarantine path from the upload folder and
            // the connection path: `<ftp_folder><connection.path>/_quarantine`.
            quarantinePath = `${status.ftp_folder}/incoming/_quarantine`;
            await route.fulfill({
                response,
                body: JSON.stringify({
                    ...status,
                    connection: { ...status.connection, path: '/incoming' },
                    quarantine_count: 2,
                }),
            });
        });

        const statusRead = page.waitForResponse(isFtpStatusRead);
        await auth.login(testUser.email, testUser.password);
        const statusResponse = await statusRead;
        expect(statusResponse.ok()).toBe(true);

        // The photographer is already on the Dashboard after the login, so this
        // click only confirms the view. It does not remount the card, so the
        // read the mock answered above is the one this test asserts on.
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const alert = main.getByRole('alert');
        await expect(alert).toBeVisible();
        await expect(alert).toContainText('2 Dateien liegen im Quarantäne-Ordner');
        await expect(alert.getByText(quarantinePath, { exact: true })).toBeVisible();
        await expect(alert).toContainText('Sie bleiben erhalten und werden nicht gelöscht.');
    });

    test('FTP Inbox is NOT visible for non-photographer roles', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('client');
        const auth = new AuthHelper(page);

        await auth.login(testUser.email, testUser.password);
        await expect(page.locator('main').first()).toBeVisible({ timeout: 10000 });

        await expect(page.locator('h2:has-text("FTP Inbox")')).toHaveCount(0);
    });

    test('Photographer can set and remove target gallery in FTP Inbox', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `FTP Target ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.reload();
        await sidebar.navigateTo('Dashboard');

        // The card is addressed by what it renders, not by a CSS class: the
        // heading names it, the queue counter belongs to it, and the target
        // controls are the only combobox and the only "Setzen" button on the
        // Dashboard. (The structure view with its own select lives on another
        // route, so the combobox below is unambiguous.)
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'FTP Inbox' })).toBeVisible();
        await expect(main.getByTestId('ftp-queue-count')).toBeVisible();

        const targetSelect = main.getByRole('combobox');
        await expect(targetSelect).toBeVisible();

        const galleryOption = targetSelect.getByRole('option', { name: new RegExp(galleryName) });
        await expect(galleryOption).toHaveCount(1);
        const optionValue = await galleryOption.getAttribute('value');
        if (optionValue === null) throw new Error('The FTP target gallery option has no value.');
        await targetSelect.selectOption(optionValue);

        const setzenBtn = main.getByRole('button', { name: 'Setzen' });
        await expect(setzenBtn).toBeEnabled();
        await setzenBtn.click();

        // The target is set: the select is replaced by the target box, and the
        // box offers the button that clears the assignment. The button carries
        // its name in the `title` attribute, which is where its accessible name
        // comes from — the icon inside it has no text.
        const clearTargetBtn = main.getByRole('button', { name: 'Zuordnung aufheben' });
        await expect(clearTargetBtn).toBeVisible({ timeout: 5000 });
        await expect(main.getByRole('combobox')).toHaveCount(0);

        // The gallery name is read from that box and not from the whole
        // Dashboard: the personal feed below the card lists the same gallery
        // as a card of its own, and an unscoped name would match both.
        const targetBox = clearTargetBtn.locator('xpath=..');
        await expect(targetBox.getByText(galleryName, { exact: true })).toBeVisible({ timeout: 5000 });

        await clearTargetBtn.click();

        await expect(main.getByRole('combobox')).toBeVisible({ timeout: 5000 });
    });

    test('FTP Inbox imports one bounded batch manually and catches up once on the next mount', { tag: ['@feature:admin:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const galleryName = `FTP Batch ${Math.random().toString(36).substring(2, 10)}`;
        let simulatedQueueCount = 0;
        /**
         * Every import request this test has seen, in order, tagged with the
         * phase it was made in. The list is the proof for the "no automatic
         * import" contract: an unwanted catch-up run (D-36) is a request that
         * is not in the expected list, and the phase says which one it was.
         */
        const importPhases: ImportPhase[] = [];
        let importPhase: ImportPhase = 'before-manual';

        // Both mocks are registered BEFORE the first page load, so the very
        // first mount of the card is covered as well. That matters: an import
        // the card was not supposed to start would otherwise reach the real
        // backend, and a counter that only sees the mocked requests would
        // stay at zero — the check would be vacuous.
        await page.route('**/api/management/ftp/status', async route => {
            const response = await route.fetch();
            if (!response.ok()) {
                throw new Error(`The FTP status request failed with status ${response.status()}.`);
            }
            const responseBody: unknown = await response.json();
            if (!isRecord(responseBody)) {
                throw new Error('FTP status response is not an object.');
            }

            await route.fulfill({
                response,
                body: JSON.stringify({ ...responseBody, file_count: simulatedQueueCount }),
            });
        });

        await page.route('**/api/management/ftp/process', async route => {
            importPhases.push(importPhase);
            // The first import of the test takes a batch of two files, every
            // further one a single file — the bounded batch of D-35. The
            // manual import is the first one, so the queue goes 3 -> 1; the
            // catch-up run of the next mount takes the last file, 1 -> 0.
            const batchLimit = importPhases.length === 1 ? 2 : 1;
            const processed = Math.min(simulatedQueueCount, batchLimit);
            simulatedQueueCount -= processed;

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, processed }),
            });
        });

        const firstStatusRead = page.waitForResponse(isFtpStatusRead);
        await auth.login(testUser.email, testUser.password);
        const firstStatusResponse = await firstStatusRead;
        expect(firstStatusResponse.ok()).toBe(true);

        const main = page.getByRole('main');
        const queueCount = main.getByTestId('ftp-queue-count');

        // The first mount of the card: an empty inbox and no target gallery.
        // The catch-up run must stay silent (D-36) — an empty inbox has
        // nothing to catch up on. This mount cannot tell the two reasons
        // apart; the mount under test below does, with a non-empty inbox and
        // still no target gallery.
        //
        // The decision of this mount is made right after the read that the
        // queue count comes from — the effect runs on that render — and the
        // gallery is created afterwards, which takes several user
        // interactions. The window below is what keeps the test open long
        // enough for a request to actually start.
        await expect(queueCount).toHaveText('0');
        expect(await noImportRequestWithin(page)).toBe(true);
        expect(importPhases).toEqual([]);

        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const galleryResponse = await modal.submitModal('Speichern');
        const galleryId = galleryResponse?.gallery?.id;
        if (!galleryId) throw new Error('The FTP target gallery was not created.');
        helper.trackGallery(galleryId);

        // From here on the inbox is not empty: the queue belongs to the mock,
        // so every following status read reports three files. The mount under
        // test therefore sees a NON-EMPTY inbox while the target gallery is
        // still unset — the combination D-36 forbids an automatic import for.
        // (The card is not mounted on the route of the gallery list, so no
        // status read happens between here and the mount below.)
        simulatedQueueCount = 3;

        // A fresh document, so the gallery tree is rebuilt from the server and
        // the card mounts with a read of its own.
        await page.reload();

        const mountStatusRead = page.waitForResponse(isFtpStatusRead);
        await sidebar.navigateTo('Dashboard');
        const mountStatusResponse = await mountStatusRead;
        expect(mountStatusResponse.ok()).toBe(true);

        await expect(main.getByRole('heading', { name: 'FTP Inbox' })).toBeVisible();
        await expect(queueCount).toHaveText('3');

        const targetSelect = main.getByRole('combobox');
        await expect(targetSelect).toBeVisible();
        await expect(targetSelect).toHaveValue('');

        // The mount under test: a non-empty inbox and still no target gallery.
        //
        // The order is what makes the check meaningful. This mount reads the
        // status when the card mounts; the decision effect runs after that
        // read has settled, which is the very render the queue count above
        // comes from. The observation window then keeps the test open long
        // enough for an effect that decides to import to actually start its
        // request — the snapshot of the phase list alone would miss a request
        // that is still in flight.
        expect(await noImportRequestWithin(page)).toBe(true);
        // The phase list is the second half of the proof: it also holds a
        // request that was already on its way when the window opened.
        expect(importPhases).toEqual([]);

        const galleryOption = main.getByRole('option', { name: new RegExp(galleryName) });
        await expect(galleryOption).toHaveCount(1);
        const optionValue = await galleryOption.getAttribute('value');
        if (optionValue === null) throw new Error('The FTP target gallery option has no value.');
        await targetSelect.selectOption(optionValue);

        const targetStatusRead = page.waitForResponse(isFtpStatusRead);
        await main.getByRole('button', { name: 'Setzen' }).click();
        const targetStatusResponse = await targetStatusRead;
        expect(targetStatusResponse.ok()).toBe(true);

        // The target is set — and the card says so without naming the
        // gallery: the select is replaced by the target box, and the import
        // button is enabled, which needs a target gallery AND a non-empty
        // inbox. (The gallery name itself is asserted in the target-selection
        // test above, scoped to the box that shows it.)
        await expect(main.getByRole('combobox')).toHaveCount(0);
        const importButton = main.getByRole('button', { name: 'Bilder Importieren' });
        await expect(importButton).toBeEnabled();
        await expect(queueCount).toHaveText('3');

        // Setting the target on THIS mount must not start the catch-up run
        // either: the one-shot decision of this mount was already spent on the
        // read above, which had no target gallery. Only a later mount may
        // decide again.
        expect(await noImportRequestWithin(page)).toBe(true);
        expect(importPhases).toEqual([]);

        importPhase = 'manual';
        const importStatusRead = page.waitForResponse(isFtpStatusRead);
        await importButton.click();
        const importStatusResponse = await importStatusRead;
        expect(importStatusResponse.ok()).toBe(true);

        // The manual import took the first bounded batch: two of the three
        // files, one is left.
        await expect(queueCount).toHaveText('1');
        // The manual import was the FIRST import request of this test, so no
        // catch-up run consumed the one-shot decision of this mount.
        expect(importPhases).toEqual(['manual']);

        importPhase = 'after-manual';

        await sidebar.navigateTo('Galerien & Ordner');
        const catchUpStatusRead = page.waitForResponse(isFtpStatusRead);
        await sidebar.navigateTo('Dashboard');
        const catchUpStatusResponse = await catchUpStatusRead;
        expect(catchUpStatusResponse.ok()).toBe(true);

        // The next mount reads the status again and finds one file with a
        // target gallery set: the catch-up run of D-36 starts exactly one
        // import, which takes the last file.
        //
        // The poll carries an explicit timeout because the mount has to read
        // the status, decide, import and reload the status again before the
        // import list reaches two entries.
        await expect.poll(() => importPhases.length, { timeout: 15000 }).toBe(2);
        await expect(queueCount).toHaveText('0');
        expect(importPhases).toEqual(['manual', 'after-manual']);
    });
});
