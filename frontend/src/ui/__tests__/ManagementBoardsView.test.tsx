import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { renderWithProviders } from '../../test-setup';
import ManagementBoardsView from '../management/ManagementBoardsView';
import type { Permissions } from '../../logic/usePermissions';

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

const basePermissions: Permissions = {
    isStaff: true,
    isSuperAdmin: false,
    isAdmin: false,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: false,
    canAccessProductionBoard: false,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: false,
};

function LocationProbe() {
    const location = useLocation();
    return <div data-testid="location-search">{location.search}</div>;
}

function renderView(initialEntries: string[] = ['/boards']) {
    return renderWithProviders(
        <MemoryRouter initialEntries={initialEntries}>
            <LocationProbe />
            <ManagementBoardsView />
        </MemoryRouter>,
    );
}

describe('ManagementBoardsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders tabs for super admins and defaults to the projects board', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isSuperAdmin: true,
            isAdmin: true,
            canAccessProjectsBoard: true,
            canAccessProductionBoard: true,
        });
        renderView();

        expect(screen.getByText('Workflow')).toBeInTheDocument();
        expect(screen.getByText('Projekte')).toBeInTheDocument();
        expect(screen.getByText('Bildbearbeitung')).toBeInTheDocument();
        expect(screen.getByText('ProjectsBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProductionBoardMock')).not.toBeInTheDocument();
        expect(screen.getByTestId('location-search')).toHaveTextContent('tab=projects');
    });

    it('renders tabs for super admins and defaults to the projects board', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isSuperAdmin: true,
            isAdmin: true,
            canAccessProjectsBoard: true,
            canAccessProductionBoard: true,
        });
        renderView(['/boards?tab=projects']);

        expect(screen.getByText('ProjectsBoardMock')).toBeInTheDocument();
        expect(screen.getByTestId('location-search')).toHaveTextContent('tab=projects');
    });

    it('switches to the production board when the tab is clicked', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isSuperAdmin: true,
            isAdmin: true,
            canAccessProjectsBoard: true,
            canAccessProductionBoard: true,
        });
        renderView();

        fireEvent.click(screen.getByText('Bildbearbeitung'));

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
    });

    it('renders the production board when the URL already selects the tab', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isSuperAdmin: true,
            isAdmin: true,
            canAccessProjectsBoard: true,
            canAccessProductionBoard: true,
        });
        renderView(['/boards?tab=production']);

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
    });

    it('hides tabs for non-super admins but still renders the default board', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isAdmin: true,
            canAccessProjectsBoard: true,
        });
        renderView();

        expect(screen.queryByText('Workflow')).not.toBeInTheDocument();
        expect(screen.queryByText('Projekte')).not.toBeInTheDocument();
        expect(screen.queryByText('Bildbearbeitung')).not.toBeInTheDocument();
        expect(screen.getByText('ProjectsBoardMock')).toBeInTheDocument();
    });

    it('defaults photographers to the production board when /boards is opened directly (no tab param)', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isPhotographer: true,
            canAccessProductionBoard: true,
        });
        renderView();

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
        expect(screen.queryByText('Kein Zugriff auf dieses Board.')).not.toBeInTheDocument();
        expect(screen.getByTestId('location-search')).toHaveTextContent('tab=production');
    });

    it('normalizes the URL when a photographer requests the inaccessible projects tab', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isPhotographer: true,
            canAccessProductionBoard: true,
        });
        renderView(['/boards?tab=projects']);

        expect(screen.getByText('ProductionBoardMock')).toBeInTheDocument();
        expect(screen.queryByText('ProjectsBoardMock')).not.toBeInTheDocument();
        expect(screen.getByTestId('location-search')).toHaveTextContent('tab=production');
    });
});
