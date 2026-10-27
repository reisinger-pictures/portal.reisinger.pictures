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
 * The account state decides what the one button on the Kamera-Konto card does
 * (D-51): a `pending` account has no password that could be confirmed, so the
 * click provisions it directly; an `active` account opens the confirmation
 * dialog, and the reset is the recovery path inside it. Both branches are
 * covered below, and the real path — a confirmation checked against the real
 * SFTPGo — is covered by `ftp-password-change.spec.ts`.
 *
 * No `page.goto` for navigation: the guide is reached by clicking the button in the
 * inbox card, which is also the user path that button exists for.
 *
 * The connection values live behind a second button on the same card, and they
 * are asserted through the same stub: the E2E stack's own environment declares
 * host and the two ports but neither the passive range nor the TLS mode, so the
 * stub is the only place where a *fully* declared connection exists. That is
 * also where a hardcoded port in the component would be caught — a dialog that
 * quotes values the server never sent fails here.
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
    quarantine_count: 0,
    current_target_gallery: null,
    ftp_account_status: 'pending',
    ftp_provisioned_at: null,
    ftp_account_error: null,
    // The guide quotes the reset quota from this field, so a stub without it
    // would render the sentence with a hole in it — the same drift the prop
    // replaced, only inside the fixture. Deliberately not the production value:
    // the guide is expected to show what the response says, whatever that is.
    ftp_reset_limit_per_hour: 7,
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
        await expect(cameraSection.getByTestId('ftp-connection-button')).toBeVisible();

        // The connection values are no longer inline on the card: they live
        // behind the button, so nothing on the landing page could quote a port
        // the server stopped listening on.
        await expect(cameraSection.locator('table')).toHaveCount(0);
        await expect(cameraSection.getByText('sftp.example.invalid')).toHaveCount(0);

        // The dialog is the same data source; if it shows the stub, the response
        // is really being used.
        await cameraSection.getByTestId('ftp-connection-button').click();
        const connectionDialog = page.getByRole('dialog', { name: 'Kamera-Verbindung' });
        await expect(connectionDialog).toBeVisible();
        await expect(connectionDialog.locator('td', { hasText: 'sftp.example.invalid' })).toBeVisible();
        await connectionDialog.getByRole('button', { name: 'Schließen' }).click();
        await expect(connectionDialog).toHaveCount(0);

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

    test('downloads the optional ROOT.PEM certificate from the camera setup guide', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await stubStatus(page);
        await page.route('**/api/management/ftp/certificate', route => route.fulfill({
            status: 200,
            contentType: 'application/x-pem-file',
            headers: {'Content-Disposition': 'attachment; filename="ROOT.PEM"'},
            body: '-----BEGIN CERTIFICATE-----\npublic certificate\n-----END CERTIFICATE-----\n',
        }));
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const cameraSection = main.locator('.card').filter({ hasText: 'Kamera-Verbindung' });
        await cameraSection.getByRole('button', { name: 'Anleitung öffnen' }).click();

        const dialog = main.getByRole('dialog', { name: 'Kamera einrichten' });
        await expect(dialog.getByText('Die Kamera akzeptiert das Zertifikat auch ohne Import über „Zielserver vertrauen“.')).toBeVisible();
        const downloadPromise = page.waitForEvent('download');
        await dialog.getByRole('button', { name: 'ROOT.PEM herunterladen' }).click();
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toBe('ROOT.PEM');
    });

    test('opens every connection row in the dialog and closes it with Escape', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await stubStatus(page);
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const cameraSection = main.locator('.card').filter({ hasText: 'Kamera-Verbindung' });

        // Nothing of the connection is inline before the click — that is the
        // whole point of the move.
        await expect(cameraSection.locator('table')).toHaveCount(0);

        await cameraSection.getByTestId('ftp-connection-button').click();

        const dialog = page.getByRole('dialog', { name: 'Kamera-Verbindung' });
        await expect(dialog).toBeVisible();

        // Every declared row, scoped by its label. The row-scoped assertions
        // are what keep a hardcoded copy of these values in the component red.
        const row = (label: string) => dialog.locator('tr').filter({ hasText: label });
        for (const label of [
            'Server',
            'Benutzername',
            'SFTP-Port',
            'FTPS-Port',
            'FTPS-Verschlüsselung',
            'Passiver Bereich (Firewall)',
            'Zielordner',
        ]) {
            await expect(row(label)).toHaveCount(1);
        }
        await expect(row('Server').locator('td').nth(1)).toHaveText('sftp.example.invalid');
        await expect(row('Benutzername').locator('td').nth(1)).toHaveText('e2e-camera');
        await expect(row('SFTP-Port').locator('td').nth(1)).toHaveText('2222');
        await expect(row('FTPS-Port').locator('td').nth(1)).toHaveText('989');
        await expect(row('FTPS-Verschlüsselung').locator('td').nth(1)).toHaveText('explicit');
        await expect(row('Passiver Bereich (Firewall)').locator('td').nth(1)).toHaveText('50000–50100');
        await expect(row('Zielordner').locator('td').nth(1)).toHaveText('/');

        // Escape is the shell's contract (ModalShell), not this dialog's special
        // case, and closing returns focus to the button that opened it.
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(cameraSection.getByTestId('ftp-connection-button')).toBeFocused();
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

        // The notice moved into the dialog together with the table — an empty
        // port cell is what a photographer pastes into a camera, so the
        // unconfigured state must not render a half-filled table either.
        await cameraSection.getByTestId('ftp-connection-button').click();
        const dialog = page.getByRole('dialog', { name: 'Kamera-Verbindung' });
        await expect(dialog.getByText('Verbindungsdaten der Kamera nicht verfügbar')).toBeVisible();
        await expect(dialog.locator('table')).toHaveCount(0);
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

        // A pending account has no password that could be confirmed, so the
        // click provisions it directly — no confirmation dialog in between.
        await expect(main.getByRole('dialog', { name: 'Kamera-Passwort ändern' })).toHaveCount(0);

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
        // An active account: the button opens the confirmation dialog, and the
        // reset is the recovery path inside it (D-51). The quota covers this
        // path exactly like the one of a pending account.
        await stubStatus(page, {ftp_account_status: 'active'});

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        await main.getByTestId('ftp-credentials-button').click();

        const dialog = main.getByRole('dialog', { name: 'Kamera-Passwort ändern' });
        await expect(dialog).toBeVisible();
        await dialog.getByTestId('ftp-change-recovery').click();

        // The toast container is global (`toast-global`) and sits outside `main`,
        // so it is addressed from the page — the same as every other toast
        // assertion in this suite.
        await expect(page.locator('.toast')).toContainText('Zu viele Passwort-Änderungen');
        // The hourly reset quota is the backend rule. A client-side retry or a
        // re-enabled button would spend the remaining quota on guesses, so exactly
        // one request may reach the endpoint.
        await expect.poll(() => resetCalls).toBe(1);
        // Nothing was provisioned, so no password may be offered anywhere. The
        // dismiss button only exists together with a shown password.
        await expect(main.getByRole('button', { name: 'Verstanden, ausblenden' })).toHaveCount(0);
    });
});
