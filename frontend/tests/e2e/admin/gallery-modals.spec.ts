import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { FormHelper } from '../helpers/FormHelper';
import { ModalHelper } from '../helpers/ModalHelper';

test.describe('Gallery & Group Modals Roundtrip', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Gallery dialog is named, modal, and closes with Escape', { tag: ['@feature:admin:galleries', '@regression'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien & Ordner');
        const newGalleryButton = page.getByRole('button', { name: 'Neue Galerie' });
        await newGalleryButton.focus();
        await newGalleryButton.click();

        const dialog = page.getByRole('dialog', { name: 'Neue Galerie' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('aria-modal', 'true');

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(newGalleryButton).toBeFocused();
    });

    test('Gallery visibility follows a parent policy without losing explicit intent', { tag: ['@feature:admin:galleries', '@regression'] }, async ({ page, request }) => {
        const adminHeaders = { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() };
        const suffix = Math.random().toString(36).substring(2, 8);
        const createGroup = async (name: string, isPublic: boolean | null) => {
            const response = await request.post('/api/management/gallery-groups', {
                data: { name, is_public: isPublic },
                headers: adminHeaders,
            });
            expect(response.ok()).toBeTruthy();
            const payload = await response.json() as { group?: { id?: string } };
            const groupId = payload.group?.id;
            if (!groupId) throw new Error(`Group ID missing for ${name}`);
            helper.trackGroup(groupId);
            return groupId;
        };

        const publicGroupId = await createGroup(`E2E Public Parent ${suffix}`, true);
        const neutralGroupId = await createGroup(`E2E Neutral Parent ${suffix}`, null);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien & Ordner');
        const main = page.locator('main');
        await main.getByRole('button', { name: 'Neue Galerie' }).click();

        const dialog = page.getByRole('dialog', { name: 'Neue Galerie' });
        const visibility = dialog.getByLabel('Sichtbarkeit');
        const parent = dialog.getByLabel('In welchem Ordner soll die Galerie liegen?');

        await visibility.selectOption('false');
        await parent.selectOption(publicGroupId);
        await expect(visibility).toHaveValue('true');
        await expect(visibility).toBeDisabled();

        await parent.selectOption(neutralGroupId);
        await expect(visibility).toHaveValue('false');
        await expect(visibility).toBeEnabled();
    });

    test('Photographer can save and restore all boolean flags via Group Modal', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien');

        const uniqueName = `Group Flags ${Math.random().toString(36).substring(2, 8)}`;

        // 1. Group erstellen
        await page.getByRole('button', { name: 'Neuer Ordner' }).click();
        await form.fillGroupModal({
            name: uniqueName,
            freeDownload: true,
            editorialOnly: true,
            hidden: true
        });
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.group?.id) helper.trackGroup(resData.group.id);

        await expect(page.locator('.toast')).toContainText('Ordner erfolgreich erstellt');

        await page.reload();
        await page.waitForSelector('summary', { timeout: 10000 });
        await page.locator('summary').filter({ hasText: uniqueName }).locator('button').filter({ has: page.locator('span.mdi--pencil') }).click();
        
        // Assert Modal UI is populated
        await modal.assertCheckboxByLabel('Im Frontend verstecken', true);
        await modal.assertCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', true);
        await modal.assertCheckboxByLabel('Kostenlosen Download erlauben', true);
    });

    test('Editing a group without touching its organisation preserves all assignments', { tag: ['@feature:admin:galleries', '@regression'] }, async ({ page, request }) => {
        const adminHeaders = { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() };
        const orgName = `E2E Group Org ${Math.random().toString(36).substring(2, 8)}`;
        const orgResponse = await request.post('/api/management/orgs', {
            data: { name: orgName, invoice_frequency: 'immediate' },
            headers: adminHeaders,
        });
        expect(orgResponse.ok()).toBeTruthy();
        const orgData = await orgResponse.json();
        const orgId = orgData.org?.id as string | undefined;
        if (!orgId) throw new Error('Organisation ID missing');
        helper.trackOrg(orgId);

        const secondOrgName = `E2E Second Group Org ${Math.random().toString(36).substring(2, 8)}`;
        const secondOrgResponse = await request.post('/api/management/orgs', {
            data: { name: secondOrgName, invoice_frequency: 'immediate' },
            headers: adminHeaders,
        });
        expect(secondOrgResponse.ok()).toBeTruthy();
        const secondOrgData = await secondOrgResponse.json();
        const secondOrgId = secondOrgData.org?.id as string | undefined;
        if (!secondOrgId) throw new Error('Second organisation ID missing');
        helper.trackOrg(secondOrgId);

        const groupName = `E2E Untouched Org ${Math.random().toString(36).substring(2, 8)}`;
        const groupResponse = await request.post('/api/management/gallery-groups', {
            data: { name: groupName, org_id: orgId },
            headers: adminHeaders,
        });
        expect(groupResponse.ok()).toBeTruthy();
        const groupData = await groupResponse.json();
        const groupId = groupData.group?.id as string | undefined;
        if (!groupId) throw new Error('Group ID missing');
        helper.trackGroup(groupId);

        const attachResponse = await request.put(`/api/management/orgs/${secondOrgId}/groups`, {
            data: { group_ids: [groupId] },
            headers: adminHeaders,
        });
        expect(attachResponse.ok()).toBeTruthy();

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien & Ordner');

        const main = page.locator('main');
        const groupSummary = main.locator('summary').filter({ hasText: groupName });
        await expect(groupSummary).toBeVisible({ timeout: 10000 });
        await groupSummary.locator('button[data-tip="Ordner bearbeiten"]').click();

        const dialog = page.getByRole('dialog', { name: 'Meta-Galerie bearbeiten' });
        await expect(dialog).toBeVisible();
        await dialog.locator('input[name="name"]').fill(`${groupName} Updated`);
        const orgSelect = dialog.locator('select[name="org_id"]');
        const selectedOrgId = await orgSelect.inputValue();
        expect([orgId, secondOrgId]).toContain(selectedOrgId);

        const updateRequestPromise = page.waitForRequest((req) =>
            req.method() === 'PUT' && req.url().endsWith(`/api/management/gallery-groups/${groupId}`)
        );
        const updateResponsePromise = page.waitForResponse((res) =>
            res.request().method() === 'PUT' && res.url().endsWith(`/api/management/gallery-groups/${groupId}`)
        );
        await dialog.getByRole('button', { name: 'Speichern' }).click();

        const updateRequest = await updateRequestPromise;
        const updatePayload = updateRequest.postDataJSON() as Record<string, unknown>;
        expect(updatePayload).not.toHaveProperty('org_id');
        const updateResponse = await updateResponsePromise;
        expect(updateResponse.ok()).toBeTruthy();
        await expect(dialog).toHaveCount(0);

        const treeResponse = await request.get('/api/management/galleries', { headers: adminHeaders });
        expect(treeResponse.ok()).toBeTruthy();
        const tree = await treeResponse.json() as {
            groups: Array<{ id: string; orgs?: Array<{ id: string }> }>;
        };
        const updatedGroup = tree.groups.find((group) => group.id === groupId);
        expect(updatedGroup?.orgs).toEqual(expect.arrayContaining([
            expect.objectContaining({ id: orgId }),
            expect.objectContaining({ id: secondOrgId }),
        ]));
    });

    test('Meta route preserves and forwards organisation assignments while editing a group', { tag: ['@feature:admin:galleries', '@regression'] }, async ({ page, request }) => {
        const adminUser = await helper.createIsolatedUser('admin');
        const adminHeaders = { 'Accept': 'application/json', 'Cookie': helper.getAdminToken() };
        const firstOrgName = `E2E Meta Org A ${Math.random().toString(36).substring(2, 8)}`;
        const firstOrgResponse = await request.post('/api/management/orgs', {
            data: { name: firstOrgName, invoice_frequency: 'immediate' },
            headers: adminHeaders,
        });
        expect(firstOrgResponse.ok()).toBeTruthy();
        const firstOrgData = await firstOrgResponse.json();
        const firstOrgId = firstOrgData.org?.id as string | undefined;
        if (!firstOrgId) throw new Error('First organisation ID missing');
        helper.trackOrg(firstOrgId);

        const secondOrgName = `E2E Meta Org B ${Math.random().toString(36).substring(2, 8)}`;
        const secondOrgResponse = await request.post('/api/management/orgs', {
            data: { name: secondOrgName, invoice_frequency: 'immediate' },
            headers: adminHeaders,
        });
        expect(secondOrgResponse.ok()).toBeTruthy();
        const secondOrgData = await secondOrgResponse.json();
        const secondOrgId = secondOrgData.org?.id as string | undefined;
        if (!secondOrgId) throw new Error('Second organisation ID missing');
        helper.trackOrg(secondOrgId);

        const groupName = `E2E Meta Route ${Math.random().toString(36).substring(2, 8)}`;
        const groupResponse = await request.post('/api/management/gallery-groups', {
            data: { name: groupName, org_id: firstOrgId },
            headers: adminHeaders,
        });
        expect(groupResponse.ok()).toBeTruthy();
        const groupData = await groupResponse.json();
        const groupId = groupData.group?.id as string | undefined;
        if (!groupId) throw new Error('Meta group ID missing');
        helper.trackGroup(groupId);

        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password);
        // This route is intentionally opened directly: the management tree has no
        // public link to the aggregate view, and the regression needs that route's
        // own resource/modal wiring.
        await page.goto(`/meta/${groupId}`);

        const main = page.locator('main');
        await expect(main.getByText(groupName, { exact: false }).first()).toBeVisible({ timeout: 15000 });
        await main.locator('button[data-tip="Meta-Galerie bearbeiten"]').click();

        const dialog = page.getByRole('dialog', { name: 'Meta-Galerie bearbeiten' });
        await expect(dialog).toBeVisible();
        const orgSelect = dialog.locator('select[name="org_id"]');
        await expect(orgSelect).toHaveValue(firstOrgId, { timeout: 10000 });

        await dialog.locator('input[name="name"]').fill(`${groupName} Updated`);
        await orgSelect.selectOption(secondOrgId);

        const updateRequestPromise = page.waitForRequest((req) =>
            req.method() === 'PUT' && req.url().endsWith(`/api/management/gallery-groups/${groupId}`)
        );
        const updateResponsePromise = page.waitForResponse((res) =>
            res.request().method() === 'PUT' && res.url().endsWith(`/api/management/gallery-groups/${groupId}`)
        );
        await dialog.getByRole('button', { name: 'Speichern' }).click();

        const updateRequest = await updateRequestPromise;
        const updatePayload = updateRequest.postDataJSON() as { org_id?: string | null };
        expect(updatePayload.org_id).toBe(secondOrgId);
        const updateResponse = await updateResponsePromise;
        expect(updateResponse.ok()).toBeTruthy();
        await expect(dialog).toHaveCount(0);
    });

    test('Photographer can save and restore all boolean flags via Gallery Modal', { tag: ['@feature:admin:galleries'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Galerien');

        const uniqueName = `Gallery Flags ${Math.random().toString(36).substring(2, 8)}`;

        // 1. Gallery erstellen
        await page.getByRole('button', { name: 'Neue Galerie' }).click();
        await form.fillGalleryModal({
            name: uniqueName,
            type: 'Delivery (Downloads)',
            freeDownload: true,
            editorialOnly: true,
            hidden: true,
            live: true
        });
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await expect(page.locator('.toast')).toContainText('Galerie erfolgreich erstellt');

        // 2. Roundtrip Check
        await page.reload();
        await page.locator('a').filter({ hasText: uniqueName }).locator('..').locator('button[data-tip="Bearbeiten"]').click();
        
        // Assert Modal UI is populated
        await modal.assertCheckboxByLabel('Im Frontend verstecken', true);
        await modal.assertCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', true);
        await modal.assertCheckboxByLabel('Kostenlosen Download erlauben', true);
        await modal.assertCheckboxByLabel('LIVE Galerie', true);
    });
});
