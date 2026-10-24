import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import {browserAuthHeaders} from '../helpers/BrowserAuthHeaders';

/**
 * The photographer changes their own camera password (D-51).
 *
 * The portal stores no camera password — it is generated, shown once and
 * discarded — so the current one can only be learned from the reset endpoint
 * before the page ever shows it. That is why this spec calls the reset once
 * through the API and keeps the value: it is the fixture for the confirmation
 * field, and there is no other way to obtain it. The reset happens **before**
 * the browser logs in, so the status the page reads is already `active` and the
 * account button opens the confirmation dialog instead of provisioning.
 *
 * The endpoints are NOT stubbed here, unlike in `kamera-einrichtung.spec.ts`:
 * the point of this spec is the real path — the confirmation really is checked
 * against the E2E stack's SFTPGo, and a wrong password really is refused. A
 * stubbed version would prove nothing about the contract this feature rests on.
 *
 * Locators are scoped to the `main` landmark; the toast container is global
 * (`toast-global`) and therefore addressed from the page, as in every other
 * toast assertion in this suite.
 *
 * @feature:ftp
 */

const CAMERA_PASSWORD_ERROR = 'Das aktuelle Kamera-Passwort ist nicht korrekt.';

test.describe('Kamera-Passwort ändern', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('confirms the change with the current password and refuses a wrong one', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        // The one way to learn the current camera password: the reset hands it
        // out exactly once, and this spec has to own that value before the
        // page ever sees it.
        const cookie = await helper.loginAs(testUser.email, testUser.password);
        const resetResponse = await request.post('/api/management/ftp/reset-password', {
            headers: { 'Accept': 'application/json', Cookie: cookie, ...browserAuthHeaders(cookie)},
        });
        // The body is read once and kept: `text()` consumes it, so a second
        // read for the failure message would make the `json()` below fail for
        // a reason that has nothing to do with the password.
        const resetBodyText = await resetResponse.text();
        expect(
            resetResponse.ok(),
            `Der Zugangsdatum-Reset ist fehlgeschlagen: ${resetResponse.status()} ${resetBodyText}`,
        ).toBe(true);
        const resetBody = JSON.parse(resetBodyText) as { password?: string };
        const currentPassword = resetBody.password ?? '';
        expect(currentPassword.length).toBeGreaterThan(0);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Dashboard');

        const main = page.getByRole('main');
        const accountCard = main.locator('.card').filter({ hasText: 'FTP Inbox' });
        // The dialog is a descendant of the landmark, so it is addressed
        // through it (D-10) rather than from the page.
        const dialog = main.getByRole('dialog', { name: 'Kamera-Passwort ändern' });

        // The account exists now, so the button confirms instead of
        // provisioning. Without this the click would take the other branch and
        // the rest of the spec would test the wrong thing.
        await expect(accountCard.getByText('Konto aktiv')).toBeVisible();

        await accountCard.getByTestId('ftp-credentials-button').click();

        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Aktuelles Kamera-Passwort').fill(currentPassword);
        await dialog.getByTestId('ftp-change-submit').click();

        // The new password is shown once, on the card — and it is a new one.
        const showOnce = main.locator('.alert').filter({ hasText: 'Neues Kamera-Passwort' });
        await expect(showOnce).toBeVisible();
        const newPassword = (await showOnce.locator('code').innerText()).trim();
        expect(newPassword.length).toBeGreaterThan(0);
        expect(newPassword).not.toBe(currentPassword);
        // The dialog is closed: the password belongs on the card.
        await expect(dialog).toHaveCount(0);

        // The show-once contract: the value leaves the screen on request.
        await main.getByRole('button', { name: 'Verstanden, ausblenden' }).click();
        await expect(showOnce).toHaveCount(0);

        // A wrong current password is refused with the server's own words, and
        // the dialog stays open so the input can be corrected.
        await accountCard.getByTestId('ftp-credentials-button').click();
        await expect(dialog).toBeVisible();
        await dialog.getByLabel('Aktuelles Kamera-Passwort').fill('falscheskamerapasswort');
        await dialog.getByTestId('ftp-change-submit').click();

        await expect(page.locator('.toast')).toContainText(CAMERA_PASSWORD_ERROR);
        await expect(dialog).toBeVisible();
        // Nothing was rotated, so no password may be offered.
        await expect(main.getByRole('heading', { name: 'Neues Kamera-Passwort' })).toHaveCount(0);
    });
});
