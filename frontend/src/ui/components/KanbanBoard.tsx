import { t } from "@lingui/core/macro";
import { ReactNode, useEffect, useRef, useState } from "react";
import { createRoot } from 'react-dom/client';
import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { setCustomNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/set-custom-native-drag-preview';
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine';
import { attachClosestEdge, extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';
import { I18nProvider } from '../../logic/I18nProvider';

export interface BoardItem {
    id: string;
    status: string;
    position: number;
}

export interface KanbanColumnDef {
    status: string;
    label: string;
}

interface KanbanBoardProps<T extends BoardItem> {
    title: string;
    icon?: string;
    columns: KanbanColumnDef[];
    items: T[];
    onMove: (id: string, status: string, position: number) => void;
    renderCard: (item: T) => ReactNode;
    renderColumnHeader: (status: string, label: string) => ReactNode;
    /** Wenn true werden Karten und Spalten nicht drag-/droppable gerendert (Statuswechsel nur via Edit-Modal). */
    disallowDrag?: boolean;
    /** Unterdrückt den Board-Header inkl. Toptitel (Einbettung in eine Tabs-View). */
    embedded?: boolean;
}

interface CardData {
    kind: 'card';
    itemId: string;
    status: string;
}

interface ColumnData {
    kind: 'column';
    status: string;
}

type DropTargetData = CardData | ColumnData;

/** Ausgegrauter Platzhalter (leer, nur Höhe der gezogenen Karte) — nimmt Raum in-flow ein. */
function CardShadow({ height }: { height: number }) {
    return (
        <div
            data-testid="kanban-shadow"
            className="flex-shrink-0 rounded-box border-2 border-dashed border-primary/60 bg-primary/10 pointer-events-none"
            style={{ height }}
            aria-hidden="true"
        />
    );
}

type CardState =
    | { type: 'idle' }
    | { type: 'is-dragging' }
    | { type: 'is-over'; edge: 'top' | 'bottom'; height: number };

interface SortableCardProps<T extends BoardItem> {
    item: T;
    renderCard: (item: T) => ReactNode;
}

function SortableCard<T extends BoardItem>({ item, renderCard }: SortableCardProps<T>) {
    const outerRef = useRef<HTMLDivElement | null>(null);
    const innerRef = useRef<HTMLDivElement | null>(null);
    const [state, setState] = useState<CardState>({ type: 'idle' });
    const itemRef = useRef(item);
    const renderCardRef = useRef(renderCard);

    useEffect(() => {
        itemRef.current = item;
        renderCardRef.current = renderCard;
    });

    useEffect(() => {
        const outer = outerRef.current;
        const inner = innerRef.current;
        if (!outer || !inner) return;

        return combine(
            draggable({
                element: inner,
                getInitialData: () => ({ kind: 'card', itemId: itemRef.current.id }),
                onDragStart: () => setState({ type: 'is-dragging' }),
                onDrop: () => setState({ type: 'idle' }),
                onGenerateDragPreview: ({ nativeSetDragImage }) => {
                    const width = inner.getBoundingClientRect().width;
                    setCustomNativeDragPreview({
                        nativeSetDragImage,
                        getOffset: () => ({
                            x: 20,
                            y: 20,
                        }),
                        render: ({ container }) => {
                            container.style.width = `${width}px`;
                            const root = createRoot(container);
                            root.render(
                                <I18nProvider>
                                    <div className="w-full rotate-2 opacity-95 shadow-2xl rounded-lg">
                                        {renderCardRef.current(itemRef.current)}
                                    </div>
                                </I18nProvider>,
                            );
                            return () => root.unmount();
                        },
                    });
                },
            }),
            dropTargetForElements({
                element: outer,
                getIsSticky: () => true,
                getData: ({ input, element }) =>
                    attachClosestEdge(
                        { kind: 'card', itemId: item.id, status: item.status },
                        { input, element, allowedEdges: ['top', 'bottom'] },
                    ),
                canDrop: ({ source }) =>
                    source.data?.kind === 'card' && source.element !== inner,
                onDragEnter: ({ source, self }) => {
                    if (source.data?.kind !== 'card') return;
                    if (source.data.itemId === item.id) return;
                    const edge = extractClosestEdge(self.data);
                    if (!edge) return;
                    const height = (source.element as HTMLElement).getBoundingClientRect().height;
                    setState({ type: 'is-over', edge: edge === 'bottom' ? 'bottom' : 'top', height });
                },
                onDrag: ({ source, self }) => {
                    if (source.data?.kind !== 'card') return;
                    if (source.data.itemId === item.id) return;
                    const edge = extractClosestEdge(self.data);
                    if (!edge) return;
                    const height = (source.element as HTMLElement).getBoundingClientRect().height;
                    const proposed: CardState = {
                        type: 'is-over',
                        edge: edge === 'bottom' ? 'bottom' : 'top',
                        height,
                    };
                    setState(current => (current.type === 'is-over' && current.edge === proposed.edge ? current : proposed));
                },
                onDragLeave: () => setState({ type: 'idle' }),
                onDrop: () => setState({ type: 'idle' }),
            }),
        );
    }, [item.id, item.status, renderCard]);

    const isDragging = state.type === 'is-dragging';
    const isOver = state.type === 'is-over';

    return (
        <div
            ref={outerRef}
            data-testid="kanban-card"
            className="relative flex flex-col gap-2 flex-shrink-0 select-none rounded-box"
        >
            {isOver && state.edge === 'top' && <CardShadow height={state.height} />}
            <div
                ref={innerRef}
                className={`cursor-grab touch-none rounded-box ${isDragging ? 'hidden' : ''}`}
            >
                {renderCard(item)}
            </div>
            {isOver && state.edge === 'bottom' && <CardShadow height={state.height} />}
        </div>
    );
}

type ColumnState =
    | { type: 'idle' }
    | { type: 'is-card-over'; height: number; isOverChildCard: boolean };

function BoardColumn<T extends BoardItem>({
    status,
    label,
    items,
    renderCard,
    renderColumnHeader,
    disallowDrag,
}: {
    status: string;
    label: string;
    items: T[];
    renderCard: (item: T) => ReactNode;
    renderColumnHeader: (status: string, label: string) => ReactNode;
    disallowDrag: boolean;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [state, setState] = useState<ColumnState>({ type: 'idle' });

    useEffect(() => {
        const el = ref.current;
        if (!el || disallowDrag) return;

        return dropTargetForElements({
            element: el,
            getIsSticky: () => true,
            getData: ({ input, element }) =>
                attachClosestEdge(
                    { kind: 'column', status },
                    { input, element, allowedEdges: ['top', 'bottom'] },
                ),
            canDrop: ({ source }) => source.data?.kind === 'card',
            onDragEnter: ({ source, location }) => {
                if (source.data?.kind !== 'card') return;
                const innerMost = location.current.dropTargets[0];
                const isOverChildCard = Boolean(innerMost && innerMost.data?.kind === 'card');
                const height = (source.element as HTMLElement).getBoundingClientRect().height;
                setState({ type: 'is-card-over', height, isOverChildCard });
            },
            onDropTargetChange: ({ source, location }) => {
                if (source.data?.kind !== 'card') return;
                const innerMost = location.current.dropTargets[0];
                const isOverChildCard = Boolean(innerMost && innerMost.data?.kind === 'card');
                const height = (source.element as HTMLElement).getBoundingClientRect().height;
                setState({ type: 'is-card-over', height, isOverChildCard });
            },
            onDragLeave: () => setState({ type: 'idle' }),
            onDrop: () => setState({ type: 'idle' }),
        });
    }, [status, disallowDrag]);

    const sorted = [...items].sort((a, b) => a.position - b.position);
    const isCardOver = state.type === 'is-card-over';
    const columnActive = isCardOver && !state.isOverChildCard;
    const dropClass = columnActive ? 'bg-base-200 ring-2 ring-primary/40 ring-inset' : 'bg-base-200';

    return (
        <div className="flex flex-col w-full min-w-0 max-h-full bg-base-200 rounded-box border border-base-300 overflow-hidden">
            {renderColumnHeader(status, label)}
            <div ref={ref} className={`relative flex-1 overflow-y-auto p-2 flex flex-col gap-2 min-h-24 ${dropClass}`}>
                {sorted.map(item =>
                    disallowDrag ? (
                        <div key={item.id} className="relative select-none rounded-box">
                            {renderCard(item)}
                        </div>
                    ) : (
                        <SortableCard key={item.id} item={item} renderCard={renderCard} />
                    )
                )}
                {isCardOver && !state.isOverChildCard && (
                    <CardShadow height={state.height} />
                )}
                {sorted.length === 0 && (
                    <div className="flex-1 flex items-center justify-center text-sm opacity-40 border-2 border-dashed border-base-300 rounded-lg py-6">
                        {t`Leer`}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function KanbanBoard<T extends BoardItem>({
    items,
    columns,
    icon,
    title,
    onMove,
    renderCard,
    renderColumnHeader,
    disallowDrag = false,
    embedded = false,
}: KanbanBoardProps<T>) {
    const itemsRef = useRef(items);
    const onMoveRef = useRef(onMove);

    useEffect(() => {
        itemsRef.current = items;
        onMoveRef.current = onMove;
    });

    useEffect(() => {
        if (disallowDrag) return;

        return monitorForElements({
            onDrop: ({ source, location }) => {
                const sourceData = source.data as { kind?: string; itemId?: string } | undefined;
                if (sourceData?.kind !== 'card' || !sourceData.itemId) return;

                const dest = location.current.dropTargets[0];
                if (!dest) return;
                const destData = dest.data as unknown as DropTargetData | undefined;
                if (!destData) return;

                const currentItems = itemsRef.current;
                const sourceItem = currentItems.find(i => i.id === sourceData.itemId);
                if (!sourceItem) return;

                if (destData.kind === 'column') {
                    const newStatus = destData.status;
                    const columnItems = currentItems
                        .filter(i => i.status === newStatus && i.id !== sourceItem.id)
                        .sort((a, b) => a.position - b.position);
                    // HitBox-Kante des Column-Bodys: top → Position 0, bottom → Append (Ende).
                    const edge = extractClosestEdge(dest.data);
                    const insertIndex = edge === 'top' ? 0 : columnItems.length;
                    onMoveRef.current(sourceItem.id, newStatus, insertIndex);
                    return;
                }

                // kind === 'card': Drop über/unter der Zielkarte (HitBox via closestEdge)
                if (destData.itemId === sourceItem.id) return;
                const newStatus = destData.status;
                const columnItems = currentItems
                    .filter(i => i.status === newStatus && i.id !== sourceItem.id)
                    .sort((a, b) => a.position - b.position);
                const targetIndex = columnItems.findIndex(c => c.id === destData.itemId);
                if (targetIndex < 0) return;

                const edge = extractClosestEdge(dest.data);
                const insertIndex = edge === 'bottom' ? targetIndex + 1 : targetIndex;
                onMoveRef.current(sourceItem.id, newStatus, insertIndex);
            },
        });
    }, [disallowDrag]);

    return (
        <div className={`max-w-screen-2xl mx-auto w-full ${embedded ? 'px-2 pb-6' : 'p-6 md:p-10'}`}>
            {!embedded && (
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h1 className="text-4xl font-bold flex items-center gap-3">
                        {icon && <span className={`${icon} text-primary`}></span>}
                        {title}
                    </h1>
                </div>
            )}
            <div className={`grid kanban-grid gap-4 items-start ${embedded ? 'mt-3' : 'mt-8'}`}>
                {columns.map(col => (
                    <BoardColumn
                        key={col.status}
                        status={col.status}
                        label={col.label}
                        items={items.filter(i => i.status === col.status)}
                        renderCard={renderCard}
                        renderColumnHeader={renderColumnHeader}
                        disallowDrag={disallowDrag}
                    />
                ))}
            </div>
        </div>
    );
}
