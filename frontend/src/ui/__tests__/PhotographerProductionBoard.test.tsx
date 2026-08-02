import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
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
    status: 'shooting',
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
    is_private: false,
};

function jobWith(overrides: Record<string, unknown>) {
    return { ...job, ...overrides };
}

describe('PhotographerProductionBoard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(usePermissions).mockReturnValue({
            canAccessProductionBoard: true,
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

    it('renders the Abgebrochen column', () => {
        renderWithProviders(<PhotographerProductionBoard />);
        expect(screen.getByText('Abgebrochen')).toBeInTheDocument();
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
});