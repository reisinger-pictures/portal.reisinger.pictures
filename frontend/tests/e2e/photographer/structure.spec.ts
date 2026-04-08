import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';

test.describe('Management Structure View (Tree)', () => {
    let helper: E2ESessionHelper;
    let photogUser: { email: string; password: string; id: string };

    test.beforeEach(async ({ page, request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        await auth.login(photogUser.email, photogUser.password);
    });

    test.afterEach(async () => {
        await helper.teardown();
    });

    test('Photographer can create nested groups and toggle tree nodes', async ({ page }) => {
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const groupName = `Tree Group ${Math.random()}`;

        await sidebar.navigateTo('Galerien');
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        const form = new FormHelper(page, modal);
        await form.fillGroupModal({ name: groupName });

        const responsePromise = page.waitForResponse(r => r.url().includes('/gallery-groups') && r.request().method() === 'POST');
        await modal.clickButton('Speichern');
        const res = await responsePromise;
        const data = await res.json();
        helper.trackGroup(data.group.id);

        await expect(page.locator('summary').filter({ hasText: groupName }).first()).toBeVisible();
    });
});
