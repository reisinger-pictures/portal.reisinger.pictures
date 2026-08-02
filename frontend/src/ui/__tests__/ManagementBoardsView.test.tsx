import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { renderWithProviders } from '../../test-setup';
import ManagementBoardsView from '../management/ManagementBoardsView';

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../management/ManagementProjectsBoard', () => ({
    default: () => <div>ProjectsBoardMock</div>,
}));

vi.mock('../photographer/PhotographerProductionBoard', () => ({
    default: () => <div>ProductionBoardMock</div>,
}));

import { usePermissions } from '../../logic/usePermissions';

function renderView(initialEntries: string[] = ['/boards']) {
    return renderWithProviders(
        <MemoryRouter initialEntries={initialEntries}>
            <ManagementBoardsView />
        </MemoryRouter>,
    );
}

describe('ManagementBoardsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders tabs for super admins and defaults to the projects board', () => {
        vi.mocked(usePermissions).mockReturnValue({ isSuperAdmin: true } as never);
        renderView();

        expect(screen.getByText('Workflow')).toBeInTheDocument();
        expect(screen.getByText('Projekte')).toBeInTheDocument();
        expect(screen.getByText('Bildbearbeitung')).toBeInTheDocument();
        expect(screen.getByText('ProjectsBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProductionBoardMock')).not.toBeInTheDocument();
    });

    it('switches to the production board when the tab is clicked', () => {
        vi.mocked(usePermissions).mockReturnValue({ isSuperAdmin: true } as never);
        renderView();

        fireEvent.click(screen.getByText('Bildbearbeitung'));

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
    });

    it('renders the production board when the URL already selects the tab', () => {
        vi.mocked(usePermissions).mockReturnValue({ isSuperAdmin: true } as never);
        renderView(['/boards?tab=production']);

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
    });

    it('hides tabs for non-super admins but still renders the default board', () => {
        vi.mocked(usePermissions).mockReturnValue({ isSuperAdmin: false } as never);
        renderView();

        expect(screen.queryByText('Workflow')).not.toBeInTheDocument();
        expect(screen.queryByText('Projekte')).not.toBeInTheDocument();
        expect(screen.queryByText('Bildbearbeitung')).not.toBeInTheDocument();
        expect(screen.getByText('ProjectsBoardMock')).toBeInTheDocument();
    });
});
