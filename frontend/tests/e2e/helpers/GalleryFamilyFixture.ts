import { type APIRequestContext } from '@playwright/test';
import { E2ESessionHelper } from './E2ESessionHelper';

/**
 * The ONE deterministic gallery seed the whole gallery-dialog family hangs on.
 *
 * Every gallery dialog lives on a gallery, and all of them hang on the same
 * one: the delivery gallery below. `Bewertungen...` is the only action-row
 * trigger a delivery gallery cannot satisfy (it renders for
 * `type === 'selection'` only), so the family is three definitions of the same
 * shape — delivery, selection, meta-gallery group — not three unrelated seeds.
 *
 * Determinism, spelled out because the name/slug/type/visibility are constant:
 *
 * 1. The gallery is created **as the isolated test user** (`createReferenceUser`
 *    below), not via the harness admin. `GalleryService::storeGallery` attaches
 *    the authenticated user as the gallery's photographer, and the management
 *    tree only shows a photographer the galleries inside `getAllowedGalleryIds()`.
 *    An admin-created gallery would therefore be invisible to the isolated user
 *    the spec logs in as — the create call has to carry the user's own cookie.
 * 2. The spec resolves the gallery by the **slug from the create response**, not
 *    by the constant name: `SlugService::makeUnique` appends a counter when the
 *    fixed slug is already taken by an earlier run, and a stale slug would 404.
 * 3. `name`/`slug`/`type`/`is_public` are explicit constants here, so the seed no
 *    longer depends on a default the store call happens to apply.
 */

export const REFERENCE_DELIVERY_GALLERY = {
    name: 'E2E Galerie-Familie',
    slug: 'e2e-galerie-familie',
    type: 'delivery',
    isPublic: true,
} as const;

export const REFERENCE_SELECTION_GALLERY = {
    name: 'E2E Galerie-Familie Auswahl',
    slug: 'e2e-galerie-familie-auswahl',
    type: 'selection',
    // Selection galleries are private by definition and the backend forces the
    // flag false (GalleryService::storeGallery); sent for symmetry only.
    isPublic: false,
} as const;

export const REFERENCE_GALLERY_GROUP = {
    name: 'E2E Galerie-Familie Meta',
    isPublic: true,
} as const;

export interface ReferenceGallery {
    id: string;
    slug: string;
    name: string;
}

export interface ReferenceUser {
    email: string;
    password: string;
    id: string;
}

/**
 * One user for the whole family. Both roles are required and neither is
 * optional: the action row only renders for `isPhotographer`
 * (ManagementGalleryActions.tsx:23), while `Zugriff...` additionally needs
 * `onOpenAccess`, which `ManagementGalleryView` passes only for `isAdmin`
 * (ManagementGalleryView.tsx:95). A single-role user never sees the control.
 * This mirrors `admin/galleries/gallery-access-modal.spec.ts`.
 */
export async function createReferenceUser(helper: E2ESessionHelper): Promise<ReferenceUser> {
    return helper.createIsolatedUser('photographer', { additionalRoles: ['admin'] });
}

async function createGalleryAsUser(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    user: ReferenceUser,
    definition: { name: string; slug: string; type: 'delivery' | 'selection'; isPublic: boolean },
): Promise<ReferenceGallery> {
    const cookie = await helper.loginAs(user.email, user.password);
    const response = await request.post('/api/management/galleries', {
        data: {
            name: definition.name,
            slug: definition.slug,
            type: definition.type,
            is_public: definition.isPublic,
        },
        headers: { Accept: 'application/json', Cookie: cookie },
    });

    if (!response.ok()) {
        throw new Error(`Reference gallery creation failed (${response.status()}): ${await response.text()}`);
    }

    const body = (await response.json()) as { gallery?: { id?: string | number; slug?: string } };
    const id = body.gallery?.id;
    const slug = body.gallery?.slug;
    if (id === undefined || id === null || !slug) {
        throw new Error(`Reference gallery creation returned no id/slug: ${JSON.stringify(body)}`);
    }

    helper.trackGallery(String(id));
    return { id: String(id), slug, name: definition.name };
}

/** The reference delivery gallery — the one seed most of the family hangs on. */
export async function createReferenceDeliveryGallery(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    user: ReferenceUser,
): Promise<ReferenceGallery> {
    return createGalleryAsUser(request, helper, user, REFERENCE_DELIVERY_GALLERY);
}

/** The one gallery `Bewertungen...` needs, because the trigger is type-gated. */
export async function createReferenceSelectionGallery(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    user: ReferenceUser,
): Promise<ReferenceGallery> {
    return createGalleryAsUser(request, helper, user, REFERENCE_SELECTION_GALLERY);
}

/**
 * Opt one client in to notifications for the reference gallery.
 *
 * `E-Mail senden...` is the one trigger gated on data rather than on a role or a
 * gallery type: `canSendMail = (notified_count || 0) > 0`
 * (ManagementGalleryView.tsx:89), so without a recipient the button renders
 * disabled and a click would sit in Playwright's actionability wait until the
 * test times out. The opt-in endpoint is IDOR-guarded by `canAccessGallery()`,
 * so the client has to be assigned to the gallery first — `createIsolatedUser`
 * does both together.
 */
export async function addReferenceRecipient(
    helper: E2ESessionHelper,
    gallery: ReferenceGallery,
): Promise<void> {
    await helper.createIsolatedUser('client', {
        assignGalleryId: gallery.id,
        wantsNotifications: true,
    });
}

/**
 * The reference meta-gallery group. A group is enough: `/meta/:id` is addressed
 * by the group's numeric id, not by a gallery slug, and `GalleryGroupModal`
 * renders its editing title and delete button as soon as a group exists.
 *
 * Created as the isolated user for the same visibility reason as the galleries
 * above — a photographer may create folders from the structure view
 * (`photographer/structure.spec.ts`), and the user has to see the group
 * afterwards.
 */
export async function createReferenceGalleryGroup(
    request: APIRequestContext,
    helper: E2ESessionHelper,
    user: ReferenceUser,
): Promise<{ id: string; name: string }> {
    const cookie = await helper.loginAs(user.email, user.password);
    const response = await request.post('/api/management/gallery-groups', {
        data: { name: REFERENCE_GALLERY_GROUP.name, is_public: REFERENCE_GALLERY_GROUP.isPublic },
        headers: { Accept: 'application/json', Cookie: cookie },
    });

    if (!response.ok()) {
        throw new Error(`Reference gallery group creation failed (${response.status()}): ${await response.text()}`);
    }

    const body = (await response.json()) as { group?: { id?: string | number } };
    const id = body.group?.id;
    if (id === undefined || id === null) {
        throw new Error(`Reference gallery group creation returned no id: ${JSON.stringify(body)}`);
    }

    helper.trackGroup(String(id));
    return { id: String(id), name: REFERENCE_GALLERY_GROUP.name };
}
