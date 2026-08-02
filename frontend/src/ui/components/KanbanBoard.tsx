import { t } from "@lingui/core/macro";
import { ReactNode } from "react";
import {
    DragDropProvider,
    DragOverlay,
    type DragEndEvent,
    useDroppable,
} from '@dnd-kit/react';
import { useSortable } from '@dnd-kit/react/sortable';

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

interface SortableCardProps<T extends BoardItem> {
    item: T;
    renderCard: (item: T) => ReactNode;
}

function SortableCard<T extends BoardItem>({ item, renderCard }: SortableCardProps<T>) {
    const { ref, isDragging, isDropTarget } = useSortable({
        id: item.id,
        data: { kind: 'card', item, status: item.status, position: item.position },
        index: item.position,
        group: item.status,
    });

    const draggingClass = isDragging ? 'opacity-30' : '';
    const dropClass = isDropTarget ? 'ring-2 ring-primary ring-inset' : '';
    return (
        <div
            ref={ref}
            className={`relative cursor-grab touch-none select-none rounded-box ${draggingClass} ${dropClass}`}
        >
            {renderCard(item)}
        </div>
    );
}

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
    const { ref, isDropTarget } = useDroppable({ id: status, data: { kind: 'column' } });
    const sorted = [...items].sort((a, b) => a.position - b.position);
    const dropClass = isDropTarget ? 'bg-base-200 ring-2 ring-primary/40 ring-inset' : 'bg-base-200';
    return (
        <div className="flex flex-col w-full min-w-0 max-h-full bg-base-200 rounded-box border border-base-300 overflow-hidden">
            {renderColumnHeader(status, label)}
            <div ref={ref} className={`flex-1 overflow-y-auto p-2 flex flex-col gap-2 min-h-24 ${dropClass}`}>
                {sorted.map(item =>
                    disallowDrag ? (
                        <div key={item.id} className="relative select-none rounded-box">
                            {renderCard(item)}
                        </div>
                    ) : (
                        <SortableCard key={item.id} item={item} renderCard={renderCard} />
                    )
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
    const handleDragEnd = (event: DragEndEvent) => {
        if (disallowDrag) return;
        if (event.canceled) return;
        const source = event.operation.source;
        const target = event.operation.target;
        if (!source) return;
        const sourceItem = source.data?.item as T | undefined;
        if (!sourceItem || !target) return;

        const targetData = target.data as { kind?: string; status?: string } | undefined;
        let newStatus: string;
        if (targetData?.kind === 'card') {
            newStatus = targetData.status ?? sourceItem.status;
        } else {
            newStatus = String(target.id);
        }

        const columnItems = items
            .filter(i => i.status === newStatus && i.id !== sourceItem.id)
            .sort((a, b) => a.position - b.position);

        let insertIndex = columnItems.length;
        if (targetData?.kind === 'card') {
            const targetId = String(target.id);
            const idx = columnItems.findIndex(i => i.id === targetId);
            if (idx !== -1) insertIndex = idx;
        }

        onMove(sourceItem.id, newStatus, insertIndex);
    };

    return (
        <div className={`max-w-screen-2xl mx-auto w-full ${embedded ? 'px-2 pb-6' : 'p-6 md:p-10'}`}>
            <DragDropProvider onDragEnd={handleDragEnd}>
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
                {!disallowDrag && (
                    <DragOverlay>
                        {(source) => {
                            const item = source?.data?.item as T | undefined;
                            if (!item) return null;
                            return (
                                <div className="w-full min-w-0 rotate-2 rounded-xl border border-primary/40 bg-base-100 shadow-xl">
                                    {renderCard(item)}
                                </div>
                            );
                        }}
                    </DragOverlay>
                )}
            </DragDropProvider>
        </div>
    );
}