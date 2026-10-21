import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ToastHelper } from '../../helpers/ToastHelper';
import {
    createMemberUser,
    createOrgFixture,
    readAuthOrgScope,
    readOrgMembers,
    readUserScope,
    setUserRoles,
    setUserScope,
    syncOrgMembers,
    STUDIO_ADMIN,
} from '../../helpers/AccessScopeFixture';

/**
 * The membership of an organisation, as the management view writes it and as
 * the server keeps it.
 *
 * Two contracts are pinned here:
 *
 *  - **the sync** (`PUT /api/management/orgs/{id}/users`): the submitted users
 *    are the members and the omitted ones are not. This is the user-visible
 *    half of the lock order of owner decision D-40 — the two-connection proof
 *    of the order itself is a backend test, this is what the owner sees.
 *  - **the delete** (`DELETE /api/management/orgs/{id}`): the members of a
 *    deleted organisation are no longer bound to it, and the values the
 *    organisation gave them are taken back.
 */

const TAG = ['@regression', '@feature:admin:Org'];

/** Open the detail view of an organisation from the organisation list. */
async function openOrgDetail(page: Page, orgName: string): Promise<void> {
    const main = page.getByRole('main');
    await main.locator('.card-title', { hasText: orgName }).first().click();
    await expect(main.getByRole('heading', { name: orgName, exact: true })).toBeVisible({ timeout: 20000 });
}

test.describe('Organisation membership (sync and delete)', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('a membership sync makes the submitted users members and detaches the omitted ones', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const org = await createOrgFixture(request, helper, `E2E Sync Org ${suffix}`);
        const currentMember = await createMemberUser(request, helper);
        const newMember = await createMemberUser(request, helper);
        await syncOrgMembers(request, helper, org.id, [currentMember.id]);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(STUDIO_ADMIN.email, STUDIO_ADMIN.password);
        await sidebar.navigateTo('Organisationen');
        await openOrgDetail(page, org.name);

        const main = page.getByRole('main');
        const currentCheckbox = main.getByRole('checkbox', { name: currentMember.email });
        const newCheckbox = main.getByRole('checkbox', { name: newMember.email });

        // The view starts from the current membership: the member is ticked,
        // the other one is not.
        await expect(currentCheckbox).toBeChecked({ timeout: 20000 });
        await expect(newCheckbox).not.toBeChecked();

        // The management view submits the ticked users.
        await currentCheckbox.uncheck();
        await newCheckbox.check();
        await main.getByRole('button', { name: 'Zuweisungen speichern' }).click();
        await new ToastHelper(page).expectToast('Zuweisungen gespeichert.');

        // The submitted user is a member of the organisation...
        const members = await readOrgMembers(request, helper, org.id);
        expect(members?.map((member) => member.id)).toContain(newMember.id);
        // ...and the omitted one is not.
        expect(members?.map((member) => member.id)).not.toContain(currentMember.id);
    });

    test('deleting an organisation detaches its members', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const org = await createOrgFixture(request, helper, `E2E Delete Org ${suffix}`, {
            defaultFlatrateLevel: 'web',
            canPurchaseUpgrades: true,
        });
        const member = await helper.createIsolatedUser('client');
        // An organisation admin is a user who is a member of the organisation —
        // that is what the scope of `/api/auth/me` reports.
        await setUserRoles(request, helper, member.id, ['client', 'org_admin']);
        // The organisation hands its default flatrate and its upgrade
        // permission to its members.
        await setUserScope(request, helper, member.id, {
            flatrate_level: 'web',
            can_purchase_upgrades: true,
        });
        await syncOrgMembers(request, helper, org.id, [member.id]);

        // Before the delete the member is bound to the organisation.
        const scopeBefore = await readAuthOrgScope(request, helper, member);
        expect(scopeBefore?.org_id).toBe(org.id);

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(STUDIO_ADMIN.email, STUDIO_ADMIN.password);
        await sidebar.navigateTo('Organisationen');
        await openOrgDetail(page, org.name);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Löschen', exact: true }).click();
        // The confirmation of the delete is the global confirm dialog, which
        // lives outside the `<main>` landmark by design (the repo convention
        // for it is the `.modal-global` scope).
        const confirmDialog = page.locator('.modal-global');
        await expect(confirmDialog).toBeVisible({ timeout: 20000 });
        await confirmDialog.getByRole('button', { name: 'Bestätigen', exact: true }).click();

        // The organisation is gone.
        await expect(page).toHaveURL(/\/orgs$/, { timeout: 20000 });
        expect(await readOrgMembers(request, helper, org.id)).toBeNull();

        // The member is no longer bound to it: the scope of an organisation
        // admin is gone with the membership.
        const scopeAfter = await readAuthOrgScope(request, helper, member);
        expect(scopeAfter, 'A member of a deleted organisation must not keep an organisation scope').toBeNull();

        // And the values the organisation gave to its member are taken back.
        const memberScope = await readUserScope(request, helper, member.id);
        expect(memberScope?.flatrate_level).toBe('none');
        expect(memberScope?.can_purchase_upgrades).toBe(false);
    });
});
