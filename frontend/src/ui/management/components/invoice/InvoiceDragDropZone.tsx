interface InvoiceDragDropZoneProps {
    isDragging: boolean;
    isOffer: boolean;
    onFileUpload: (e: React.ChangeEvent<HTMLInputElement>) => void;
}

export default function InvoiceDragDropZone({
    isDragging,
    isOffer,
    onFileUpload
}: InvoiceDragDropZoneProps) {
    return (
        <>
            {isDragging && !isOffer && (
                <div className="absolute inset-0 z-50 flex items-center justify-center bg-base-100/80 backdrop-blur-sm rounded-box border-4 border-dashed border-primary m-6 pointer-events-none">
                    <div className="text-center text-primary">
                        <span className="iconify mdi--upload text-6xl mb-2"></span>
                        <h2 className="text-2xl font-bold">Angebot hier ablegen</h2>
                        <p>Die Daten werden automatisch in die Rechnung übernommen.</p>
                    </div>
                </div>
            )}

            <div className="mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative z-10">
                <div>
                    <h1 className="text-4xl font-bold flex items-center gap-2 mb-2">
                        <span className={`iconify ${isOffer ? 'mdi--file-chart-outline' : 'mdi--file-document-edit-outline'} text-primary`}></span>
                        {isOffer ? 'Manuelles Angebot' : 'Manuelle Rechnung'}
                    </h1>
                    <p className="opacity-70">
                        {isOffer ? 'Erstelle ein unverbindliches Angebot für Kunden.' : 'Erstelle eine freie PDF-Rechnung.'}
                    </p>
                </div>

                {!isOffer && (
                    <div className="flex-none">
                        <label className="btn btn-outline btn-primary shadow-sm cursor-pointer">
                            <span className="iconify mdi--upload text-xl"></span> Angebot importieren (.pdf)
                            <input type="file" accept="application/pdf" className="hidden" onChange={onFileUpload} />
                        </label>
                    </div>
                )}
            </div>
        </>
    );
}
