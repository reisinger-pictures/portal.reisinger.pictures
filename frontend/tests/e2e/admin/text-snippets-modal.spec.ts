import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

test.describe('Text snippet modal', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        // The "Textbausteine" navigation entry is super-admin gated (Sidebar).
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('opens the new-snippet dialog from the Textbausteine view', { tag: ['@feature:admin:documents'] }, async ({ page }) => {
        await new AuthHelper(page).login(testUser.email, testUser.password);
        await new SidebarHelper(page).navigateTo('Textbausteine');

        const createButton = page.getByRole('button', { name: 'Neuer Baustein' });
        await expect(createButton).toBeVisible({ timeout: 15000 });
        await createButton.click();

        const dialog = page.getByRole('dialog', { name: 'Neuen Textbaustein anlegen' });
        await expect(dialog).toBeVisible({ timeout: 5000 });
        // The three form sections of the create dialog. Their labels are plain
        // text (the inputs are named by the dialog's own markup, not by an
        // associated `for`), so they are asserted as user-facing text.
        await expect(dialog.getByText('Titel (Intern)')).toBeVisible();
        await expect(dialog.getByText('Kürzel (Shortcut)')).toBeVisible();
        await expect(dialog.getByText('Inhalt (HTML)')).toBeVisible();
        // The first toolbar control of the embedded editor proves the WYSIWYG
        // body actually mounted inside the dialog.
        await expect(dialog.getByRole('button', { name: 'Fett' })).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Speichern' })).toBeVisible();
    });
});
