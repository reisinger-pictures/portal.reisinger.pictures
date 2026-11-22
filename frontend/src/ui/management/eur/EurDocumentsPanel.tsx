import { useId, useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import {
    createEurCorrectionDocument,
    deleteEurDocument,
    eurDocumentDownloadUrl,
    eurDocumentSignedDownloadUrl,
    EUR_MONTHS,
    importEurEntries,
    uploadEurDocument,
    uploadSignedEurDocument,
    useEurDocuments,
    type EurCorrectionKind,
    type EurCorrectionPayload,
    type EurDocument,
    type EurImportResult,
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

const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);

/** The two German strings of a 422 response; kept separate from generic network failures. */
interface SignedUploadFailure {
    error: string;
    action: string;
}

/** Reads `{error, action}` from the thrown `ApiError.info` without narrowing to `any`. */
const readSignedUploadFailure = (cause: unknown): SignedUploadFailure | null => {
    const info = isRecord(cause) && 'info' in cause ? cause.info : cause;
    if (!isRecord(info)) return null;
    const {error, action} = info;
    if (typeof error !== 'string' || typeof action !== 'string') return null;
    return {error, action};
};

const hasSignedVersion = (document: EurDocument): boolean =>
    typeof document.signed_sha256 === 'string' && document.signed_sha256.length > 0;

interface SignedCellProps {
    document: EurDocument;
    uploaded: EurDocument | undefined;
    failure: SignedUploadFailure | undefined;
    busy: boolean;
    onUpload: (id: number, file: File) => void;
}

function SignedCell({document, uploaded, failure, busy, onUpload}: SignedCellProps) {
    const signedInputId = `eur-signed-upload-${document.id}`;

    if (hasSignedVersion(uploaded ?? document)) {
        return (
            <a className="btn btn-ghost btn-xs" href={eurDocumentSignedDownloadUrl(document.id)}>
                <Trans>Signiertes PDF herunterladen</Trans>
            </a>
        );
    }

    if (document.status !== 'unsigned') {
        return <span className="opacity-70">—</span>;
    }

    return (
        <div className="form-control">
            <span className="label-text"><Trans>Signiertes PDF hochladen</Trans></span>
            <input
                id={signedInputId}
                type="file"
                accept="application/pdf,.pdf"
                className="peer sr-only"
                aria-label={t`Signiertes PDF hochladen`}
                onChange={(event) => {
                    const chosen = event.target.files?.[0];
                    if (chosen) onUpload(document.id, chosen);
                }}
                disabled={busy}
                required
            />
            <label htmlFor={signedInputId}
                   className="btn btn-outline btn-xs cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-primary">
                <Trans>Signiertes PDF auswählen</Trans>
            </label>
            {busy && (
                <span className="text-xs opacity-70"><Trans>Inhaltsprüfung läuft …</Trans></span>
            )}
            {failure && (
                <div className="alert alert-error mt-2" role="alert">
                    <span>{failure.error}</span>
                    <span>{failure.action}</span>
                </div>
            )}
        </div>
    );
}

export default function EurDocumentsPanel({year, month}: Props) {
    const documents = useEurDocuments(year);
    const [file, setFile] = useState<File | null>(null);
    const [receiptNo, setReceiptNo] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [csvFile, setCsvFile] = useState<File | null>(null);
    const [isImporting, setIsImporting] = useState(false);
    const [csvMessage, setCsvMessage] = useState<string | null>(null);
    const [csvError, setCsvError] = useState<string | null>(null);
    const [csvResult, setCsvResult] = useState<EurImportResult | null>(null);
    const [correctionKind, setCorrectionKind] = useState<EurCorrectionKind>('monthly');
    const [correctionMonth, setCorrectionMonth] = useState(month);
    const [isCreatingCorrection, setIsCreatingCorrection] = useState(false);
    const [correctionDocId, setCorrectionDocId] = useState<number | null>(null);
    const [correctionError, setCorrectionError] = useState<string | null>(null);
    const [signedErrors, setSignedErrors] = useState<Record<number, SignedUploadFailure>>({});
    const [signedDocs, setSignedDocs] = useState<Record<number, EurDocument>>({});
    const [signedBusyId, setSignedBusyId] = useState<number | null>(null);
    const fileInputId = useId();
    const csvInputId = useId();

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

    const importCsv = async () => {
        if (!csvFile || isImporting) return;
        setIsImporting(true);
        setCsvMessage(null);
        setCsvError(null);
        setCsvResult(null);

        try {
            const result = await importEurEntries({file: csvFile, year, month});
            setCsvResult(result);
            const {created, skipped} = result;
            setCsvMessage(t`Import abgeschlossen: ${created} angelegt, ${skipped} übersprungen.`);
            setCsvFile(null);
            void documents.mutate();
        } catch {
            setCsvError(t`Der CSV-Import ist fehlgeschlagen. Prüfen Sie die Datei und versuchen Sie es erneut.`);
        } finally {
            setIsImporting(false);
        }
    };

    const createCorrection = async () => {
        if (isCreatingCorrection) return;
        setIsCreatingCorrection(true);
        setCorrectionError(null);
        setCorrectionDocId(null);

        try {
            const payload: EurCorrectionPayload = correctionKind === 'assets'
                ? {kind: 'assets', year}
                : {kind: correctionKind, year, month: correctionMonth};
            const {document} = await createEurCorrectionDocument(payload);
            setCorrectionDocId(document.id);
            void documents.mutate();
        } catch {
            setCorrectionError(t`Das Korrektur-PDF konnte nicht erstellt werden. Prüfen Sie die Auswahl und versuchen Sie es erneut.`);
        } finally {
            setIsCreatingCorrection(false);
        }
    };

    const uploadSigned = async (id: number, fileToUpload: File) => {
        if (signedBusyId !== null) return;
        setSignedBusyId(id);
        setSignedErrors((previous) => {
            const next = {...previous};
            delete next[id];
            return next;
        });

        try {
            const {document} = await uploadSignedEurDocument(id, fileToUpload);
            setSignedDocs((previous) => ({...previous, [id]: document}));
            void documents.mutate();
        } catch (cause) {
            setSignedErrors((previous) => ({
                ...previous,
                [id]: readSignedUploadFailure(cause) ?? {
                    error: t`Die Inhaltsprüfung des signierten PDFs ist fehlgeschlagen.`,
                    action: t`Signieren Sie unser Original-PDF erneut und laden Sie es noch einmal hoch.`,
                },
            }));
        } finally {
            setSignedBusyId(null);
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
                                    <th><Trans>Signatur</Trans></th>
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
                                            <SignedCell
                                                document={document}
                                                uploaded={signedDocs[document.id]}
                                                failure={signedErrors[document.id]}
                                                busy={signedBusyId === document.id}
                                                onUpload={(id, chosen) => void uploadSigned(id, chosen)}
                                            />
                                        </td>
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

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>CSV-Import</Trans></h3>
                <p className="text-sm opacity-70">
                    <Trans>Buchungen aus einer CSV-Datei in den gewählten Monat importieren. Bereits vorhandene Zeilen werden übersprungen.</Trans>
                </p>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2 items-end">
                    <div className="form-control">
                        <span className="label-text"><Trans>CSV-Datei</Trans></span>
                        <input
                            id={csvInputId}
                            type="file"
                            accept=".csv,text/csv"
                            className="peer sr-only"
                            onChange={(event) => setCsvFile(event.target.files?.[0] ?? null)}
                        />
                        <label
                            htmlFor={csvInputId}
                            className="btn btn-outline btn-sm w-full cursor-pointer peer-focus-visible:ring-2 peer-focus-visible:ring-primary"
                        >
                            <Trans>CSV-Datei auswählen</Trans>
                        </label>
                        <span className="text-sm opacity-70 mt-1" aria-live="polite">
                            {csvFile ? csvFile.name : <Trans>Keine CSV-Datei ausgewählt</Trans>}
                        </span>
                    </div>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void importCsv()}
                            disabled={!csvFile || isImporting}>
                            <Trans>CSV importieren</Trans>
                        </button>
                    </div>
                </div>

                {csvMessage && <p className="alert alert-success mt-3" role="status">{csvMessage}</p>}
                {csvError && <p className="alert alert-error mt-3" role="alert">{csvError}</p>}
                {csvResult && csvResult.errors.length > 0 && (
                    <ul className="mt-2 space-y-1 text-sm text-error" aria-label={t`Importfehler`}>
                        {csvResult.errors.map((message, index) => (
                            <li key={`${index}-${message}`}>{message}</li>
                        ))}
                    </ul>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Korrektur-PDF</Trans></h3>
                <p className="text-sm opacity-70">
                    <Trans>Erstellen Sie ein unsigniertes PDF für einen Monatsbericht, Eigenbeleg oder das Anlagenverzeichnis. Signieren Sie die Datei extern und laden Sie sie anschließend in der Zeile des Dokuments wieder hoch.</Trans>
                </p>
                <p className="text-sm opacity-70 mt-1">
                    <Trans>Die Inhaltsprüfung vergleicht den SHA-256-Hash der hochgeladenen Datei mit dem unseres ursprünglichen PDFs. Damit wird geprüft, ob es die signierte Version genau dieses Dokuments ist – keine qualifizierte elektronische Signatur.</Trans>
                </p>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2 items-end">
                    <label className="form-control">
                        <span className="label-text"><Trans>Art des Korrektur-PDF</Trans></span>
                        <select className="select select-bordered" value={correctionKind} required
                            onChange={(event) => setCorrectionKind(event.target.value as EurCorrectionKind)}>
                            <option value="monthly"><Trans>Monatsbericht</Trans></option>
                            <option value="eigenbeleg"><Trans>Eigenbeleg</Trans></option>
                            <option value="assets"><Trans>Anlagenverzeichnis</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Monat des Korrektur-PDF</Trans></span>
                        <select className="select select-bordered" value={correctionMonth}
                            disabled={correctionKind === 'assets'}
                            required={correctionKind !== 'assets'}
                            onChange={(event) => setCorrectionMonth(Number(event.target.value))}>
                            {EUR_MONTHS.map((name, index) => (
                                <option key={name} value={index + 1}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm"
                            onClick={() => void createCorrection()} disabled={isCreatingCorrection}>
                            <Trans>Korrektur-PDF erstellen</Trans>
                        </button>
                    </div>
                </div>

                {correctionDocId !== null && (
                    <p className="alert alert-success mt-3" role="status">
                        <a className="link" href={eurDocumentDownloadUrl(correctionDocId)}>
                            <Trans>Unsigniertes Korrektur-PDF herunterladen</Trans>
                        </a>
                    </p>
                )}
                {correctionError && (
                    <p className="alert alert-error mt-3" role="alert">{correctionError}</p>
                )}
            </div>
        </div>
    );
}
