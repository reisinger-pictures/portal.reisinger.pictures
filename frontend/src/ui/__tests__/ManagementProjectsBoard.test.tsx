import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import ManagementProjectsBoard from '../management/ManagementProjectsBoard';

vi.mock('../../logic/useProjectsBoard', () => ({
    useProjectsBoard: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../logic/useProjectPdfDrop', () => ({
    useProjectPdfDrop: () => ({ isDragging: false, isExtracting: false, handleDragOver: vi.fn(), handleDragLeave: vi.fn(), handleDrop: vi.fn() }),
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
            {items.map(item => <div key={item.id} data-testid="project-card">{renderCard(item)}</div>)}
        </div>
    ),
}));

vi.mock('../management/components/ProjectModal', () => ({
    default: () => <div>ProjectModalMock</div>,
}));

import { useProjectsBoard } from '../../logic/useProjectsBoard';
import { usePermissions } from '../../logic/usePermissions';
import { useUI } from '../components/UIContext';

const owner = { id: 'u1', name: 'Florian' };
const unlinkedProject = {
    id: 'p1',
    status: 'anfrage',
    position: 0,
    owner,
    assignee: null,
    created_at: '2026-08-02T10:00:00Z',
    client_name: 'Muster GmbH',
    email: '',
    phone: null,
    package: null,
    price_cents: 0,
    payment_status: 'open',
    linked_photo_job_id: null,
};
const linkedProject = {
    ...unlinkedProject,
    id: 'p2',
    client_name: 'Beispiel AG',
    linked_photo_job_id: 'pj-1',
};

function setupMocks(overrides: Record<string, unknown> = {}) {
    const handoff = vi.fn().mockResolvedValue(undefined);
    const showToast = vi.fn();
    const confirm = vi.fn().mockResolvedValue(true);

    vi.mocked(usePermissions).mockReturnValue({
        canAccessProjectsBoard: true,
        isSuperAdmin: true,
    } as never);

    vi.mocked(useProjectsBoard).mockReturnValue({
        projects: [unlinkedProject, linkedProject],
        isLoading: false,
        error: undefined,
        create: vi.fn(),
        update: vi.fn(),
        move: vi.fn(),
        remove: vi.fn(),
        handoff,
        ...overrides,
    });

    vi.mocked(useUI).mockReturnValue({
        showToast,
        confirm,
        hasUnsavedChanges: false,
        setUnsavedChanges: vi.fn(),
    });

    return { handoff, showToast, confirm };
}

describe('ManagementProjectsBoard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupMocks();
    });

    it('renders the Storniert column', () => {
        renderWithProviders(<ManagementProjectsBoard />);
        expect(screen.getByText('Storniert')).toBeInTheDocument();
    });

    it('renders a handoff button for super admins when the project is not linked', () => {
        renderWithProviders(<ManagementProjectsBoard />);
        expect(screen.getByText('In Bildbearbeitung übernehmen')).toBeInTheDocument();
    });

    it('renders the Übernommen badge when the project is already linked', () => {
        renderWithProviders(<ManagementProjectsBoard />);
        expect(screen.getByText('Übernommen')).toBeInTheDocument();
    });

    it('calls handoff and shows a success toast when the handoff button is clicked', async () => {
        const user = userEvent.setup();
        const { handoff, showToast } = setupMocks();

        renderWithProviders(<ManagementProjectsBoard />);
        await user.click(screen.getByText('In Bildbearbeitung übernehmen'));

        expect(handoff).toHaveBeenCalledWith('p1');
        expect(showToast).toHaveBeenCalledWith('success', expect.stringContaining('übernommen'));
    });

    it('does not render the handoff button for non-super admins', () => {
        vi.mocked(usePermissions).mockReturnValue({
            canAccessProjectsBoard: true,
            isSuperAdmin: false,
        } as never);

        renderWithProviders(<ManagementProjectsBoard />);
        expect(screen.queryByText('In Bildbearbeitung übernehmen')).not.toBeInTheDocument();
    });
});
