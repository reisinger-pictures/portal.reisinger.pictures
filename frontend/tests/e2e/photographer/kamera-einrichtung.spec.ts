import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

/**
 * Camera setup: the guide dialog and the credential reset (P1-M33, P1-M32).
 *
 * Both responses are stubbed with `page.route` on purpose. The guide renders host,
 * ports and the TLS mode from `GET /api/management/ftp/status`, so a test that
 * asserted the real deployment would only ever verify whatever the CI environment
 * happens to be configured with — and would be green while the dialog showed a port
 * nothing listens on. The same is true of the reset: it mints a fresh random
 * password, so the value and the server's notice are stubbed here to keep the
 * assertions deterministic. The real provisioning path, including the one-time
 * password, is covered by profile-ftp-slug.spec.ts against the E2E stack's SFTPGo.
 *
 * The stub therefore serves a deliberately distinctive payload (`.invalid` host,
 * non-default ports) and the assertions check that those exact values reach the
 * DOM. A hardcoded copy of the same values in the dialog would fail this test,
 * which is the point.
 *
 * No `page.goto` for navigation: the guide is reached by clicking the button in the
 * inbox card, which is also the user path that button exists for.
 *
 * @feature:ftp
 */

const CONNECTION = {
    configured: true,
    host: 'sftp.example.invalid',
    username: 'e2e-camera',
    path: '/',
    sftp_port: 2222,
    ftps_port: 989,
    pasv_port_start: 50000,
    pasv_port_end: 50100,
    ftps_tls_mode: 'explicit',
};

const statusPayload = (overrides: Record<string, unknown> = {}) => ({
    ftp_folder: '/e2e-camera',
    file_count: 0,
    current_target_gallery: null,
    ftp_account_status: 'pending',
    ftp_provisioned_at: null,
    ftp_account_error: null,
    connection: CONNECTION,
    ...overrides,
});

/** Serve one status payload for every status refetch in this test. */
const stubStatus = (page: import('@playwright/test').Page, overrides: Record<string, unknown> = {}) => {
    const payload = statusPayload(overrides);
    return page.route('**/api/management/ftp/status', route =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(payload),
        })
    );
};

test.describe('Kamera einrichten: Anleitung und Zugangsdaten', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('renders the camera values from the status response in the guide dialog', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await stubStatus(page);
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const cameraSection = main.locator('.card').filter({ hasText: 'Kamera-Verbindung' });
        await expect(cameraSection.getByRole('button', { name: 'Anleitung öffnen' })).toBeVisible();

        // The inbox card is the same data source; if it shows the stub, the
        // response is really being used.
        await expect(cameraSection.locator('td', { hasText: 'sftp.example.invalid' })).toBeVisible();

        await cameraSection.getByRole('button', { name: 'Anleitung öffnen' }).click();

        // The guide is a dialog in the same page, not a route. Its accessible name
        // comes from ModalShell's title heading.
        const dialog = page.getByRole('dialog', { name: 'Kamera einrichten' });
        await expect(dialog).toBeVisible();

        // Every stubbed value has to appear in the guide, in both places it
        // mentions it: the value table and the menu table.
        await expect(dialog.getByText('sftp.example.invalid').first()).toBeVisible();
        await expect(dialog.getByText('2222').first()).toBeVisible();
        await expect(dialog.getByText('989').first()).toBeVisible();
        await expect(dialog.getByText('50000–50100').first()).toBeVisible();
        await expect(dialog.getByText('e2e-camera').first()).toBeVisible();

        // The TLS mode is the value that must never be asserted instead of read.
        // Row-scoped on purpose: a bare `hasText: 'explicit'` would also match the
        // error table's "explizites", which contains it as a substring.
        const tlsRow = dialog.locator('tr').filter({ hasText: 'FTPS-Verschlüsselung' });
        await expect(tlsRow.locator('td').nth(1)).toHaveText('explicit');
        await expect(dialog.getByText('explizites TLS konfiguriert (AUTH TLS)').first()).toBeVisible();

        // Closing the dialog returns to the inbox and removes it from the DOM.
        await dialog.getByRole('button', { name: 'Schließen' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('warns instead of showing a value table when the server is not configured', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await page.route('**/api/management/ftp/status', route => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(statusPayload({
                connection: {...CONNECTION, configured: false, host: null, sftp_port: null, ftps_port: null, ftps_tls_mode: null},
            })),
        }));

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const cameraSection = page.getByRole('main').locator('.card').filter({ hasText: 'Kamera-Verbindung' });
        await expect(cameraSection.getByText('Verbindungsdaten der Kamera nicht verfügbar')).toBeVisible();
        // No half-filled table: an empty port cell is what a photographer pastes
        // into a camera.
        await expect(cameraSection.locator('table')).toHaveCount(0);
    });

    test('requests credentials, shows the password once, and can dismiss it', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const password = 'Kamera-P4sswort-4711';
        const passwordNotice = 'Dieses Passwort wird genau einmal angezeigt. Es ist nicht gespeichert.';

        // The account is provisioned *by* the reset, so the status has to change
        // across it. Stubbing `active` from the start would make the pending
        // warning below unreachable and the refetch assertion vacuous.
        let provisioned = false;
        await page.route('**/api/management/ftp/reset-password', route => {
            provisioned = true;
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({success: true, password, password_notice: passwordNotice}),
            });
        });
        await page.route('**/api/management/ftp/status', route => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(statusPayload({ftp_account_status: provisioned ? 'active' : 'pending'})),
        }));

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const accountCard = main.locator('.card').filter({ hasText: 'FTP Inbox' });

        // Before the request, the pending account is called out — a pending account
        // fails every camera login regardless of the password.
        await expect(accountCard.getByText('Kamera-Konto noch nicht angelegt')).toBeVisible();

        await accountCard.getByTestId('ftp-credentials-button').click();

        await expect(main.getByText(password)).toBeVisible();
        await expect(main.getByText(passwordNotice)).toBeVisible();
        // The server's own wording, not an invented one.
        await expect(main.getByRole('heading', { name: 'Neues Kamera-Passwort' })).toBeVisible();
        // The refetch is what turns the pending warning into "Konto aktiv".
        await expect(accountCard.getByText('Konto aktiv')).toBeVisible();

        await main.getByRole('button', { name: 'Verstanden, ausblenden' }).click();
        await expect(main.getByText(password)).toHaveCount(0);
    });

    test('reports a rate limit with the server message and does not retry', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        let resetCalls = 0;
        await page.route('**/api/management/ftp/reset-password', route => {
            resetCalls += 1;
            return route.fulfill({
                status: 429,
                contentType: 'application/json',
                headers: {'Retry-After': '3600'},
                body: JSON.stringify({error: 'Zu viele Passwort-Änderungen. Bitte später erneut versuchen.'}),
            });
        });
        await stubStatus(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        await main.getByTestId('ftp-credentials-button').click();

        // The toast container is global (`toast-global`) and sits outside `main`,
        // so it is addressed from the page — the same as every other toast
        // assertion in this suite.
        await expect(page.locator('.toast')).toContainText('Zu viele Passwort-Änderungen');
        // Three resets per hour is the backend quota. A client-side retry or a
        // re-enabled button would spend the remaining quota on guesses, so exactly
        // one request may reach the endpoint.
        await expect.poll(() => resetCalls).toBe(1);
        // Nothing was provisioned, so no password may be offered anywhere. The
        // dismiss button only exists together with a shown password.
        await expect(main.getByRole('button', { name: 'Verstanden, ausblenden' })).toHaveCount(0);
    });
});
