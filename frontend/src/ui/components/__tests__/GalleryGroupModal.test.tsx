import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../../test-setup';
import userEvent from '@testing-library/user-event';
import GalleryGroupModal from '../GalleryGroupModal';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../UIContext', () => ({
    useUI: () => ({ showToast: vi.fn(), confirm: vi.fn().mockResolvedValue(true) }),
}));

vi.mock('../ModalDialogShell', () => ({
    default: ({children, onSubmit}: {children: React.ReactNode; onSubmit: (e: React.FormEvent) => void}) => (
        <form onSubmit={onSubmit}>
            {children}
            <button type="submit">Speichern</button>
        </form>
    ),
}));

const orgs = [
    {id: 'org-1', name: 'Org Eins', domain: null, invoice_frequency: 'immediate'},
    {id: 'org-2', name: 'Org Zwei', domain: null, invoice_frequency: 'immediate'},
];

function setupSwr() {
    vi.mocked(useSWR).mockReturnValue({
        data: orgs,
        error: undefined,
        isLoading: false,
        mutate: vi.fn(),
    } as never);
}

describe('GalleryGroupModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupSwr();
    });

    it('passes the selected org_id to onCreate', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);
        const onUpdate = vi.fn().mockResolvedValue(undefined);
        const onDelete = vi.fn().mockResolvedValue(undefined);

        const {container} = renderWithProviders(
            <GalleryGroupModal
                isOpen
                onClose={vi.fn()}
                availableGroups={[]}
                onCreate={onCreate}
                onUpdate={onUpdate}
                onDelete={onDelete}
            />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Mein Ordner');

        const orgSelect = container.querySelector('select[name="org_id"]') as HTMLSelectElement;
        await user.selectOptions(orgSelect, 'org-1');

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        expect(onCreate).toHaveBeenCalledTimes(1);
        const extraOpts = onCreate.mock.calls[0][4] as {org_id?: string | null};
        expect(extraOpts.org_id).toBe('org-1');
    });

    it('passes org_id null when no organisation is selected', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);

        const {container} = renderWithProviders(
            <GalleryGroupModal
                isOpen
                onClose={vi.fn()}
                availableGroups={[]}
                onCreate={onCreate}
                onUpdate={vi.fn().mockResolvedValue(undefined)}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Ohne Org');

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        expect(onCreate).toHaveBeenCalledTimes(1);
        const extraOpts = onCreate.mock.calls[0][4] as {org_id?: string | null};
        expect(extraOpts.org_id).toBeNull();
    });
});
