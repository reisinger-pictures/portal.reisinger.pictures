import { useId, useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    createEaCorrectionDocument,
    createEaDocumentPositions,
    deleteEaDocument,
    deleteEaDocumentPosition,
    eaDocumentDownloadUrl,
    eaDocumentSignedDownloadUrl,
    EUR_MONTHS,
    importEaEntries,
    ocrEaDocument,
    setEaDocumentGroup,
    uploadEaDocument,
    uploadSignedEaDocument,
    useEaAssetGroups,
    useEaDocuments,
    type EaAssetGroup,
    type EaDocument,
    type EaDocumentPosition,
    type EaCorrectionKind,
    type EaCorrectionPayload,
    type EaImportResult,
    type EaOcrHints,
} from '../../../logic/useEa';

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

const hasSignedVersion = (document: EaDocument): boolean =>
    typeof document.signed_sha256 === 'string' && document.signed_sha256.length > 0;

interface SignedCellProps {
    document: EaDocument;
    uploaded: EaDocument | undefined;
    failure: SignedUploadFailure | undefined;
    busy: boolean;
    onUpload: (id: number, file: File) => void;
}

function SignedCell({document, uploaded, failure, busy, onUpload}: SignedCellProps) {
    const signedInputId = `ea-signed-upload-${document.id}`;

    if (hasSignedVersion(uploaded ?? document)) {
        return (
            <a className="btn btn-ghost btn-xs" href={eaDocumentSignedDownloadUrl(document.id)}>
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

const cents = (value: number): string => formatEuro(value / 100);

/** #59: the API accepts 0–100 in 5-% steps, just like the group editor. */
const BUSINESS_SHARES = Array.from({length: 21}, (_, index) => index * 5);

/** A 422 carries the server's German reason on `error`; keep it if present. */
const readServerError = (cause: unknown, fallback: string): string => {
    const info = isRecord(cause) && 'info' in cause ? cause.info : cause;
    if (isRecord(info) && typeof info.error === 'string' && info.error.length > 0) return info.error;
    return fallback;
};

// Factory, called inside the component: a `t` macro at module scope would be
// evaluated before i18n.activate() (see main.tsx).
const createSplitSchema = () => z.object({
    totalCents: z.string().refine((value) => {
        if (value.trim() === '') return true;
        const amount = Number(value);
        return Number.isSafeInteger(amount) && amount >= 0;
    }, t`Belegbetrag als ganze Cent angeben`),
    positions: z.array(z.object({
        name: z.string().trim().min(1, t`Bitte einen Namen angeben`),
        amountCents: z.string().refine((value) => {
            const amount = Number(value);
            return value.trim() !== '' && Number.isSafeInteger(amount) && amount > 0;
        }, t`Betrag als ganze Cent angeben`),
        businessShare: z.string().refine((value) => {
            const share = Number(value);
            return Number.isInteger(share) && share >= 0 && share <= 100 && share % 5 === 0;
        }, t`Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben`),
        usefulLifeYears: z.string().refine((value) => {
            const years = Number(value);
            return value.trim() !== '' && Number.isInteger(years) && years >= 1 && years <= 50;
        }, t`Nutzungsdauer in ganzen Jahren von 1 bis 50 angeben`),
        label: z.string(),
    })).min(1, t`Mindestens eine Position angeben`),
});

type SplitFormValues = z.infer<ReturnType<typeof createSplitSchema>>;

const emptySplitPosition = () => ({name: '', amountCents: '', businessShare: '100', usefulLifeYears: '', label: ''});

interface DocumentPositionsProps {
    doc: EaDocument;
    groups: EaAssetGroup[];
}

/**
 * #59: row-scoped split/merge editor. Everything a test needs stays inside the
 * document's table row (D-10), no separate portal-level landmark.
 */
function DocumentPositions({doc, groups}: DocumentPositionsProps) {
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [groupSelection, setGroupSelection] = useState('');
    const {control, register, handleSubmit, reset, formState: {errors}} = useForm<SplitFormValues>({
        resolver: zodResolver(createSplitSchema()),
        defaultValues: {totalCents: '', positions: [emptySplitPosition()]},
        mode: 'onSubmit',
    });
    const {fields, append, remove} = useFieldArray({control, name: 'positions'});

    const alreadySplit = doc.positions.length > 0;
    const total = doc.total_cents === null ? '—' : cents(doc.total_cents);
    const allocated = cents(doc.allocated_cents);
    const remaining = doc.remaining_cents === null ? '—' : cents(doc.remaining_cents);

    const onSplit = async (values: SplitFormValues) => {
        if (busy) return;
        setBusy(true);
        setMessage(null);
        try {
            await createEaDocumentPositions(doc.id, {
                total_cents: values.totalCents.trim() === '' ? null : Number(values.totalCents),
                positions: values.positions.map((position) => ({
                    name: position.name.trim(),
                    amount_cents: Number(position.amountCents),
                    business_share: Number(position.businessShare),
                    useful_life_years: Number(position.usefulLifeYears),
                    label: position.label.trim() === '' ? null : position.label.trim(),
                    method: 'linear',
                })),
            });
            reset({totalCents: '', positions: [emptySplitPosition()]});
            const count = values.positions.length;
            setMessage(t`Der Beleg wurde in ${count} Positionen aufgeteilt.`);
        } catch (cause) {
            setMessage(readServerError(
                cause,
                t`Der Beleg konnte nicht aufgeteilt werden. Prüfen Sie die Beträge und versuchen Sie es erneut.`,
            ));
        } finally {
            setBusy(false);
        }
    };

    const onRemove = async (position: EaDocumentPosition) => {
        setBusy(true);
        setMessage(null);
        try {
            await deleteEaDocumentPosition(position.id);
            const name = position.asset_name;
            setMessage(t`Die Position ${name} wurde entfernt.`);
        } catch {
            setMessage(t`Die Position konnte nicht entfernt werden.`);
        } finally {
            setBusy(false);
        }
    };

    const onAssign = async () => {
        if (groupSelection === '') return;
        setBusy(true);
        setMessage(null);
        try {
            await setEaDocumentGroup(doc.id, Number(groupSelection));
            setGroupSelection('');
            setMessage(t`Der Beleg wurde der Gruppe zugeordnet.`);
        } catch {
            setMessage(t`Der Beleg konnte nicht zugeordnet werden.`);
        } finally {
            setBusy(false);
        }
    };

    const onDetach = async () => {
        setBusy(true);
        setMessage(null);
        try {
            await setEaDocumentGroup(doc.id, null);
            setMessage(t`Der Beleg wurde aus der Gruppe gelöst.`);
        } catch {
            setMessage(t`Der Beleg konnte nicht gelöst werden.`);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mt-3 space-y-2" data-testid={`ea-document-positions-${doc.id}`}>
            <h4 className="font-medium"><Trans>Positionen</Trans></h4>
            <p className="text-sm opacity-70">
                <Trans>Belegbetrag: {total} · verteilt: {allocated} · offen: {remaining}</Trans>
            </p>
            {doc.positions.length === 0 ? (
                <p className="text-sm opacity-70"><Trans>Noch keine Positionen erfasst.</Trans></p>
            ) : (
                <ul className="text-sm" data-testid={`ea-document-position-list-${doc.id}`}>
                    {doc.positions.map((position) => (
                        <li key={position.id} className="flex flex-wrap items-center justify-between gap-2">
                            <span>{position.label ?? position.asset_name}</span>
                            <span className="flex flex-wrap items-center gap-2">
                                <span>{position.amount_cents === null ? '—' : cents(position.amount_cents)}</span>
                                <button type="button" className="btn btn-ghost btn-xs" disabled={busy}
                                    onClick={() => void onRemove(position)}>
                                    <Trans>Position entfernen</Trans>
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {alreadySplit && (
                <p className="text-sm opacity-70">
                    <Trans>Dieser Beleg ist bereits aufgeteilt. Entfernen Sie zuerst die Positionen, um ihn erneut zu splitten.</Trans>
                </p>
            )}
            {/*
             * `noValidate`: zod decides, and every rejection is rendered inline
             * below its field. The native bubbles would block the submit event
             * before React sees it — a silent no-op for the user and for tests.
             * The `required` attributes stay: they drive the label star.
             */}
            <form onSubmit={handleSubmit(onSplit)} noValidate className="space-y-2">
                <fieldset disabled={alreadySplit || busy} className="space-y-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Belegbetrag in Cent</Trans></span>
                        <input type="number" className="input input-bordered input-sm" {...register('totalCents')}/>
                        {errors.totalCents && <span className="text-error text-xs mt-1">{errors.totalCents.message}</span>}
                    </label>
                    {fields.map((field, index) => (
                        <div key={field.id} className="rounded-box border border-base-300 p-2 space-y-2"
                            data-testid={`ea-document-position-${index}`}>
                            <label className="form-control">
                                <span className="label-text"><Trans>Name</Trans></span>
                                <input type="text" className="input input-bordered input-sm" required
                                    {...register(`positions.${index}.name`)}/>
                                {errors.positions?.[index]?.name && (
                                    <span className="text-error text-xs mt-1">{errors.positions[index]?.name?.message}</span>
                                )}
                            </label>
                            <label className="form-control">
                                <span className="label-text"><Trans>Betrag in Cent</Trans></span>
                                <input type="number" className="input input-bordered input-sm" required
                                    {...register(`positions.${index}.amountCents`)}/>
                                {errors.positions?.[index]?.amountCents && (
                                    <span className="text-error text-xs mt-1">{errors.positions[index]?.amountCents?.message}</span>
                                )}
                            </label>
                            <label className="form-control">
                                <span className="label-text"><Trans>Betriebsanteil</Trans></span>
                                <select className="select select-bordered select-sm" required
                                    {...register(`positions.${index}.businessShare`)}>
                                    {BUSINESS_SHARES.map((share) => (
                                        <option key={share} value={share}>{share} %</option>
                                    ))}
                                </select>
                                {errors.positions?.[index]?.businessShare && (
                                    <span className="text-error text-xs mt-1">{errors.positions[index]?.businessShare?.message}</span>
                                )}
                            </label>
                            <label className="form-control">
                                <span className="label-text"><Trans>Nutzungsdauer in Jahren</Trans></span>
                                <input type="number" className="input input-bordered input-sm" required
                                    min={1} max={50} {...register(`positions.${index}.usefulLifeYears`)}/>
                                {errors.positions?.[index]?.usefulLifeYears && (
                                    <span className="text-error text-xs mt-1">{errors.positions[index]?.usefulLifeYears?.message}</span>
                                )}
                            </label>
                            <label className="form-control">
                                <span className="label-text"><Trans>Bezeichnung</Trans></span>
                                <input type="text" className="input input-bordered input-sm"
                                    {...register(`positions.${index}.label`)}/>
                            </label>
                            <button type="button" className="btn btn-ghost btn-xs"
                                onClick={() => remove(index)}>
                                <Trans>Zeile entfernen</Trans>
                            </button>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className="btn btn-outline btn-sm"
                            onClick={() => append(emptySplitPosition())}>
                            <Trans>Position hinzufügen</Trans>
                        </button>
                        <button type="submit" className="btn btn-primary btn-sm">
                            <Trans>Beleg aufteilen</Trans>
                        </button>
                    </div>
                </fieldset>
            </form>

            <div className="flex flex-wrap items-end gap-2">
                <label className="form-control">
                    <span className="label-text"><Trans>Beleg einer Gruppe zuordnen</Trans></span>
                    <select className="select select-bordered select-sm" value={groupSelection}
                        disabled={busy}
                        data-testid="ea-document-group-select"
                        onChange={(event) => setGroupSelection(event.target.value)}>
                        <option value=""><Trans>Gruppe wählen</Trans></option>
                        {groups.map((group) => (
                            <option key={group.id} value={group.id}>{group.name}</option>
                        ))}
                    </select>
                </label>
                <button type="button" className="btn btn-outline btn-sm"
                    disabled={busy || groupSelection === ''}
                    onClick={() => void onAssign()}>
                    <Trans>Zuordnen</Trans>
                </button>
                <button type="button" className="btn btn-ghost btn-sm" disabled={busy}
                    onClick={() => void onDetach()}>
                    <Trans>Gruppe lösen</Trans>
                </button>
            </div>

            {message && (
                <p className="alert alert-info mt-2" role="status" data-testid="ea-document-positions-status">{message}</p>
            )}
        </div>
    );
}

export default function EaDocumentsPanel({year, month}: Props) {
    const documents = useEaDocuments(year);
    const groups = useEaAssetGroups();
    /**
     * A background revalidation (or a failed one) must not blank the document
     * rows: their local editors and confirmations would unmount and the user
     * would lose what they just did. The last loaded list therefore stays on
     * screen until the refetch lands, with an error banner when it fails.
     * Adjusting the state during render (instead of in an effect) is React's
     * documented pattern for deriving state from the current render's data.
     */
    const [lastLoaded, setLastLoaded] = useState<{year: number; documents: EaDocument[]} | null>(null);
    const fetched = documents.data?.documents;
    if (fetched !== undefined
        && (lastLoaded === null || lastLoaded.year !== year || lastLoaded.documents !== fetched)) {
        setLastLoaded({year, documents: fetched});
    }
    const loadedItems = fetched !== undefined
        ? fetched
        : lastLoaded !== null && lastLoaded.year === year
            ? lastLoaded.documents
            : undefined;
    const [file, setFile] = useState<File | null>(null);
    const [receiptNo, setReceiptNo] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [csvFile, setCsvFile] = useState<File | null>(null);
    const [isImporting, setIsImporting] = useState(false);
    const [csvMessage, setCsvMessage] = useState<string | null>(null);
    const [csvError, setCsvError] = useState<string | null>(null);
    const [csvResult, setCsvResult] = useState<EaImportResult | null>(null);
    const [correctionKind, setCorrectionKind] = useState<EaCorrectionKind>('monthly');
    const [correctionMonth, setCorrectionMonth] = useState(month);
    const [isCreatingCorrection, setIsCreatingCorrection] = useState(false);
    const [correctionDocId, setCorrectionDocId] = useState<number | null>(null);
    const [correctionError, setCorrectionError] = useState<string | null>(null);
    const [signedErrors, setSignedErrors] = useState<Record<number, SignedUploadFailure>>({});
    const [signedDocs, setSignedDocs] = useState<Record<number, EaDocument>>({});
    const [signedBusyId, setSignedBusyId] = useState<number | null>(null);
    const [ocrHints, setOcrHints] = useState<Record<number, EaOcrHints>>({});
    const [ocrBusyId, setOcrBusyId] = useState<number | null>(null);
    const fileInputId = useId();
    const csvInputId = useId();

    const submit = async () => {
        if (!file || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            await uploadEaDocument({
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
            await deleteEaDocument(id);
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
            const result = await importEaEntries({file: csvFile, year, month});
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
            const payload: EaCorrectionPayload = correctionKind === 'assets'
                ? {kind: 'assets', year}
                : {kind: correctionKind, year, month: correctionMonth};
            const {document} = await createEaCorrectionDocument(payload);
            setCorrectionDocId(document.id);
            void documents.mutate();
        } catch {
            setCorrectionError(t`Das Korrektur-PDF konnte nicht erstellt werden. Prüfen Sie die Auswahl und versuchen Sie es erneut.`);
        } finally {
            setIsCreatingCorrection(false);
        }
    };

    const runOcr = async (id: number) => {
        if (ocrBusyId !== null) return;
        setOcrBusyId(id);
        try {
            const {ocr} = await ocrEaDocument(id);
            setOcrHints((previous) => ({...previous, [id]: ocr}));
        } catch {
            setOcrHints((previous) => ({
                ...previous,
                [id]: {supported: false, reason: t`OCR konnte nicht ausgeführt werden.`},
            }));
        } finally {
            setOcrBusyId(null);
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
            const {document} = await uploadSignedEaDocument(id, fileToUpload);
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

    // Only a first load without any previously loaded list shows the spinner;
    // a background revalidation keeps the rendered rows (see `lastLoaded`).
    if (loadedItems === undefined && documents.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Belege werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (loadedItems === undefined) {
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

    const items = loadedItems;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Belege</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Belege bleiben privat gespeichert. Jede Betriebsausgabe sollte über eine Belegnummer auffindbar sein.</Trans>
                </p>

                {documents.error && (
                    <div className="alert alert-error mt-4" role="alert">
                        <span><Trans>Die Belegliste konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie sie erneut.</Trans></span>
                        <button type="button" className="btn btn-sm" onClick={() => void documents.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                )}

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
                                    <th><Trans>Positionen</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((document) => (
                                    <tr key={document.id}>
                                        <td>
                                            {document.original_name}
                                            {ocrHints[document.id] && (
                                                <span className="block text-xs opacity-70 mt-1">
                                                    {ocrHints[document.id].supported
                                                        ? [
                                                            ocrHints[document.id].receipt_no,
                                                            ocrHints[document.id].date,
                                                            ocrHints[document.id].amount_cents === null || ocrHints[document.id].amount_cents === undefined
                                                                ? null
                                                                : formatEuro((ocrHints[document.id].amount_cents ?? 0) / 100),
                                                        ].filter(Boolean).join(' · ') || t`Keine Hinweise erkannt`
                                                        : ocrHints[document.id].reason}
                                                </span>
                                            )}
                                        </td>
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
                                                <a className="btn btn-ghost btn-xs" href={eaDocumentDownloadUrl(document.id)}>
                                                    <Trans>Öffnen</Trans>
                                                </a>
                                                <button type="button" className="btn btn-ghost btn-xs"
                                                    disabled={ocrBusyId === document.id}
                                                    onClick={() => void runOcr(document.id)}>
                                                    <Trans>OCR-Hinweise</Trans>
                                                </button>
                                                <button type="button" className="btn btn-ghost btn-xs"
                                                    onClick={() => void remove(document.id)}>
                                                    <Trans>Entfernen</Trans>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <DocumentPositions doc={document} groups={groups.data?.groups ?? []}/>
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
                            onChange={(event) => setCorrectionKind(event.target.value as EaCorrectionKind)}>
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
                        <a className="link" href={eaDocumentDownloadUrl(correctionDocId)}>
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
