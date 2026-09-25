import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../../test-setup';
import userEvent from '@testing-library/user-event';
import GalleryModal from '../GalleryModal';
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

function setupSwr() {
    vi.mocked(useSWR).mockImplementation(((key: unknown) => {
        if (key === '/api/management/orgs') {
            return {data: [], error: undefined, isLoading: false, mutate: vi.fn()};
        }
        if (key === '/api/management/settings/volume-presets') {
            return {data: {presets: []}, error: undefined, isLoading: false, mutate: vi.fn()};
        }
        return {data: undefined, error: undefined, isLoading: false, mutate: vi.fn()};
    }) as never);
}

describe('GalleryModal forced visibility', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupSwr();
    });

    it('submits the visibility forced by the parent meta-gallery', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);

        const availableGroups = [
            {id: 'g-public', name: 'Öffentlicher Parent', depth: 0, is_public: true},
        ];

        const {container} = renderWithProviders(
            <GalleryModal
                isOpen
                onClose={vi.fn()}
                onOpenGroupModal={vi.fn()}
                availableGroups={availableGroups}
                defaultGroupId="g-public"
                onCreate={onCreate}
                onUpdate={vi.fn().mockResolvedValue(undefined)}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Neue Galerie');

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        expect(onCreate).toHaveBeenCalledTimes(1);
        // Positional args: (name, slug, type, isLive, isPublic, ...)
        expect(onCreate.mock.calls[0][4]).toBe(true);
    });

    it('overrides a persisted public value when the parent forces private', async () => {
        const user = userEvent.setup();
        const onUpdate = vi.fn().mockResolvedValue(undefined);
        const editingGallery = {
            id: 'gallery-1',
            name: 'Bestehende Galerie',
            slug: 'bestehende-galerie',
            full_path: 'galleries/bestehende-galerie',
            type: 'delivery' as const,
            is_live: false,
            is_public: true,
            gallery_group_id: 'g-private',
        };

        renderWithProviders(
            <GalleryModal
                isOpen
                onClose={vi.fn()}
                onOpenGroupModal={vi.fn()}
                availableGroups={[
                    {id: 'g-private', name: 'Privater Parent', depth: 0, is_public: false},
                ]}
                editingGallery={editingGallery}
                onCreate={vi.fn().mockResolvedValue(undefined)}
                onUpdate={onUpdate}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const visibilitySelect = screen.getByLabelText('Sichtbarkeit');
        expect(visibilitySelect).toHaveValue('false');
        expect(visibilitySelect).toBeDisabled();

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        expect(onUpdate).toHaveBeenCalledTimes(1);
        expect(onUpdate.mock.calls[0][5]).toBe(false);
    });

    it('keeps the user-selected visibility when the parent does not force one', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);

        const availableGroups = [
            {id: 'g-inherit', name: 'Neutraler Parent', depth: 0, is_public: null},
        ];

        const {container} = renderWithProviders(
            <GalleryModal
                isOpen
                onClose={vi.fn()}
                onOpenGroupModal={vi.fn()}
                availableGroups={availableGroups}
                defaultGroupId="g-inherit"
                onCreate={onCreate}
                onUpdate={vi.fn().mockResolvedValue(undefined)}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Neue Galerie');
        await user.selectOptions(screen.getByLabelText('Sichtbarkeit'), 'true');

        await user.click(screen.getByRole('button', {name: 'Speichern'}));

        expect(onCreate).toHaveBeenCalledTimes(1);
        expect(onCreate.mock.calls[0][4]).toBe(true);
    });

    it('restores explicit public intent after the selection-only privacy rule is removed', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);

        renderWithProviders(
            <GalleryModal
                isOpen
                onClose={vi.fn()}
                onOpenGroupModal={vi.fn()}
                availableGroups={[]}
                onCreate={onCreate}
                onUpdate={vi.fn().mockResolvedValue(undefined)}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const visibilitySelect = screen.getByLabelText('Sichtbarkeit');
        const typeSelect = screen.getByLabelText('Galerie-Typ');
        await user.type(screen.getByLabelText('Name der Galerie'), 'Neue Galerie');
        await user.selectOptions(visibilitySelect, 'true');
        await user.selectOptions(typeSelect, 'selection');

        expect(visibilitySelect).toHaveValue('false');
        expect(visibilitySelect).toBeDisabled();

        await user.selectOptions(typeSelect, 'delivery');

        expect(visibilitySelect).toHaveValue('true');
        expect(visibilitySelect).toBeEnabled();
        await user.click(screen.getByRole('button', {name: 'Speichern'}));
        expect(onCreate.mock.calls[0][4]).toBe(true);
    });

    it('restores explicit private intent after a forcing parent is removed', async () => {
        const user = userEvent.setup();
        const onCreate = vi.fn().mockResolvedValue(undefined);
        const availableGroups = [
            {id: 'g-neutral', name: 'Neutraler Parent', depth: 0, is_public: null},
            {id: 'g-public', name: 'Öffentlicher Parent', depth: 0, is_public: true},
        ];

        const {container} = renderWithProviders(
            <GalleryModal
                isOpen
                onClose={vi.fn()}
                onOpenGroupModal={vi.fn()}
                availableGroups={availableGroups}
                defaultGroupId="g-neutral"
                onCreate={onCreate}
                onUpdate={vi.fn().mockResolvedValue(undefined)}
                onDelete={vi.fn().mockResolvedValue(undefined)}
            />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        const groupSelect = screen.getByLabelText('In welchem Ordner soll die Galerie liegen?');
        const visibilitySelect = screen.getByLabelText('Sichtbarkeit');
        await user.type(nameInput, 'Neue Galerie');
        await user.selectOptions(visibilitySelect, 'false');
        await user.selectOptions(groupSelect, 'g-public');

        expect(visibilitySelect).toHaveValue('true');
        expect(visibilitySelect).toBeDisabled();

        await user.selectOptions(groupSelect, 'g-neutral');

        expect(visibilitySelect).toHaveValue('false');
        expect(visibilitySelect).toBeEnabled();
        await user.click(screen.getByRole('button', {name: 'Speichern'}));
        expect(onCreate.mock.calls[0][4]).toBe(false);
    });
});
