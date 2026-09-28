import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { renderWithProviders } from '../../../test-setup';
import { UIContext, type UIContextType } from '../../components/UIContext';
import { useOrgs, type Org } from '../../../logic/useOrgs';
import { usePermissions, type Permissions } from '../../../logic/usePermissions';
import ManagementOrgsView from '../ManagementOrgsView';

// ManagementOrgsView had no unit suite at all, so the shared-modal migration
// onto ModalDialogShell landed with nothing holding it. The three clauses that
// matter live here: the dialog is named and modal and closes on Escape, the
// create handler is wired to the *form* rather than to a button click, and
// cancelling never reaches the API.
//
// The two hook mocks are spread over the real module shape instead of replacing
// it, which is what makes `vi.mocked(...).mockReturnValue(...)` below
// type-checked against the real inferred return type. A partial mock object
// cannot compile here — the exact failure the repo's older partial mocks had
// nobody checking for.

vi.mock('../../../logic/useOrgs', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useOrgs')>()),
    useOrgs: vi.fn(),
}));

vi.mock('../../../logic/usePermissions', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/usePermissions')>()),
    usePermissions: vi.fn(),
}));

// PageLayout pulls in DashboardLayout → Sidebar → GalleryModals and a second
// `useProtectedGalleries` call. None of that is under test; the view's own
// dialog and its create path are.
vi.mock('../../components/PageLayout', () => ({
    default: ({ children }: { children: ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

const orgs: Org[] = [
    {
        id: 'org-1',
        name: 'Firma XYZ',
        domain: 'firma.de',
        invoice_frequency: 'immediate',
        default_flatrate_level: 'web',
        users_count: 3,
        gallery_groups_count: 2,
    },
    {
        id: 'org-2',
        name: 'Ohne Domain',
        domain: null,
        invoice_frequency: 'monthly',
        users_count: 0,
        gallery_groups_count: 0,
    },
];

const basePermissions: Permissions = {
    isStaff: true,
    isSuperAdmin: false,
    isAdmin: true,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: true,
    canAccessProductionBoard: false,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: false,
};

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

const createOrg = vi.fn().mockResolvedValue(undefined);

function renderView() {
    return renderWithProviders(
        <MemoryRouter>
            <UIContext.Provider value={uiContext}>
                <ManagementOrgsView />
            </UIContext.Provider>
        </MemoryRouter>,
    );
}

async function openCreateDialog(eventUser: ReturnType<typeof userEvent.setup>) {
    await eventUser.click(screen.getByRole('button', { name: /Neue Organisation/ }));
    return screen.getByRole('dialog', { name: 'Neue Organisation anlegen' });
}

/**
 * Resolves the `.form-control` a label belongs to, so a field is reached by the
 * German text that introduces it rather than by index. The two text fields both
 * start empty, and picking the name field by "the one that is required" would
 * make the `required` assertion below it circular.
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

/**
 * The submit path is the form's `onSubmit`, so the tests submit the form
 * element itself rather than clicking the button. If `handleCreate` were ever
 * moved onto a button `onClick`, `fireEvent.submit` would stop reaching it and
 * these tests would fail — which is the point: a test that clicks the button
 * cannot tell those two wirings apart.
 */
function submitDialogForm(dialog: HTMLElement) {
    const submitButton = within(dialog).getByRole('button', { name: 'Speichern' });
    expect(submitButton).toHaveAttribute('type', 'submit');

    const form = submitButton.closest('form');
    if (form === null) {
        throw new Error('expected the dialog submit button to live inside a form element');
    }
    fireEvent.submit(form);
}

describe('ManagementOrgsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(usePermissions).mockReturnValue({ ...basePermissions, isAdmin: true });
        vi.mocked(useOrgs).mockReturnValue({
            orgs,
            org: undefined,
            isLoading: false,
            createOrg,
            updateOrg: vi.fn().mockResolvedValue(undefined),
            deleteOrg: vi.fn().mockResolvedValue(undefined),
            syncUsers: vi.fn().mockResolvedValue(undefined),
            syncGroups: vi.fn().mockResolvedValue(undefined),
            generateCollectiveInvoice: vi.fn().mockResolvedValue({
                success: true,
                invoice_number: 'SR-2026-001',
                processed_orders: 0,
            }),
        });
    });

    it('offers the create trigger only to admins, without hiding the org list', () => {
        vi.mocked(usePermissions).mockReturnValue({ ...basePermissions, isAdmin: false });

        renderView();

        // The list is still there, so the missing trigger is a permission gate
        // and not an empty page satisfying the assertion by accident.
        expect(screen.getByRole('heading', { name: 'Firma XYZ' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Neue Organisation/ })).not.toBeInTheDocument();
    });

    it('opens the create dialog as a named aria-modal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openCreateDialog(eventUser);

        // One assertion covers role, aria-labelledby wiring and the name text.
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        // The view passes no `onDelete` on purpose: an existing organisation
        // is deleted from the detail view, so this dialog must not offer it.
        expect(within(dialog).queryByRole('button', { name: 'Löschen' })).not.toBeInTheDocument();

        await eventUser.keyboard('{Escape}');
        expect(screen.queryByRole('dialog', { name: 'Neue Organisation anlegen' })).not.toBeInTheDocument();
        expect(createOrg).not.toHaveBeenCalled();
    });

    it('creates the organisation from the form onSubmit with every field the selects hold', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openCreateDialog(eventUser);

        await eventUser.type(within(fieldOf(dialog, 'Name (z.B. Firma XYZ)')).getByRole('textbox'), 'Firma ABC');
        await eventUser.type(within(fieldOf(dialog, 'Auto-Join Domain')).getByRole('textbox'), 'firma-abc.de');
        await eventUser.selectOptions(within(dialog).getByDisplayValue('Keine Flatrate'), 'print');
        await eventUser.selectOptions(within(dialog).getByDisplayValue('Sofort (automatisch)'), 'requires_invite');

        submitDialogForm(dialog);

        expect(createOrg).toHaveBeenCalledWith({
            name: 'Firma ABC',
            domain: 'firma-abc.de',
            invoice_frequency: 'immediate',
            default_flatrate_level: 'print',
            auto_join_policy: 'requires_invite',
        });

        await waitFor(() => {
            expect(uiContext.showToast).toHaveBeenCalledWith('success', 'Organisation erstellt');
            expect(screen.queryByRole('dialog', { name: 'Neue Organisation anlegen' })).not.toBeInTheDocument();
        });
    });

    it('maps a blank domain to null and keeps the create defaults', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openCreateDialog(eventUser);

        await eventUser.type(within(fieldOf(dialog, 'Name (z.B. Firma XYZ)')).getByRole('textbox'), 'Firma ohne Domain');
        submitDialogForm(dialog);

        // `domain: newDomain || null` — sending the empty string instead would be
        // a silent backend contract break, so the null is pinned here.
        expect(createOrg).toHaveBeenCalledWith({
            name: 'Firma ohne Domain',
            domain: null,
            invoice_frequency: 'immediate',
            default_flatrate_level: 'none',
            auto_join_policy: 'immediate',
        });

        await waitFor(() => {
            expect(uiContext.showToast).toHaveBeenCalledWith('success', 'Organisation erstellt');
        });
    });

    it('closes on cancel without creating anything', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openCreateDialog(eventUser);

        await eventUser.type(within(fieldOf(dialog, 'Name (z.B. Firma XYZ)')).getByRole('textbox'), 'Wird nie angelegt');
        await eventUser.click(within(dialog).getByRole('button', { name: 'Abbrechen' }));

        expect(screen.queryByRole('dialog', { name: 'Neue Organisation anlegen' })).not.toBeInTheDocument();
        expect(createOrg).not.toHaveBeenCalled();
        expect(uiContext.showToast).not.toHaveBeenCalled();
    });

    it('keeps the German field labels, the required name and native validation', async () => {
        const eventUser = userEvent.setup();

        renderView();
        const dialog = await openCreateDialog(eventUser);

        expect(within(dialog).getByText('Name (z.B. Firma XYZ)')).toBeInTheDocument();
        expect(within(dialog).getByText('Auto-Join Domain')).toBeInTheDocument();
        // The "ohne @" hint is the only thing telling the user the value is
        // stored without the prefix; a rename drops it silently.
        expect(within(dialog).getByText('ohne @')).toBeInTheDocument();
        expect(within(dialog).getByText('Standard-Flatrate-Level')).toBeInTheDocument();
        expect(within(dialog).getByText('Auto-Join Policy')).toBeInTheDocument();

        // Field Label Policy: the required attribute is what appends the star.
        const nameInput = within(fieldOf(dialog, 'Name (z.B. Firma XYZ)')).getByRole('textbox');
        expect(nameInput).toBeRequired();
        // The domain is optional, and per the Field Label Policy it carries no
        // "required" either — so it gets no star and no "(optional)" suffix.
        const domainInput = within(fieldOf(dialog, 'Auto-Join Domain')).getByRole('textbox');
        expect(domainInput).not.toBeRequired();
        expect(domainInput).toHaveAttribute('placeholder', 'firma.de');

        // The view deliberately leaves native constraint validation on, so the
        // shell must not have introduced `noValidate`.
        expect(nameInput.closest('form')).not.toHaveAttribute('novalidate');

        expect(within(dialog).queryByText(/\(optional\)/i)).not.toBeInTheDocument();
    });
});
