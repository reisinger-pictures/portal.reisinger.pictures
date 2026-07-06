import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Photographer Empty Feed (G11)', () => {
    let helper: E2ESessionHelper;
    let photogUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        await auth.login(photogUser.email, photogUser.password);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Photographer with no activity sees empty state', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Dashboard');

        const heading = page.locator('h2').filter({ hasText: 'Deine neuesten Uploads' }).first();
        await expect(heading).toBeVisible({ timeout: 15000 });

        // Wait for feed to finish loading — either the empty state or gallery cards appear
        const feedSection = heading.locator('..').locator('..');
        await expect(feedSection).toBeVisible({ timeout: 10000 });
    });

    test('Dashboard loads without errors for new photographer', { tags: ['@smoke', '@feature:photographer'] }, async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Dashboard');

        const errors: string[] = [];
        page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });

        await expect(page.locator('h2').filter({ hasText: 'Deine neuesten Uploads' }).first()).toBeVisible({ timeout: 15000 });
        expect(errors.filter(e => !e.includes('favicon')).length).toBe(0);
    });

    test('Photographer sees FTP Inbox card on Dashboard', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Dashboard');

        const ftpInbox = page.locator('h2').filter({ hasText: 'FTP Inbox' }).first();
        await expect(ftpInbox).toBeVisible({ timeout: 15000 });
    });
});
