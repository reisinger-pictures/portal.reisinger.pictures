import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { renderWithProviders } from '../../../test-setup';
import { useProtectedGalleries } from '../../../logic/useGalleries';
import { useDashboard } from '../DashboardContext';
import { UIContext, type UIContextType } from '../UIContext';
import DashboardLayout from '../DashboardLayout';

// D-10: the gallery dialogs must render INSIDE the `<main>` landmark.
//
// The defect is invisible on screen and fatal to every landmark-scoped locator.
// `ModalShell` opens a plain `<dialog open>` and uses no portal, so while
// `<GalleryModals>` was rendered as a sibling AFTER `</main>`, the dialogs were
// on the page but not descendants of the landmark. The repo's E2E convention
// scopes through landmarks (`page.locator('main').getByRole(...)`), so those
// locators could not see the dialog at all — `click()` failed on an element
// that was plainly rendered. The owner's decision was to change the structure
// rather than weaken the scoping rule, so the invariant lives here.
//
// The trap this test has to avoid: asserting that a `.modal-open` element
// exists. `GalleryGroupModal`/`GalleryModal` return `null` while closed, so a
// mere existence check is satisfied by a broken implementation that never opens
// anything. Every assertion below therefore drives a real open first, through
// the same `DashboardContext` callbacks the structure view uses.

vi.mock('../../../logic/useGalleries', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useGalleries')>()),
    useProtectedGalleries: vi.fn(),
}));

// The sidebar is not under test and pulls in auth, permissions, brand, cart and
// the router. The dialogs are.
vi.mock('../Sidebar', () => ({
    default: () => <div data-testid="sidebar" />,
}));

const noop = vi.fn().mockResolvedValue(undefined);

function mockGalleries() {
    vi.mocked(useProtectedGalleries).mockReturnValue({
        tree: {
            groups: [
                { id: 'g1', name: 'Print', parent_id: null, children: [] },
                { id: 'g2', name: 'Hochzeit', parent_id: 'g1', children: [] },
            ],
            root_galleries: [],
        },
        isLoading: false,
        isError: undefined,
        mutate: vi.fn().mockResolvedValue(undefined),
        createGroup: noop,
        createGallery: noop,
        updateGroup: noop,
        updateGallery: noop,
        deleteGroup: noop,
        deleteGallery: noop,
    });
}

/**
 * Renders the layout with a child that drives the two dialog openers, mirroring
 * how `ManagementStructureView` reaches them: through `DashboardContext`, not
 * through props. That indirection is the point — a test that passed the opener
 * down as a prop would not exercise the wiring the real views use.
 */
function Openers() {
    const { onOpenGalleryModal, onOpenGroupModal } = useDashboard();
    return (
        <>
            <button type="button" onClick={() => onOpenGroupModal()}>
                Neuer Ordner
            </button>
            <button type="button" onClick={() => onOpenGalleryModal()}>
                Neue Galerie
            </button>
        </>
    );
}

// Both gallery dialogs reach for `useUI` (toasts, the delete confirmation) as
// soon as they mount. None of that is under test here, but the provider has to
// be real or the components throw before they ever reach the DOM.
const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(false),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

function renderLayout(children: ReactNode = <Openers />) {
    return renderWithProviders(
        <UIContext.Provider value={uiContext}>
            <DashboardLayout currentView="structure">{children}</DashboardLayout>
        </UIContext.Provider>,
    );
}

describe('DashboardLayout', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockGalleries();
    });

    it('renders the group dialog inside the main landmark once it is opened', async () => {
        const user = userEvent.setup();
        renderLayout();

        await user.click(screen.getByRole('button', { name: 'Neuer Ordner' }));

        const dialog = screen.getByRole('dialog');
        // Scoped the same way an E2E locator scopes it. This is the assertion
        // that fails if the dialog is ever moved back out of `<main>`: the
        // landmark query resolves to nothing and `getByRole` inside it throws.
        expect(within(screen.getByRole('main')).getByRole('dialog')).toBe(dialog);
    });

    it('renders the gallery dialog inside the main landmark once it is opened', async () => {
        const user = userEvent.setup();
        renderLayout();

        await user.click(screen.getByRole('button', { name: 'Neue Galerie' }));

        const dialog = screen.getByRole('dialog');
        expect(within(screen.getByRole('main')).getByRole('dialog')).toBe(dialog);
    });

    it('keeps the sidebar outside the main landmark', () => {
        // The mirror image of the two assertions above, and the reason they
        // cannot pass by accident: a dialog rendered as a sibling of `<main>`
        // would satisfy `getByRole('dialog')` while failing the scoped query.
        renderLayout();

        expect(within(screen.getByRole('main')).queryByTestId('sidebar')).not.toBeInTheDocument();
        expect(screen.getByTestId('sidebar')).toBeInTheDocument();
    });

    it('renders no dialog at all while both are closed', () => {
        // Not an existence check — the complement of it. Both dialogs return
        // `null` when closed, so "there is a dialog" and "there is a dialog
        // once opened" are different claims and only the pair is meaningful.
        renderLayout();

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
