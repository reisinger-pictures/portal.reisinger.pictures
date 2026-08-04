import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import PhotographerProductionBoard from '../photographer/PhotographerProductionBoard';

vi.mock('../../logic/useProductionBoard', () => ({
    useProductionBoard: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn(), confirm: vi.fn().mockResolvedValue(true) }),
}));

vi.mock('../components/KanbanBoard', () => ({
    default: ({ title, columns, items, renderCard }: {
        title: string;
        columns: Array<{ status: string; label: string }>;
        items: Array<{ id: string; status: string }>;
        renderCard: (item: { id: string; status: string }) => React.ReactNode;
    }) => (
        <div>
            <h1>{title}</h1>
            {columns.map(c => <div key={c.status}>{c.label}</div>)}
            {items.map(item => <div key={item.id} data-testid="job-card">{renderCard(item)}</div>)}
        </div>
    ),
}));

import { useProductionBoard } from '../../logic/useProductionBoard';
import { usePermissions } from '../../logic/usePermissions';

const owner = { id: 'u1', name: 'Florian' };
const job = {
    id: 'j1',
    status: 'importiert',
    position: 0,
    owner,
    assignee: null,
    created_at: '2026-08-02T10:00:00Z',
    title: 'Hochzeit Sommer',
    lightroom_catalog: null,
    lightroom_catalog_is_mine: false,
    total_count: 0,
    selected_count: 0,
    target_gallery_id: null,
};

function jobWith(overrides: Record<string, unknown>) {
    return { ...job, ...overrides };
}

describe('PhotographerProductionBoard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(usePermissions).mockReturnValue({
            canAccessProductionBoard: true,
            isSuperAdmin: false,
        } as never);
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [job],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);
    });

    it('renders the production board title', () => {
        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText('Bildbearbeitung')).toBeInTheDocument();
    });

    it('renders the Importiert, Culling, Bearbeitung, Exportiert and Abgebrochen columns', () => {
        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText('Importiert', { selector: 'div' })).toBeInTheDocument();
        expect(screen.getByText('Culling', { selector: 'div' })).toBeInTheDocument();
        expect(screen.getByText('Bearbeitung', { selector: 'div' })).toBeInTheDocument();
        expect(screen.getByText('Exportiert', { selector: 'div' })).toBeInTheDocument();
        expect(screen.getByText('Abgebrochen', { selector: 'div' })).toBeInTheDocument();
        expect(screen.queryByText('Veröffentlicht', { selector: 'div' })).not.toBeInTheDocument();
    });

    it('renders a job card when data is present', () => {
        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText('Hochzeit Sommer')).toBeInTheDocument();
    });

    it('shows the catalog name when it belongs to the viewer', () => {
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ lightroom_catalog: '2026-08', lightroom_catalog_is_mine: true })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText('2026-08')).toBeInTheDocument();
    });

    it('hides the catalog name for foreign catalogs', () => {
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ lightroom_catalog: 'Fremder Katalog', lightroom_catalog_is_mine: false })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.queryByText('Fremder Katalog')).not.toBeInTheDocument();
    });

    it('shows the catalog name to super admins even for foreign catalogs', () => {
        vi.mocked(usePermissions).mockReturnValue({
            canAccessProductionBoard: true,
            isSuperAdmin: true,
        } as never);
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ lightroom_catalog: 'Fremder Katalog', lightroom_catalog_is_mine: false })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);
        const badgeContent = screen.getByText('Fremder Katalog');
        expect(badgeContent.closest('.badge')).not.toBeNull();
    });

    it('shows a spinner while loading', () => {
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: undefined,
            isLoading: true,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);
        renderWithProviders(<PhotographerProductionBoard />);
        expect(document.querySelector('.loading-spinner')).toBeInTheDocument();
    });

    it('blocks access without permission', () => {
        vi.mocked(usePermissions).mockReturnValue({
            canAccessProductionBoard: false,
        } as never);
        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText(/Kein Zugriff/i)).toBeInTheDocument();
    });

    it('renders the Lightroom catalog as a badge when it belongs to the viewer', () => {
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ lightroom_catalog: '2026-08', lightroom_catalog_is_mine: true })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);

        const badgeContent = screen.getByText('2026-08');
        expect(badgeContent.closest('.badge')).not.toBeNull();
    });

    it('renders a status select on each job card and moves the job to the selected column', async () => {
        const user = userEvent.setup();
        const move = vi.fn().mockResolvedValue(undefined);
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [job],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move,
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);

        const selects = screen.getAllByLabelText('Status ändern');
        expect(selects).toHaveLength(1);

        await user.selectOptions(selects[0], 'exportiert');

        expect(move).toHaveBeenCalledWith('j1', 'exportiert', 0);
    });

    it('renders the note preview with the full note as tooltip', () => {
        const notes = 'Dringend: Raw-Dateien sichern';
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ notes })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);

        const notePreview = screen.getByText(notes);
        expect(notePreview).toHaveClass('tooltip');
        expect(notePreview).toHaveAttribute('data-tip', notes);
    });

    it('renders the assignee as a badge with priority over the owner', () => {
        vi.mocked(useProductionBoard).mockReturnValue({
            photoJobs: [jobWith({ assignee: { id: 'u9', name: 'Sarah' } })],
            isLoading: false,
            error: undefined,
            create: vi.fn(),
            update: vi.fn(),
            move: vi.fn(),
            remove: vi.fn(),
        } as never);

        renderWithProviders(<PhotographerProductionBoard />);

        const badgeContent = screen.getByText('Sarah');
        expect(badgeContent.closest('.badge')).not.toBeNull();
        expect(screen.queryByText('Florian')).not.toBeInTheDocument();
    });
});