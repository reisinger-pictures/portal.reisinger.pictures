interface PaginationProps {
    page: number;
    lastPage: number;
    onPageChange: (page: number) => void;
    className?: string;
}

export default function Pagination({ page, lastPage, onPageChange, className = '' }: PaginationProps) {
    if (lastPage <= 1) return null;

    return (
        <div className={`flex justify-between items-center flex-wrap gap-2 ${className}`}>
            <button className="btn btn-sm btn-outline" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
                ← Zurück
            </button>
            <span className="text-sm font-semibold">Seite {page} von {lastPage}</span>
            <button className="btn btn-sm btn-outline" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>
                Weiter →
            </button>
        </div>
    );
}
