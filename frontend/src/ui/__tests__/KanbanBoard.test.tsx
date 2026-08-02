import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import KanbanBoard from '../components/KanbanBoard';

vi.mock('@dnd-kit/react', () => {
    const MockDragDropProvider = ({ children }: { children: React.ReactNode }) => <>{children}</>;
    const MockDragOverlay = ({ children }: { children: (source: unknown) => React.ReactNode }) => children(null);
    const useDroppable = () => ({ ref: undefined, isDropTarget: false });
    return { DragDropProvider: MockDragDropProvider, DragOverlay: MockDragOverlay, useDroppable };
});

vi.mock('@dnd-kit/react/sortable', () => ({
    useSortable: () => ({ ref: undefined, isDragging: false, isDropTarget: false }),
}));

const columns = [
    { status: 'anfrage', label: 'Anfrage' },
    { status: 'angebot', label: 'Angebot' },
];

const items = [
    { id: 'p1', status: 'anfrage', position: 0 },
    { id: 'p2', status: 'angebot', position: 1 },
];

function renderBoard(overrides: Record<string, unknown> = {}) {
    return renderWithProviders(
        <KanbanBoard
            title="Projekte"
            columns={columns}
            items={items}
            onMove={vi.fn()}
            renderCard={(item) => <div>Karte {item.id}</div>}
            renderColumnHeader={(_status, label) => <div>{label}</div>}
            {...overrides}
        />,
    );
}

describe('KanbanBoard', () => {
    it('renders column headers and cards', () => {
        renderBoard();

        expect(screen.getByText('Anfrage')).toBeInTheDocument();
        expect(screen.getByText('Angebot')).toBeInTheDocument();
        expect(screen.getByText('Karte p1')).toBeInTheDocument();
        expect(screen.getByText('Karte p2')).toBeInTheDocument();
        expect(screen.getByText('Projekte')).toBeInTheDocument();
    });

    it('renders an empty placeholder for columns without items', () => {
        renderBoard({ items: [] });

        expect(screen.getAllByText('Leer')).toHaveLength(2);
        expect(screen.queryByText('Karte p1')).not.toBeInTheDocument();
    });

    it('sorts cards by position within a column', () => {
        const unsorted = [
            { id: 'b', status: 'anfrage', position: 1 },
            { id: 'a', status: 'anfrage', position: 0 },
        ];
        renderBoard({ items: unsorted });

        const columnBody = screen.getByText('Karte a').closest('.flex-1');
        expect(columnBody).not.toBeNull();
        const texts = Array.from(columnBody!.children).map(el => el.textContent);
        expect(texts).toEqual(['Karte a', 'Karte b']);
    });

    it('renders cards without drag handlers when disallowDrag is set', () => {
        renderBoard({ disallowDrag: true });

        expect(screen.getByText('Karte p1')).toBeInTheDocument();
        expect(screen.getByText('Karte p2')).toBeInTheDocument();
    });

    it('omits the board title when embedded', () => {
        renderBoard({ embedded: true });

        expect(screen.queryByText('Projekte')).not.toBeInTheDocument();
        expect(screen.getByText('Anfrage')).toBeInTheDocument();
        expect(screen.getByText('Karte p1')).toBeInTheDocument();
    });
});
