import { type APIRequestContext, type APIResponse } from '@playwright/test';
import { E2ESessionHelper } from './E2ESessionHelper';

/**
 * Fixtures for the specs that pin the **access scope**: which membership
 * justifies which gallery, and what happens to that access when the membership
 * is revoked.
 *
 * The fixtures are built through the management API (the same endpoints the
 * management views call) and every created row is handed to the
 * {@link E2ESessionHelper} for the teardown — nothing is left behind in the
 * shared E2E database.
 *
 * Why the API and not the UI: the specs walk the *client's* user flow in the
 * browser (login → gallery → notification toggle → preferences), while the
 * **management side** (create an org, attach a gallery, sync a membership) is
 * the setup of that flow, not its subject. Building it through the API keeps
 * the browser part free of a second, competing flow and keeps the specs fast
 * enough for the per-test budget.
 */

export type FixtureUser = {
    email: string;
    password: string;
    id: string;
};

export type FixtureGallery = {
    id: string;
    slug: string;
    name: string;
};

export type FixtureGroup = {
    id: string;
    name: string;
};

export type FixtureOrg = {
    id: string;
    name: string;
};

const ADMIN_EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'admin';

/**
 * The studio administrator of the E2E backend.
 *
 * The seeded admin holds **every** role (including `super_admin` and
 * `photographer`), so it may create galleries and groups and manage them. It
 * is used as the *owner* of the fixtures: a fixture that nobody logs in as
 * must not cost a password-reset round trip over Mailpit — the E2E database
 * and the mail server are shared by the whole run, and every avoidable write
 * is contention the other specs feel.
 */
export const STUDIO_ADMIN: FixtureUser = {
    email: ADMIN_EMAIL,
    password: ADMIN_PASSWORD,
    id: '',
};

/**
 * The admin cookie for the management endpoints.
 *
 * `E2ESessionHelper.loginAs()` is used instead of the harness admin token:
 * the fixture may run before any harness call has logged the admin in, and the
 * token would then be empty. The helper's own `teardown()` logs the admin in
 * again when it needs the token.
 */
export async function adminCookie(helper: E2ESessionHelper): Promise<string> {
    return helper.loginAs(ADMIN_EMAIL, ADMIN_PASSWORD);
}

async function readJson<T>(response: APIResponse, context: string): Promise<T> {
    if (!response.ok()) {
        throw new Error(`${context} failed (HTTP ${response.status()}): ${await response.text()}`);
    }
    return await response.json() as T;
}

/**
 * Create an organisation and track it for the teardown.
 */
export async function createOrgFixture(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    name: string,
    options: { defaultFlatrateLevel?: string; canPurchaseUpgrades?: boolean } = {},
): Promise<FixtureOrg> {
    const cookie = await adminCookie(helper);
    const response = await request.post('/api/management/orgs', {
        data: {
            name,
            invoice_frequency: 'immediate',
            default_flatrate_level: options.defaultFlatrateLevel ?? 'none',
            can_purchase_upgrades: options.canPurchaseUpgrades ?? false,
        },
        headers: { Accept: 'application/json', Cookie: cookie },
    });

    const body = await readJson<{ org?: { id?: string } }>(response, `Creating the organisation "${name}"`);
    const id = body.org?.id;
    if (!id) throw new Error(`The organisation "${name}" was created without an id: ${JSON.stringify(body)}`);

    helper.trackOrg(id);
    return { id, name };
}

/**
 * Create a **delivery** gallery and track it for the teardown.
 *
 * The gallery is created by the given photographer: only a photographer (or a
 * super admin) may create a gallery (`GalleryPolicy::create()`), and a studio
 * admin is neither.
 *
 * The gallery is private by default (the group has no visibility policy), so
 * every access to it goes through the grant reader and not through the public
 * feed.
 */
export async function createDeliveryGallery(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    owner: FixtureUser,
    options: { name: string; groupId?: string; orgIds?: string[]; restricted?: boolean },
): Promise<FixtureGallery> {
    const cookie = await helper.loginAs(owner.email, owner.password);
    const data: Record<string, unknown> = {
        name: options.name,
        type: 'delivery',
        is_public: false,
        restricted_photographers: options.restricted ?? false,
    };
    if (options.groupId) data.gallery_group_id = options.groupId;
    if (options.orgIds) data.org_ids = options.orgIds;

    const response = await request.post('/api/management/galleries', {
        data,
        headers: { Accept: 'application/json', Cookie: cookie },
    });

    const body = await readJson<{ gallery?: { id?: string; slug?: string } }>(
        response,
        `Creating the delivery gallery "${options.name}"`,
    );
    const id = body.gallery?.id;
    const slug = body.gallery?.slug;
    if (!id || !slug) {
        throw new Error(`The gallery "${options.name}" was created without an id/slug: ${JSON.stringify(body)}`);
    }

    helper.trackGallery(String(id));
    return { id: String(id), slug, name: options.name };
}

/**
 * Create a gallery group (a "meta gallery") and track it for the teardown.
 *
 * The group has no visibility policy (`is_public: null`), so a gallery inside
 * it stays private.
 */
export async function createGalleryGroupFixture(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    owner: FixtureUser,
    name: string,
): Promise<FixtureGroup> {
    const cookie = await helper.loginAs(owner.email, owner.password);
    const response = await request.post('/api/management/gallery-groups', {
        data: { name, is_public: null },
        headers: { Accept: 'application/json', Cookie: cookie },
    });

    const body = await readJson<{ group?: { id?: string } }>(response, `Creating the gallery group "${name}"`);
    const id = body.group?.id;
    if (!id) throw new Error(`The group "${name}" was created without an id: ${JSON.stringify(body)}`);

    helper.trackGroup(String(id));
    return { id: String(id), name };
}

/**
 * Write the membership of an organisation.
 *
 * This is the endpoint of the management view's "Zuweisungen speichern"
 * (`PUT /api/management/orgs/{id}/users`). The submitted ids are the complete
 * membership: an id that is **not** submitted is detached.
 */
export async function syncOrgMembers(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    orgId: string,
    userIds: string[],
): Promise<void> {
    const cookie = await adminCookie(helper);
    const response = await request.put(`/api/management/orgs/${orgId}/users`, {
        data: { user_ids: userIds },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Syncing the members of the organisation ${orgId} failed: ${await response.text()}`);
    }
}

/**
 * Write the **group assignment** of a user.
 *
 * `gallery_group_ids` is the client group grant (`user_gallery_groups`): the
 * assignment covers the whole subtree of the group. The endpoint replaces the
 * set, so an empty list revokes the assignment.
 *
 * Only `gallery_group_ids` is sent: the endpoint leaves the roles and the
 * brand of the user untouched when they are not part of the payload.
 */
export async function setUserGalleryGroups(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    userId: string,
    groupIds: string[],
): Promise<void> {
    const cookie = await adminCookie(helper);
    const response = await request.put(`/api/management/users/${userId}`, {
        data: { gallery_group_ids: groupIds },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Writing the group assignment of the user ${userId} failed: ${await response.text()}`);
    }
}

/**
 * Assign a photographer to a gallery group.
 *
 * This is the endpoint of the management view's photographer assignment
 * (`POST /api/management/gallery-groups/{id}/sync-photographers`). It writes the
 * `photographer_gallery_groups` pivot — the assignment that
 * `AuthorizationService::canManageGalleryGroup()` reads for a photographer.
 */
export async function assignPhotographerToGroup(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    groupId: string,
    userId: string,
): Promise<void> {
    const cookie = await adminCookie(helper);
    const response = await request.post(`/api/management/gallery-groups/${groupId}/sync-photographers`, {
        data: { user_id: userId, action: 'attach' },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Assigning the photographer ${userId} to the group ${groupId} failed: ${await response.text()}`);
    }
}

/**
 * Create a user that never logs in.
 *
 * The user is created through the management API and gets its roles — but no
 * password. A user that is only a *member* of an organisation (the subject of
 * the membership sync) never authenticates, and a password reset would cost a
 * Mailpit round trip per user for nothing.
 *
 * The returned user has an empty password: logging in with it is impossible by
 * design.
 */
export async function createMemberUser(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    roleNames: string[] = ['client'],
): Promise<FixtureUser> {
    const cookie = await adminCookie(helper);
    const email = `e2e-member-${Math.random().toString(36).substring(2, 10)}@example.com`;

    const response = await request.post('/api/management/users', {
        data: { name: 'E2E Member', email },
        headers: { Accept: 'application/json', Cookie: cookie },
    });
    const body = await readJson<{ user?: { id?: string } }>(response, `Creating the member ${email}`);
    const id = body.user?.id;
    if (!id) throw new Error(`The member ${email} was created without an id: ${JSON.stringify(body)}`);

    helper.trackUser(String(id));
    await setUserRoles(request, helper, String(id), roleNames);

    return { email, password: '', id: String(id) };
}

/**
 * Write the roles of a user.
 *
 * The role names are resolved through `/api/management/roles`, so a typo cannot
 * silently produce an account without the role.
 */
export async function setUserRoles(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    userId: string,
    roleNames: string[],
): Promise<void> {
    const cookie = await adminCookie(helper);
    const rolesResponse = await request.get('/api/management/roles', {
        headers: { Accept: 'application/json', Cookie: cookie },
    });
    const roles = await readJson<Array<{ id: string; name: string }>>(rolesResponse, 'Reading the role list');
    const roleIds = roleNames.map((name) => {
        const role = roles.find((candidate) => candidate.name === name);
        if (!role) throw new Error(`The role "${name}" was not returned by /api/management/roles`);
        return role.id;
    });

    const response = await request.put(`/api/management/users/${userId}`, {
        data: { role_ids: roleIds, brand: 'rp' },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Writing the roles of the user ${userId} failed: ${await response.text()}`);
    }
}

/**
 * Write the scope values an organisation hands to its members.
 *
 * `flatrate_level` and `can_purchase_upgrades` are the two values the
 * organisation gives to its members; the delete of the organisation takes them
 * back (see `OrgController::destroy()`).
 */
export async function setUserScope(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    userId: string,
    values: { flatrate_level?: string; can_purchase_upgrades?: boolean },
): Promise<void> {
    const cookie = await adminCookie(helper);
    const response = await request.put(`/api/management/users/${userId}`, {
        data: values,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Writing the scope of the user ${userId} failed: ${await response.text()}`);
    }
}

/**
 * Read the flatrate level and the upgrade flag of a user from the management
 * user list.
 *
 * Both fields are part of the `UserResource`, and both are rolled back when the
 * organisation is deleted: the organisation's default flatrate level and its
 * upgrade permission are given to its members, and the delete takes them back.
 */
export async function readUserScope(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    userId: string,
): Promise<{ flatrate_level: string; can_purchase_upgrades: boolean } | null> {
    const cookie = await adminCookie(helper);
    const response = await request.get('/api/management/users', {
        headers: { Accept: 'application/json', Cookie: cookie },
    });
    const body = await readJson<{ data?: Array<{ id: string; flatrate_level?: string; can_purchase_upgrades?: boolean }> }>(
        response,
        'Reading the management user list',
    );
    const user = (body.data ?? []).find((candidate) => candidate.id === userId);
    if (!user) return null;
    return {
        flatrate_level: user.flatrate_level ?? 'none',
        can_purchase_upgrades: user.can_purchase_upgrades ?? false,
    };
}

/**
 * The members of an organisation, as the management view reads them.
 *
 * `null` means the organisation does not exist any more (the endpoint answers
 * with 404).
 */
export async function readOrgMembers(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    orgId: string,
): Promise<Array<{ id: string; email: string }> | null> {
    const cookie = await adminCookie(helper);
    const response = await request.get(`/api/management/orgs/${orgId}`, {
        headers: { Accept: 'application/json', Cookie: cookie },
    });
    if (response.status() === 404) return null;
    if (!response.ok()) {
        throw new Error(`Reading the organisation ${orgId} failed (HTTP ${response.status()}): ${await response.text()}`);
    }
    const body = await readJson<{ users?: Array<{ id: string; email: string }> }>(
        response,
        `Reading the organisation ${orgId}`,
    );
    return body.users ?? [];
}

/**
 * The organisation scope of a user, as `/api/auth/me` reports it.
 *
 * The scope is only reported for an organisation admin — and an organisation
 * admin is a user who **is** a member of the organisation (`org_id !== null`).
 * A scope of `null` therefore means: the user is not bound to an organisation
 * any more.
 */
export async function readAuthOrgScope(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    user: FixtureUser,
): Promise<{ org_id?: string } | null> {
    const cookie = await helper.loginAs(user.email, user.password);
    const response = await request.get('/api/auth/me', {
        headers: { Accept: 'application/json', Cookie: cookie },
    });
    if (!response.ok()) {
        throw new Error(`Reading /api/auth/me failed (HTTP ${response.status()}): ${await response.text()}`);
    }
    const body = await readJson<{ org_scope?: { org_id?: string } | null }>(response, 'Reading /api/auth/me');
    return body.org_scope ?? null;
}
