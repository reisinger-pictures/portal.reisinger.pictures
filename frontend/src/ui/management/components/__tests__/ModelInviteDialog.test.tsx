import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import ModelInviteDialog from '../ModelInviteDialog';
import { UIContext, type UIContextType } from '../../../components/UIContext';
import type { ModelInvite } from '../../../../logic/useModelInvites';

/**
 * The E2E contract of `data-testid="model-invite-dialog"`.
 *
 * Eight assertions in `tests/e2e/crm/model-access.spec.ts` and
 * `model-registration.spec.ts` scope through that id: they click the
 * "Einladung erstellen" button inside it and read the invite table inside it.
 * When this dialog rendered a hand-rolled `.modal-box`, the id sat on the box.
 * Under ModalShell the box belongs to the shell, so the id had to move to a
 * wrapper `<div>` around the content — an element that exists only to carry a
 * testid — and that is the shape this file exists to keep from coming back.
 *
 * What matters is not where the id is but what it still reaches: an *enclosing*
 * element. Scoping a locator to a descendant of the id resolves the same nodes
 * whether the id sits on that wrapper or on the box, so the eight assertions
 * hold either way — which is why this has to be asserted here rather than left
 * to a Playwright run. What must not survive is the wrapper.
 */
vi.mock('../../../../logic/usePermissions', async importOriginal => {
    const actual = await importOriginal<typeof import('../../../../logic/usePermissions')>();
    return {
        ...actual,
        // Only the flag this dialog branches on; the create form is admin-only
        // and the "Einladung erstellen" button the E2E specs click lives in it.
        usePermissions: vi.fn(() => ({ isAdmin: true })),
    };
});

const invites: ModelInvite[] = [
    {
        id: 'inv-11',
        label: 'WhatsApp Anna',
        email: 'anna@example.de',
        link: 'https://example.de/i/abc',
        brand: null,
        status: 'open',
        expires_at: '2026-03-01T09:00:00Z',
        used_at: null,
        act_id: null,
        customer_id: null,
        invited_by: 'Max Mustermann',
        created_at: '2026-02-01T09:00:00Z',
    },
];

vi.mock('../../../../logic/useModelInvites', async importOriginal => {
    const actual = await importOriginal<typeof import('../../../../logic/useModelInvites')>();
    return {
        ...actual,
        useModelInvites: vi.fn(() => ({
            invites,
            error: null,
            isLoading: false,
            mutate: vi.fn(),
            createInvite: vi.fn(),
            revokeInvite: vi.fn(),
        })),
    };
});

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

function renderDialog() {
    return renderWithProviders(
        <UIContext.Provider value={uiContext}>
            <ModelInviteDialog onClose={vi.fn()} />
        </UIContext.Provider>,
    );
}

describe('ModelInviteDialog testid contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('hangs the testid on the dialog box and still encloses what E2E scopes to', () => {
        renderDialog();

        const scoped = screen.getByTestId('model-invite-dialog');

        // The box itself, not a stand-in for it: the `modal-box` class plus being
        // the dialog's first child is what distinguishes the hook's target from
        // the wrapper element this testid used to need.
        expect(scoped).toHaveClass('modal-box');
        expect(scoped).toBe(screen.getByRole('dialog').firstElementChild);

        // The two things the eight assertions reach, as descendants of the id.
        // Both are looked up the way a spec looks them up — by role, scoped —
        // because uniqueness inside the scope is part of the contract: an
        // enclosing box brings the header close button and the footer button
        // into scope that the wrapper never contained.
        expect(scoped).toContainElement(screen.getByRole('button', { name: 'Einladung erstellen' }));
        expect(scoped.querySelectorAll('table')).toHaveLength(1);
        expect(screen.getByRole('table')).toHaveTextContent('WhatsApp Anna');
    });

    it('adds no wrapper element around the dialog content', () => {
        // The reason the hook exists. With the id on a wrapper, the intro text,
        // the create form and the invite table sat one level deeper than the
        // shell's own body region; an element that only carries a testid is
        // invisible to the user and still has to be reasoned about by every
        // future edit of this dialog.
        renderDialog();

        const box = screen.getByTestId('model-invite-dialog');
        // The scrollable body is the one region the shell puts around the
        // children here (`scrollableBody` is set on this dialog).
        const body = box.querySelector('.overflow-y-auto') as HTMLElement;
        expect(body).not.toBeNull();
        // The intro paragraph is a direct child of that body, so no element
        // stands between the shell's region and the dialog's own content.
        expect(body.firstElementChild?.tagName).toBe('P');
        expect(body).toContainElement(screen.getByRole('button', { name: 'Einladung erstellen' }));
        // One element carries the id, not a box plus a wrapper.
        expect(document.querySelectorAll('[data-testid="model-invite-dialog"]')).toHaveLength(1);
    });
});
