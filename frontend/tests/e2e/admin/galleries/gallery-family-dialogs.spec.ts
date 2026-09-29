import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import {
    addReferenceRecipient,
    createReferenceDeliveryGallery,
    createReferenceGalleryGroup,
    createReferenceSelectionGallery,
    createReferenceUser,
} from '../../helpers/GalleryFamilyFixture';

/**
 * The gallery family, through the shared dialog semantics only.
 *
 * Every gallery dialog is opened through the real UI, asserted **inside the
 * `<main>` landmark**, and closed through the labelled button the shared
 * `ModalShell` renders for all of them. There is no per-dialog close code: the
 * point of the family is that twenty-eight dialogs share one shell
 * (`ModalShell` / `ModalDialogShell`), so one contract is asserted per member,
 * not a hand-rolled locator per dialog.
 *
 * WHY LANDMARK SCOPING (D-10 follow-through): `ModalShell` renders a plain
 * inline `<dialog open>` and uses no portal. Before `DashboardLayout` moved
 * `GalleryModals` inside `</main>` (D-10), a dialog rendered after the landmark
 * was not a descendant of it at all — a `page.locator('main').getByRole(...)`
 * locator then missed an element that was plainly on screen. The repo's E2E
 * convention is landmark scoping, so this sweep pins the fix for the whole
 * family: each dialog must resolve through a `<main>`-scoped locator, not just
 * through a page-level one.
 *
 * The family is the eight dialog entries the coverage board lists: Zugriff,
 * Einladungslink, Bewertungen, Metadaten-Vorgaben, Fotografen-Team, E-Mail,
 * Galerie bearbeiten and Meta-Galerie bearbeiten. All of them hang on the one
 * deterministic seed in `helpers/GalleryFamilyFixture.ts`.
 */

const TAG = ['@regression', '@feature:admin:galleries'];

/** The reference gallery's link resolved by slug (never by the constant name). */
async function openReferenceGallery(page: Page, slug: string): Promise<void> {
    await new SidebarHelper(page).navigateTo('Galerien & Ordner');
    const main = page.getByRole('main');
    // Resolved by href, not by the constant gallery name: `SlugService::makeUnique`
    // may have re-suffixed the slug for this run, and the href is what the tree
    // actually rendered, so the two can never drift apart.
    const link = main.locator(`a[href="/galleries/${slug}"]`).first();
    await expect(link).toBeVisible({ timeout: 15000 });
    await link.click();
    await expect(page).toHaveURL(new RegExp(`/galleries/${slug}$`));
    await expect(main.locator('button[data-tip="Galerie bearbeiten"]')).toBeVisible({ timeout: 15000 });
}

/**
 * Open → landmark-scoped dialog → close. The whole assertion vocabulary of a
 * member of the family, used verbatim for every one of them.
 */
async function assertDialogRoundTrip(page: Page, trigger: Locator, dialogName: string): Promise<void> {
    const main = page.getByRole('main');
    await expect(trigger).toBeVisible({ timeout: 15000 });
    await trigger.click();

    // D-10: this locator only resolves while the dialog is a descendant of the
    // landmark. It is the assertion the sweep exists for.
    const dialog = main.getByRole('dialog', { name: dialogName });
    await expect(dialog).toBeVisible({ timeout: 15000 });

    // The shared close contract: the labelled header button `ModalShell` renders
    // for every dialog, reached by its `aria-label` rather than by its
    // accessible name. `InviteModal` additionally renders a footer button whose
    // accessible name is the same text, so a role+name locator would be a
    // strict-mode violation — the shell's close is the one that carries the
    // `aria-label`, so it is also the precise handle on the shared contract.
    await dialog.locator('button[aria-label="Schließen"]').click();
    await expect(dialog).toHaveCount(0);
}

test.describe('Gallery family dialogs (landmark-scoped)', () => {
    test('every delivery-gallery dialog opens inside <main> and closes', { tag: TAG }, async ({ page, request }) => {
        const helper = new E2ESessionHelper(request);
        const user = await createReferenceUser(helper);
        const gallery = await createReferenceDeliveryGallery(request, helper, user);
        // Makes `E-Mail senden...` render enabled; the other five do not need it,
        // but they share the one gallery seed, so the recipient is added here.
        await addReferenceRecipient(helper, gallery);

        try {
            await new AuthHelper(page).login(user.email, user.password);
            await openReferenceGallery(page, gallery.slug);

            const main = page.getByRole('main');
            // Six of the eight family members live on a delivery gallery. The
            // landmark scope is re-evaluated for each, so each one is proven.
            await assertDialogRoundTrip(page, main.getByRole('button', { name: 'Fotografen...' }), 'Fotografen-Team');
            await assertDialogRoundTrip(page, main.getByRole('button', { name: 'Vorgaben...' }), 'Metadaten-Vorgaben');
            await assertDialogRoundTrip(page, main.getByRole('button', { name: 'Zugriff...' }), 'Nutzer-Zugriff verwalten');
            await assertDialogRoundTrip(page, main.getByRole('button', { name: 'Einladungslink...' }), 'Einladungen verwalten');
            await assertDialogRoundTrip(page, main.getByRole('button', { name: 'E-Mail senden...' }), 'Nachricht an Kunden senden');
            // The heading pencil is icon-only, so its accessible name comes from
            // the daisyUI `data-tip`; the attribute is unique in the app.
            await assertDialogRoundTrip(page, main.locator('button[data-tip="Galerie bearbeiten"]'), 'Galerie bearbeiten');
        } finally {
            await helper.teardown();
        }
    });

    test('the ratings dialog opens inside <main> on a selection gallery', { tag: TAG }, async ({ page, request }) => {
        const helper = new E2ESessionHelper(request);
        const user = await createReferenceUser(helper);
        // `Bewertungen...` is the one action-row trigger gated on
        // `gallery.type === 'selection'` (ManagementGalleryActions.tsx:31), so it
        // cannot hang on the delivery gallery above.
        const gallery = await createReferenceSelectionGallery(request, helper, user);

        try {
            await new AuthHelper(page).login(user.email, user.password);
            await openReferenceGallery(page, gallery.slug);

            await assertDialogRoundTrip(
                page,
                page.getByRole('main').getByRole('button', { name: 'Bewertungen...' }),
                'Bewertungen & Status',
            );
        } finally {
            await helper.teardown();
        }
    });

    test('the meta-gallery dialog opens inside <main>', { tag: TAG }, async ({ page, request }) => {
        const helper = new E2ESessionHelper(request);
        const user = await createReferenceUser(helper);
        const group = await createReferenceGalleryGroup(request, helper, user);

        try {
            await new AuthHelper(page).login(user.email, user.password);
            // Justified deep link, same one as `gallery-modals.spec.ts:241`
            // ("await page.goto(`/meta/${groupId}`);" there): the structure view
            // renders a group as a non-link `<summary>`, and the aggregate
            // view has no public entry in the tree — the only UI path is a
            // child gallery's breadcrumb, which an empty group does not have.
            // The route param is the group's primary id (`App.tsx:113`,
            // `<Route path="/meta/:id" element=…`).
            await page.goto(`/meta/${group.id}`);

            const main = page.getByRole('main');
            await expect(main.getByRole('heading', { name: /Meta-Galerie/ })).toBeVisible({ timeout: 15000 });
            await assertDialogRoundTrip(
                page,
                main.locator('button[data-tip="Meta-Galerie bearbeiten"]'),
                'Meta-Galerie bearbeiten',
            );
        } finally {
            await helper.teardown();
        }
    });
});
