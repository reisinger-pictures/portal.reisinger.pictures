import { useId, useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import {
    deleteEurDocument,
    eurDocumentDownloadUrl,
    uploadEurDocument,
    useEurDocuments,
} from '../../../logic/useEur';

interface Props {
    year: number;
    month: number;
}

const formatSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`;
    return `${Math.round(bytes / (1024 * 1024))} MB`;
};

export default function EurDocumentsPanel({year, month}: Props) {
    const documents = useEurDocuments(year);
    const [file, setFile] = useState<File | null>(null);
    const [receiptNo, setReceiptNo] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const fileInputId = useId();

    const submit = async () => {
        if (!file || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            await uploadEurDocument({
                file,
                year,
                month,
                receipt_no: receiptNo === '' ? undefined : receiptNo,
            });
            setFile(null);
            setReceiptNo('');
            void documents.mutate();
        } catch {
            setSaveError(t`Der Beleg konnte nicht hochgeladen werden. Prüfen Sie die Datei und die Belegnummer und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    const remove = async (id: number) => {
        try {
            await deleteEurDocument(id);
            void documents.mutate();
        } catch {
            setSaveError(t`Der Beleg konnte nicht gelöscht werden. Laden Sie die Liste erneut und versuchen Sie es noch einmal.`);
        }
    };

    if (documents.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Belege werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (documents.error || !documents.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Belege nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Die Belegliste konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie sie erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void documents.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const items = documents.data.documents;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Belege</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Belege bleiben privat gespeichert. Jede Betriebsausgabe sollte über eine Belegnummer auffindbar sein.</Trans>
                </p>

                {items.length === 0 ? (
                    <p className="opacity-70 mt-4">
                        <Trans>Noch keine Belege hinterlegt. Laden Sie unten die erste Datei hoch.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Datei</Trans></th>
                                    <th><Trans>Belegnummer</Trans></th>
                                    <th><Trans>Monat</Trans></th>
                                    <th><Trans>Größe</Trans></th>
                                    <th><Trans>Status</Trans></th>
                                    <th><Trans>Aktion</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((document) => (
                                    <tr key={document.id}>
                                        <td>{document.original_name}</td>
                                        <td>{document.receipt_no ?? '—'}</td>
                                        <td>{document.month ?? '—'}</td>
                                        <td>{formatSize(document.size_bytes)}</td>
                                        <td>{document.status}</td>
                                        <td>
                                            <div className="flex flex-wrap gap-1">
                                                <a className="btn btn-ghost btn-xs" href={eurDocumentDownloadUrl(document.id)}>
                                                    <Trans>Öffnen</Trans>
                                                </a>
                                                <button type="button" className="btn btn-ghost btn-xs"
                                                    onClick={() => void remove(document.id)}>
                                                    <Trans>Entfernen</Trans>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Beleg hochladen</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <div className="form-control">
                        <span className="label-text"><Trans>Datei</Trans></span>
                        <input
                            id={fileInputId}
                            type="file"
                            className="peer sr-only"
                            onChange={(event) => setFile(event.target.files?.[0] ?? null)} required/>
                        <label
                            htmlFor={fileInputId}
                            className="btn btn-outline btn-sm w-full cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-primary">
                            <Trans>Datei auswählen</Trans>
                        </label>
                        <span className="text-sm opacity-70 mt-1" aria-live="polite">
                            {file ? file.name : <Trans>Keine Datei ausgewählt</Trans>}
                        </span>
                    </div>
                    <label className="form-control">
                        <span className="label-text"><Trans>Belegnummer</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={receiptNo} onChange={(event) => setReceiptNo(event.target.value)}/>
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!file || isSaving}>
                        <Trans>Hochladen</Trans>
                    </button>
                    {!file && (
                        <p className="text-sm opacity-70 mt-2">
                            <Trans>Wählen Sie zuerst eine Datei aus. Die Belegnummer ist optional.</Trans>
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
