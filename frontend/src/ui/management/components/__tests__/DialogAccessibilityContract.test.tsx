import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import UserPermissionsModal from '../UserPermissionsModal';
import TextSnippetModal from '../TextSnippetModal';
import ModelInviteDialog from '../ModelInviteDialog';
import ProductModal from '../ProductModal';
import CustomerModal from '../CustomerModal';
import CreateUserModal from '../CreateUserModal';
import ProjectModal from '../ProjectModal';
import CouponFormDrawer from '../CouponFormDrawer';
import { UIContext, type UIContextType } from '../../../components/UIContext';
import { UserRole, type UserDetailed } from '../../../../logic/useUsers';

// The shared dialog contract, asserted for the dialogs that used to hand-roll
// their own `modal-box`.
//
// Seven of these eleven already had `role="dialog"`, an accessible name and a
// focus trap, so for them this file is a regression guard, not a fix: it pins
// the contract they already had so the migration onto ModalShell /
// ModalDialogShell cannot quietly drop it. The other four
// (ModelInviteDialog, CouponFormDrawer and the two inline view dialogs covered
// in the view suites) had *none* of it, and that is what this migration exists
// to correct.
//
// Two things are asserted per dialog, deliberately:
//
//   1. The dialog is named. `getByRole('dialog', { name })` only resolves if
//      `role="dialog"` and `aria-labelledby` both reach the title, so one
//      assertion covers the role, the wiring and the name text.
//   2. Escape reaches the caller's `onClose`. A named but inert dialog is the
//      failure mode that survives a role check, and it is the one users hit.
//
// The focus trap itself is not re-asserted here: ModalShell.test.tsx and
// ModalDialogShell.test.tsx own that, and repeating it per consumer would only
// test the shell eleven more times.

const user: UserDetailed = {
    id: 'user-1',
    name: 'Test User',
    email: 'test@example.com',
    is_super_admin: false,
    is_admin: true,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    flatrate_level: 'none',
    roles: [],
    gallery_groups: [],
    galleries: [],
};

const statusOptions = [{ value: 'anfrage', label: 'Anfrage' }];

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

// The real editor pulls in TipTap; the dialog contract under test does not
// depend on it, and the suite that does exercise the editor mocks it the same
// way (see TextSnippetModal.test.tsx).
vi.mock('../../../components/WysiwygEditor', () => ({
    default: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
        <textarea aria-label="Inhalt (HTML)" value={value} onChange={event => onChange(event.target.value)} />
    ),
}));

vi.mock('../../../../logic/useUsers', () => ({
    useUsers: vi.fn(() => ({ users: [] })),
    UserRole: { ADMIN: 'admin', SUPER_ADMIN: 'super_admin' },
}));

vi.mock('swr', () => ({
    default: vi.fn(() => ({ data: undefined, error: undefined, isLoading: false, mutate: vi.fn() })),
    mutate: vi.fn(),
}));

function renderWithUI(node: React.ReactNode) {
    return renderWithProviders(<UIContext.Provider value={uiContext}>{node}</UIContext.Provider>);
}

describe('shared dialog contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('names the UserPermissionsModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(
            <UserPermissionsModal
                user={user}
                roles={[{ id: 'role-1', name: UserRole.ADMIN }]}
                flatGroups={[]}
                flatGalleries={[]}
                onClose={onClose}
                onSave={vi.fn()}
            />,
        );

        const dialog = screen.getByRole('dialog', { name: 'Test User bearbeiten' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the TextSnippetModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<TextSnippetModal isOpen onClose={onClose} onSave={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Neuen Textbaustein anlegen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the ModelInviteDialog dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<ModelInviteDialog onClose={onClose} />);

        // This dialog had no role, no accessible name, no focus trap and no
        // Escape handling before the migration; every clause below is new.
        const dialog = screen.getByRole('dialog', { name: 'Einladung erstellen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the ProductModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<ProductModal isOpen onClose={onClose} onSave={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Neuen Eintrag anlegen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the CustomerModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<CustomerModal isOpen onClose={onClose} onSave={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Neuen Kunden anlegen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the CreateUserModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<CreateUserModal isOpen onClose={onClose} onCreate={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Neuen Nutzer einladen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the ProjectModal dialog and closes it on Escape', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(
            <ProjectModal isOpen onClose={onClose} defaultStatus="anfrage" statusOptions={statusOptions} onSave={vi.fn()} />,
        );

        const dialog = screen.getByRole('dialog', { name: 'Neues Projekt anlegen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('names the CouponFormDrawer dialog and routes Escape through the dirty-check', async () => {
        const eventUser = userEvent.setup();
        const onClose = vi.fn();
        renderWithUI(<CouponFormDrawer isOpen onClose={onClose} onSave={vi.fn()} />);

        const dialog = screen.getByRole('dialog', { name: 'Neuen Rabattcode anlegen' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        // Escape is not plain `onClose` here: the drawer asks before discarding
        // a dirty form. With a pristine form it closes directly, which is what
        // this asserts — and `CouponFormDrawer.test.tsx` covers the dirty
        // confirmation on the cancel button.
        await eventUser.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
        expect(uiContext.confirm).not.toHaveBeenCalled();
    });
});
