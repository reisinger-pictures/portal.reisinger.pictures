import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { renderWithProviders } from '../../../test-setup';
import { UIContext, type UIContextType } from '../../components/UIContext';
import { apiMutate } from '../../../api';
import { useOrgs, type Org } from '../../../logic/useOrgs';
import { useUsers, type UserDetailed } from '../../../logic/useUsers';
import { useProtectedGalleries, type GalleryTreeResponse } from '../../../logic/useGalleries';
import ManagementOrgDetailView from '../ManagementOrgDetailView';

// The companion of ManagementOrgsView.test.tsx, and the other half of the
// shared-modal migration that landed without a test holding it. This view owns
// the invite dialog, so the same three clauses apply — named and modal, Escape
// closes, submit goes through the form — plus the settings form next to it,
// which is a second, independent submit path the dialog tests cannot reach.
//
// All four hook mocks are spread over their real module shape rather than
// replacing it, so every `vi.mocked(...).mockReturnValue(...)` below is checked
// against the real inferred return type. A partial mock cannot compile here.

vi.mock('../../../logic/useOrgs', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useOrgs')>()),
    useOrgs: vi.fn(),
}));

vi.mock('../../../logic/useUsers', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useUsers')>()),
    useUsers: vi.fn(),
}));

vi.mock('../../../logic/useGalleries', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useGalleries')>()),
    useProtectedGalleries: vi.fn(),
}));

vi.mock('../../../api', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../api')>()),
    apiMutate: vi.fn(),
}));

// PageLayout pulls in DashboardLayout → Sidebar → GalleryModals, which reads
// `useProtectedGalleries` a second time. None of that is under test here.
vi.mock('../../components/PageLayout', () => ({
    default: ({ children }: { children: ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

const org: Org = {
    id: 'org-1',
    name: 'Firma XYZ',
    domain: 'firma.de',
    invoice_frequency: 'monthly',
    default_flatrate_level: 'none',
    shared_flatrate_cents: 0,
    auto_join_policy: 'requires_invite',
    users_count: 2,
    gallery_groups_count: 1,
    open_delivery_notes_count: 0,
    users: [{ id: 'u1', name: 'Max Mustermann', email: 'max@example.com' }],
    gallery_groups: [{ id: 'g1', name: 'Print', parent_id: null }],
};

const users: UserDetailed[] = [
    {
        id: 'u1',
        name: 'Max Mustermann',
        email: 'max@example.com',
        is_super_admin: false,
        is_admin: true,
        is_photographer: false,
        is_pending: false,
        can_edit_metadata: false,
        flatrate_level: 'none',
        roles: [],
        gallery_groups: [],
        galleries: [],
    },
    {
        id: 'u2',
        name: 'Erika Musterfrau',
        email: 'erika@example.com',
        is_super_admin: false,
        is_admin: false,
        is_photographer: false,
        is_pending: false,
        can_edit_metadata: false,
        flatrate_level: 'web',
        roles: [],
        gallery_groups: [],
        galleries: [],
    },
];

const tree: GalleryTreeResponse = {
    groups: [
        {
            id: 'g1',
            name: 'Print',
            parent_id: null,
            children: [{ id: 'g2', name: 'Hochzeit', parent_id: 'g1' }],
        },
    ],
    root_galleries: [],
};

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

const updateOrg = vi.fn().mockResolvedValue(undefined);

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/orgs/org-1']}>
            <UIContext.Provider value={uiContext}>
                {/* A real route is required: the view builds every API path
                    from `useParams().id`, so without one the invite would post
                    to `/orgs/undefined/invites`. */}
                <Routes>
                    <Route path="/orgs/:id" element={<ManagementOrgDetailView />} />
                </Routes>
            </UIContext.Provider>
        </MemoryRouter>,
    );
}

function mockOrgData(overrides: { org?: Org; isLoading?: boolean } = {}) {
    vi.mocked(useOrgs).mockReturnValue({
        org: 'org' in overrides ? overrides.org : org,
        orgs: undefined,
        isLoading: overrides.isLoading ?? false,
        createOrg: vi.fn().mockResolvedValue(undefined),
        updateOrg,
        deleteOrg: vi.fn().mockResolvedValue(undefined),
        syncUsers: vi.fn().mockResolvedValue(undefined),
        syncGroups: vi.fn().mockResolvedValue(undefined),
        generateCollectiveInvoice: vi.fn().mockResolvedValue({
            success: true,
            invoice_number: 'SR-2026-001',
            processed_orders: 0,
        }),
    });
}

async function openInviteDialog(eventUser: ReturnType<typeof userEvent.setup>) {
    await eventUser.click(screen.getByRole('button', { name: '+ Einladen' }));
    return screen.getByRole('dialog', { name: 'Nutzer in Organisation einladen' });
}

/**
 * Submits the form element itself, never the button. The invite dialog's footer
 * button is a `type="submit"`, but a test that clicks it cannot tell a
 * form-level `onSubmit` from a button-level `onClick` — and this view's
 * behaviour is entirely in the form handler.
 */
function submitFormOf(container: HTMLElement, submitButtonName: string) {
    const submitButton = within(container).getByRole('button', { name: submitButtonName });
    expect(submitButton).toHaveAttribute('type', 'submit');

    const form = submitButton.closest('form');
    if (form === null) {
        throw new Error(`expected the "${submitButtonName}" button to live inside a form element`);
    }
    fireEvent.submit(form);
}

/**
 * Resolves the `.form-control` a label belongs to, so a field is reached by the
 * German text that introduces it rather than by index.
 */
function fieldOf(scope: HTMLElement, labelText: string) {
    // `closest` is typed `Element | null`; `within()` needs an `HTMLElement`, so
    // the guard narrows instead of casting.
    const control = within(scope).getByText(labelText).closest('.form-control');
    if (!(control instanceof HTMLElement)) {
        throw new Error(`expected the "${labelText}" label to sit inside a form-control`);
    }
    return control;
}

describe('ManagementOrgDetailView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        mockOrgData();
        vi.mocked(useUsers).mockReturnValue({
            users,
            roles: [],
            createUser: vi.fn().mockResolvedValue(undefined),
            updateUser: vi.fn().mockResolvedValue(undefined),
            deleteUser: vi.fn().mockResolvedValue(undefined),
        });
        vi.mocked(useProtectedGalleries).mockReturnValue({
            tree,
            isLoading: false,
            isError: undefined,
            mutate: vi.fn().mockResolvedValue(undefined),
            createGroup: vi.fn().mockResolvedValue(undefined),
            createGallery: vi.fn().mockResolvedValue(undefined),
            updateGroup: vi.fn().mockResolvedValue(undefined),
            updateGallery: vi.fn().mockResolvedValue(undefined),
            deleteGroup: vi.fn().mockResolvedValue(undefined),
            deleteGallery: vi.fn().mockResolvedValue(undefined),
        });
        vi.mocked(apiMutate).mockResolvedValue(undefined);
    });

    it('reports a missing organisation instead of rendering an empty form', () => {
        mockOrgData({ org: undefined });

        renderView();

        expect(screen.getByText('Organisation nicht gefunden.')).toBeInTheDocument();
        expect(screen.queryByRole('dialog', { name: 'Nutzer in Organisation einladen' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Einladen' })).not.toBeInTheDocument();
    });

    it('opens the invite dialog as a named aria-modal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openInviteDialog(eventUser);

        // One assertion covers role, aria-labelledby wiring and the name text.
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        // The page itself has a "Löschen" button; the dialog must not, because
        // the view passes `editing={false}` — this dialog invites, it does not
        // remove an existing member. Scoped to the dialog on purpose.
        expect(within(dialog).queryByRole('button', { name: 'Löschen' })).not.toBeInTheDocument();

        await eventUser.keyboard('{Escape}');
        expect(screen.queryByRole('dialog', { name: 'Nutzer in Organisation einladen' })).not.toBeInTheDocument();
        expect(apiMutate).not.toHaveBeenCalled();
    });

    it('sends the invitation from the form onSubmit and closes on success', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openInviteDialog(eventUser);

        await eventUser.type(within(dialog).getByPlaceholderText('kollege@firma.de'), 'kollege@firma.de');
        submitFormOf(dialog, 'Einladung Senden');

        expect(apiMutate).toHaveBeenCalledWith('/api/management/orgs/org-1/invites', 'POST', {
            email: 'kollege@firma.de',
        });

        await waitFor(() => {
            expect(uiContext.showToast).toHaveBeenCalledWith('success', 'Einladung erfolgreich versendet.');
            expect(screen.queryByRole('dialog', { name: 'Nutzer in Organisation einladen' })).not.toBeInTheDocument();
        });
    });

    it('closes the invite dialog on cancel without sending anything', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openInviteDialog(eventUser);

        await eventUser.type(within(dialog).getByPlaceholderText('kollege@firma.de'), 'nie.gesendet@firma.de');
        await eventUser.click(within(dialog).getByRole('button', { name: 'Abbrechen' }));

        expect(screen.queryByRole('dialog', { name: 'Nutzer in Organisation einladen' })).not.toBeInTheDocument();
        expect(apiMutate).not.toHaveBeenCalled();
        expect(uiContext.showToast).not.toHaveBeenCalled();
    });

    it('saves the settings through the settings form onSubmit with its seeded values', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const main = screen.getByTestId('page-layout');

        // The form state is seeded from the fetched org, not from defaults: the
        // domain, the monthly frequency and the invite-only join policy only
        // reach the payload if the seeding on load is intact.
        const nameInput = within(fieldOf(main, 'Organisations-Name')).getByRole('textbox');
        expect(nameInput).toHaveValue('Firma XYZ');

        await eventUser.clear(nameInput);
        await eventUser.type(nameInput, 'Firma XYZ GmbH');
        submitFormOf(main, 'Speichern');

        expect(updateOrg).toHaveBeenCalledWith('org-1', {
            name: 'Firma XYZ GmbH',
            domain: 'firma.de',
            invoice_frequency: 'monthly',
            default_flatrate_level: 'none',
            shared_flatrate_cents: 0,
            auto_join_policy: 'requires_invite',
        });

        await waitFor(() => {
            expect(uiContext.showToast).toHaveBeenCalledWith('success', 'Organisation aktualisiert.');
        });
    });

    it('keeps the German settings labels and hides the shared budget while no flatrate is selected', () => {
        renderView();
        const main = screen.getByTestId('page-layout');

        expect(within(main).getByText('Organisations-Name')).toBeInTheDocument();
        expect(within(main).getByText('Auto-Join Domain')).toBeInTheDocument();
        expect(within(main).getByText('Rechnungs-Rhythmus')).toBeInTheDocument();
        expect(within(main).getByText('Standard-Flatrate-Level')).toBeInTheDocument();
        expect(within(main).getByText('Auto-Join Policy')).toBeInTheDocument();

        // Conditional field: the label and its placeholder only exist once a
        // flatrate level is chosen, so nothing else pins them.
        expect(within(main).queryByText('Geteiltes Flatrate-Budget (Cent)')).not.toBeInTheDocument();

        expect(within(main).queryByText(/\(optional\)/i)).not.toBeInTheDocument();
    });

    it('reveals the shared budget field once a flatrate level is picked and saves its value', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const main = screen.getByTestId('page-layout');

        await eventUser.selectOptions(
            within(fieldOf(main, 'Standard-Flatrate-Level')).getByRole('combobox'),
            'web',
        );

        const budgetControl = fieldOf(main, 'Geteiltes Flatrate-Budget (Cent)');
        const budgetInput = within(budgetControl).getByRole('spinbutton');
        expect(budgetInput).toHaveAttribute('placeholder', 'z.B. 50000 für 500€');

        await eventUser.type(budgetInput, '50000');
        submitFormOf(main, 'Speichern');

        expect(updateOrg).toHaveBeenCalledWith(
            'org-1',
            expect.objectContaining({
                default_flatrate_level: 'web',
                shared_flatrate_cents: 50000,
            }),
        );
    });

    it('keeps the invite dialog labels, the custom submit text and native required validation', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openInviteDialog(eventUser);

        expect(within(dialog).getByText('E-Mail Adresse')).toBeInTheDocument();

        // The dialog overrides the shell's default submit label. A string change
        // that drops the override would silently turn this into "Speichern",
        // which is the wrong verb for sending an invitation.
        expect(within(dialog).getByRole('button', { name: 'Einladung Senden' })).toBeInTheDocument();

        // Field Label Policy: the star comes from `required`, and the view
        // deliberately keeps native constraint validation, so the shell must not
        // have added `noValidate`.
        const emailInput = within(dialog).getByPlaceholderText('kollege@firma.de');
        expect(emailInput).toBeRequired();
        expect(emailInput).toHaveAttribute('type', 'email');
        expect(emailInput.closest('form')).not.toHaveAttribute('novalidate');

        expect(within(dialog).queryByText(/\(optional\)/i)).not.toBeInTheDocument();
    });
});
