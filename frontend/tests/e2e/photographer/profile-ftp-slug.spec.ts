import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

/**
 * P1-M21: `ftp_slug` is the FTP/SFTP account name, so the profile form has to
 * name the format and refuse a value the account system cannot carry — before it
 * reaches the API. The API-side contract is covered by
 * backend/tests/Feature/FtpSlugValidationTest.php; this spec proves the hint the
 * photographer actually sees, and that a compliant value still lands.
 *
 * @feature:ftp
 */
test.describe('FTP login format on the profile form', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('rejects an ftp login the account name format cannot carry', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Mein Profil');

        const main = page.getByRole('main');
        const ftpSlugInput = main.getByRole('textbox', { name: /^FTP Upload Ordner \(Slug\)/ });
        await expect(ftpSlugInput).toBeVisible();

        // A dot used to be swallowed by Str::slug() on the server, which turned
        // the typed name into a different account name without saying so.
        await ftpSlugInput.fill('a.b');
        await main.getByRole('button', { name: 'Profil speichern' }).click();

        await expect(
            main.getByText('Nur Kleinbuchstaben, Ziffern, - und _, 3 bis 32 Zeichen, Start mit Buchstabe oder Ziffer.'),
        ).toBeVisible();
        await expect(ftpSlugInput).toHaveAttribute('aria-invalid', 'true');
        await expect(ftpSlugInput).toHaveValue('a.b');
    });

    test('stores an ftp login that satisfies the format rule', { tag: ['@feature:ftp'] }, async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        const uniqueSuffix = Math.random().toString(36).substring(2, 8);
        const newSlug = `ftp-${uniqueSuffix}`;

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Mein Profil');

        const main = page.getByRole('main');
        const ftpSlugInput = main.getByRole('textbox', { name: /^FTP Upload Ordner \(Slug\)/ });
        await ftpSlugInput.fill(newSlug);
        await main.getByRole('button', { name: 'Profil speichern' }).click();

        await expect(page.locator('.toast')).toContainText('Profil aktualisiert');

        // A slug change is a password reset: the backend deletes the old SFTPGo
        // account, provisions a new one and returns the fresh password exactly
        // once. Asserting it here is what makes this spec cover the *working*
        // account, not just the saved slug — dropping the password in the form
        // was a production bug (a working account nobody could log into). The
        // E2E stack provides a real SFTPGo for this path; without it the request
        // fails closed and this assertion cannot pass.
        await expect(main.getByText('Neues Kamera-Passwort')).toBeVisible();
        await expect(main.getByText('Dieses Passwort wird nur einmal angezeigt. Speichere es sofort.')).toBeVisible();

        // Read the value back from a different view instead of re-asserting the
        // field: the hydration effect re-populates the input, so a DOM-only
        // assertion would pass even if nothing had been persisted.
        await sidebar.navigateTo('Dashboard');
        await expect(page.locator('code:has-text("/' + newSlug + '")')).toBeVisible({ timeout: 15000 });
    });
});
