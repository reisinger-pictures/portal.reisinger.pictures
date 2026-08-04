import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import KanbanBoard from '../components/KanbanBoard';
import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';

const { monitorArgs, dropTargetArgs } = vi.hoisted(() => {
    const monitorArgs: Array<Record<string, unknown>> = [];
    const dropTargetArgs: Array<Record<string, unknown>> = [];
    return { monitorArgs, dropTargetArgs };
});

vi.mock('@atlaskit/pragmatic-drag-and-drop/element/adapter', () => ({
    draggable: () => () => {},
    dropTargetForElements: (args: Record<string, unknown>) => {
        dropTargetArgs.push(args);
        return () => {};
    },
    monitorForElements: (args: Record<string, unknown>) => {
        monitorArgs.push(args);
        return () => {};
    },
}));

vi.mock('@atlaskit/pragmatic-drag-and-drop/combine', () => ({
    combine: (...fns: Array<() => void>) => () => fns.forEach(fn => fn()),
}));

vi.mock('@atlaskit/pragmatic-drag-and-drop/element/set-custom-native-drag-preview', () => ({
    setCustomNativeDragPreview: () => {},
}));

vi.mock('react-dom/client', () => ({
    createRoot: () => ({ render: () => {}, unmount: () => {} }),
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

function getMonitorHandler(key: string): (args: unknown) => void {
    const handler = monitorArgs[0]?.[key];
    expect(handler).toBeDefined();
    return handler as (args: unknown) => void;
}

function makeRect(overrides: Partial<DOMRect> = {}): DOMRect {
    const { height = 100, top = 0, ...rest } = overrides;
    const base = {
        x: 0, y: 0, top, left: 0, right: 0,
        bottom: top + height, width: 0, height,
    };
    return { ...base, ...rest, top, bottom: top + height, height } as DOMRect;
}

function elementWithRect(rect: DOMRect): Element {
    const el = document.createElement('div');
    el.getBoundingClientRect = () => rect;
    return el;
}

function makeInput(clientY: number) {
    return {
        altKey: false,
        button: 0,
        buttons: 1,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        clientX: 50,
        clientY,
        pageX: 50,
        pageY: clientY,
    };
}

/** Erzeugt Drop-Target-Data mit angehängter HitBox-Kante (top/bottom) wie zur Laufzeit. */
function cardTargetData(itemId: string, status: string, inputY: number) {
    const el = elementWithRect(makeRect());
    return attachClosestEdge(
        { kind: 'card', itemId, status },
        { input: makeInput(inputY), element: el, allowedEdges: ['top', 'bottom'] as const },
    );
}

function dropLocation(dropTargets: unknown[]) {
    return {
        current: { dropTargets, input: { clientY: 100 } },
        previous: {},
    };
}

/** Registrierte Drop-Targets der Karten (outer-Ref hat data-testid="kanban-card"). */
function cardDropTargets() {
    return dropTargetArgs.filter(args =>
        args.element instanceof HTMLElement &&
        args.element.getAttribute('data-testid') === 'kanban-card',
    );
}

/** Registrierte Drop-Targets der Spalten-Bodys (flex-1, ohne Karten-testid). */
function columnDropTargets() {
    return dropTargetArgs.filter(args =>
        args.element instanceof HTMLElement &&
        args.element.getAttribute('data-testid') !== 'kanban-card' &&
        args.element.classList.contains('flex-1'),
    );
}

function cardTargetForText(text: string) {
    const target = cardDropTargets().find(args =>
        args.element instanceof HTMLElement && args.element.textContent?.includes(text),
    );
    expect(target).toBeDefined();
    return target!;
}

describe('KanbanBoard', () => {
    beforeEach(() => {
        monitorArgs.length = 0;
        dropTargetArgs.length = 0;
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
        expect(monitorArgs).toHaveLength(0);
        expect(dropTargetArgs).toHaveLength(0);
    });

    it('omits the board title when embedded', () => {
        renderBoard({ embedded: true });

        expect(screen.queryByText('Projekte')).not.toBeInTheDocument();
        expect(screen.getByText('Anfrage')).toBeInTheDocument();
        expect(screen.getByText('Karte p1')).toBeInTheDocument();
    });

    it('calls onMove with the target column and the end position when dropped into a column', () => {
        const onMove = vi.fn();
        renderBoard({
            onMove,
            items: [
                { id: 'p1', status: 'anfrage', position: 0 },
                { id: 'p2', status: 'anfrage', position: 1 },
            ],
        });

        const onDrop = getMonitorHandler('onDrop');
        onDrop({
            source: { data: { kind: 'card', itemId: 'p1' } },
            location: dropLocation([
                { element: elementWithRect(makeRect()), data: { kind: 'column', status: 'angebot' } },
            ]),
        });

        expect(onMove).toHaveBeenCalledWith('p1', 'angebot', 0);
    });

    it('calls onMove with the projected index when dropped above the target card (hitbox top)', () => {
        const onMove = vi.fn();
        renderBoard({
            onMove,
            items: [
                { id: 'p1', status: 'anfrage', position: 0 },
                { id: 'p2', status: 'anfrage', position: 1 },
                { id: 'p3', status: 'anfrage', position: 2 },
            ],
        });

        const onDrop = getMonitorHandler('onDrop');
        // Drop p1 onto p3's top → insert before p3 → column [p2, p3] → index 1
        onDrop({
            source: { data: { kind: 'card', itemId: 'p1' } },
            location: dropLocation([
                { element: elementWithRect(makeRect()), data: cardTargetData('p3', 'anfrage', 20) },
            ]),
        });

        expect(onMove).toHaveBeenCalledWith('p1', 'anfrage', 1);
    });

    it('calls onMove with the projected index when dropped below the target card (hitbox bottom)', () => {
        const onMove = vi.fn();
        renderBoard({
            onMove,
            items: [
                { id: 'p1', status: 'anfrage', position: 0 },
                { id: 'p2', status: 'anfrage', position: 1 },
                { id: 'p3', status: 'anfrage', position: 2 },
            ],
        });

        const onDrop = getMonitorHandler('onDrop');
        // Drop p1 onto p3's bottom → insert after p3 → column [p2, p3] → index 2
        onDrop({
            source: { data: { kind: 'card', itemId: 'p1' } },
            location: dropLocation([
                { element: elementWithRect(makeRect()), data: cardTargetData('p3', 'anfrage', 80) },
            ]),
        });

        expect(onMove).toHaveBeenCalledWith('p1', 'anfrage', 2);
    });

    it('renders a shadow at the drop position when dragging over a card', () => {
        renderBoard({
            items: [
                { id: 'p1', status: 'anfrage', position: 0 },
                { id: 'p2', status: 'anfrage', position: 1 },
            ],
        });

        // Das Drop-Target der Zielkarte p2 feuert onDragEnter mit der HitBox-Kante
        const target = cardTargetForText('Karte p2');
        const onDragEnter = target.onDragEnter as (args: unknown) => void;
        act(() => {
            onDragEnter({
                source: {
                    data: { kind: 'card', itemId: 'p1' },
                    element: elementWithRect(makeRect()),
                },
                self: { data: cardTargetData('p2', 'anfrage', 20) },
            });
        });

        const shadow = screen.getByTestId('kanban-shadow');
        expect(shadow).toBeInTheDocument();
    });

    it('renders a shadow at the end of an empty target column', () => {
        renderBoard({
            items: [{ id: 'p1', status: 'anfrage', position: 0 }],
        });

        // Zielspalte 'angebot' (leer): Drop-Target-Body feuert onDragEnter mit Column-Data
        const target = columnDropTargets().find(args =>
            args.element instanceof HTMLElement && !args.element.textContent?.includes('Karte'),
        );
        expect(target).toBeDefined();
        const onDragEnter = target!.onDragEnter as (args: unknown) => void;
        act(() => {
            onDragEnter({
                source: {
                    data: { kind: 'card', itemId: 'p1' },
                    element: elementWithRect(makeRect()),
                },
                location: dropLocation([
                    { element: elementWithRect(makeRect()), data: { kind: 'column', status: 'angebot' } },
                ]),
            });
        });

        const shadow = screen.getByTestId('kanban-shadow');
        expect(shadow).toBeInTheDocument();
    });

    it('does not call onMove when dropped outside any drop target (canceled)', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });

        const onDrop = getMonitorHandler('onDrop');
        act(() => {
            onDrop({
                source: { data: { kind: 'card', itemId: 'p1' } },
                location: dropLocation([]),
            });
        });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('does not call onMove when a card is dropped onto itself', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });

        const onDrop = getMonitorHandler('onDrop');
        onDrop({
            source: { data: { kind: 'card', itemId: 'p1' } },
            location: dropLocation([
                { element: elementWithRect(makeRect()), data: cardTargetData('p1', 'anfrage', 20) },
            ]),
        });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('ignores drops from non-card sources', () => {
        const onMove = vi.fn();
        renderBoard({ onMove });

        const onDrop = getMonitorHandler('onDrop');
        onDrop({
            source: { data: { kind: 'external' } },
            location: dropLocation([
                { element: elementWithRect(makeRect()), data: { kind: 'column', status: 'angebot' } },
            ]),
        });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('highlights the active column when a card is dragged over it', () => {
        renderBoard({
            items: [{ id: 'p1', status: 'anfrage', position: 0 }],
        });

        const target = columnDropTargets().find(args =>
            args.element instanceof HTMLElement && !args.element.textContent?.includes('Karte'),
        );
        expect(target).toBeDefined();
        const onDragEnter = target!.onDragEnter as (args: unknown) => void;
        act(() => {
            onDragEnter({
                source: {
                    data: { kind: 'card', itemId: 'p1' },
                    element: elementWithRect(makeRect()),
                },
                location: dropLocation([
                    { element: elementWithRect(makeRect()), data: { kind: 'column', status: 'angebot' } },
                ]),
            });
        });

        const column = screen.getByText('Angebot').closest('.bg-base-200') as HTMLElement;
        expect(column).not.toBeNull();
        expect(column.querySelector('.ring-primary\\/40')).not.toBeNull();
    });
});
