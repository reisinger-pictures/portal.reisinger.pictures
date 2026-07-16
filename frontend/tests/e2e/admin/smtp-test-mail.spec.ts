import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import { ToastHelper } from '../helpers/ToastHelper';

test.describe('Super Admin SMTP Test Mail', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let mailpit: MailpitHelper;

    test.beforeEach(async ({ page, request }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        mailpit = new MailpitHelper(request);
        await mailpit.deleteAllMessages();
    });

    test('Super Admin can send a test email and receive it', { tag: ['@smoke', '@feature:admin'] }, async ({ page }) => {
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const testMailButton = page.getByTestId('send-test-email');
        await expect(testMailButton).toBeVisible();
        await testMailButton.click();

        await new ToastHelper(page).expectToast(/Test-E-Mail gesendet/);

        const message = await mailpit.getMessageForEmail(testUser.email);
        expect(message, 'Test-E-Mail should arrive in Mailpit').not.toBeNull();
        expect(message.Subject).toContain('SMTP Test');
    });

    test('Non-super-admin does not see the test mail button', { tag: ['@feature:admin'] }, async ({ page, request }) => {
        const adminHelper = new E2ESessionHelper(request);
        const adminUser = await adminHelper.createIsolatedUser('admin');
        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        await expect(page.getByTestId('send-test-email')).toHaveCount(0);
        await adminHelper.teardown();
    });
});
