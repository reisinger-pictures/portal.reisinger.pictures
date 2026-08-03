import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import KanbanBoard from '../components/KanbanBoard';

const { MockDragDropProvider, MockIsSortable } = vi.hoisted(() => {
    const MockDragDropProvider = vi.fn(({ children }: { children: React.ReactNode }) => <>{children}</>);
    const MockIsSortable = vi.fn();
    return { MockDragDropProvider, MockIsSortable };
});

vi.mock('@dnd-kit/react', () => {
    const useDroppable = () => ({ ref: undefined, isDropTarget: false });
    return { DragDropProvider: MockDragDropProvider, useDroppable };
});

vi.mock('@dnd-kit/react/sortable', () => ({
    useSortable: () => ({ ref: undefined, isDragging: false, isDropTarget: false }),
    isSortable: MockIsSortable,
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

function getDragEndHandler() {
    const props = MockDragDropProvider.mock.calls[0][0] as {
        onDragEnd?: (event: unknown) => void;
    };
    const handler = props.onDragEnd;
    expect(handler).toBeDefined();
    return handler!;
}

function getDragStartHandler() {
    const props = MockDragDropProvider.mock.calls[0][0] as {
        onDragStart?: (event: unknown) => void;
    };
    const handler = props.onDragStart;
    expect(handler).toBeDefined();
    return handler!;
}

describe('KanbanBoard', () => {
    beforeEach(() => {
        MockDragDropProvider.mockClear();
        MockIsSortable.mockReset();
    });

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

    it('calls onMove with the sortable projected index and group on a same-column reorder', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });
        MockIsSortable.mockReturnValue(true);

        const onDragEnd = getDragEndHandler();
        const source = {
            data: { item: { id: 'p1', status: 'anfrage', position: 0 } },
            sortable: { index: 2, group: 'anfrage' },
        };
        onDragEnd({ canceled: false, operation: { source, target: { id: 'p2', data: { kind: 'card' } } } });

        expect(onMove).toHaveBeenCalledWith('p1', 'anfrage', 2);
    });

    it('calls onMove with the target column and position 0 when dropped into an empty column', () => {
        const onMove = vi.fn();
        renderBoard({ onMove, items: [{ id: 'p1', status: 'anfrage', position: 0 }] });

        const onDragEnd = getDragEndHandler();
        const source = {
            data: { item: { id: 'p1', status: 'anfrage', position: 0 } },
            sortable: { index: 0, group: 'anfrage' },
        };
        onDragEnd({ canceled: false, operation: { source, target: { id: 'angebot', data: { kind: 'column' } } } });

        expect(onMove).toHaveBeenCalledWith('p1', 'angebot', 0);
        expect(MockIsSortable).not.toHaveBeenCalled();
    });

    it('restores the source element to its pre-drag parent on drag end (removeChild-Crash-Workaround)', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });
        MockIsSortable.mockReturnValue(true);

        const prevParent = document.createElement('div');
        const sourceElement = document.createElement('div');
        prevParent.appendChild(sourceElement);

        const onDragStart = getDragStartHandler();
        onDragStart({ operation: { source: { element: sourceElement } } });

        const targetColumn = document.createElement('div');
        targetColumn.appendChild(sourceElement);

        const onDragEnd = getDragEndHandler();
        onDragEnd({
            canceled: false,
            operation: {
                source: { element: sourceElement, data: { item: { id: 'p1', status: 'anfrage', position: 0 } }, sortable: { index: 0, group: 'angebot' } },
                target: { id: 'angebot', data: { kind: 'column' } },
            },
        });

        expect(sourceElement.parentElement).toBe(prevParent);
        expect(targetColumn.childElementCount).toBe(0);
        expect(onMove).toHaveBeenCalledWith('p1', 'angebot', 1);
    });

    it('does not call onMove for a canceled drop', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });

        const onDragEnd = getDragEndHandler();
        onDragEnd({ canceled: true, operation: { source: null, target: null } });

        expect(onMove).not.toHaveBeenCalled();
    });
});
