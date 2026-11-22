import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import {
    createEurSignature,
    useEurDocuments,
    useEurSignatures,
} from '../../../logic/useEur';

interface Props {
    year: number;
    month: number;
}

interface FormState {
    provider: string;
    documentId: string;
    note: string;
}

const INITIAL: FormState = {provider: 'signaturdienst', documentId: '', note: ''};

const isTestProvider = (provider: string): boolean => provider.toLowerCase().includes('test');

export default function EurSignaturePanel({year, month}: Props) {
    const signatures = useEurSignatures(year);
    const documents = useEurDocuments(year);
    const [form, setForm] = useState<FormState>(INITIAL);
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const [lastWasTest, setLastWasTest] = useState(false);

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (form.provider === '' || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            const result = await createEurSignature({
                year,
                month,
                eur_document_id: form.documentId === '' ? null : Number(form.documentId),
                provider: form.provider,
                note: form.note === '' ? null : form.note,
            });
            setLastWasTest(result.is_test || isTestProvider(form.provider));
            setForm(INITIAL);
            void signatures.mutate();
        } catch {
            setSaveError(t`Die Signatur konnte nicht erfasst werden. Prüfen Sie Anbieter und Beleg und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    if (signatures.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Signaturen werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (signatures.error || !signatures.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Signaturen nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Die Signaturen konnten nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie die Liste erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void signatures.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const items = signatures.data.signatures;
    const availableDocuments = documents.data?.documents ?? [];

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Signatur</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Der Signaturstatus je Monat wird hier festgehalten. Testanbieter sind gekennzeichnet und ersetzen keine rechtsverbindliche Signatur.</Trans>
                </p>

                {lastWasTest && (
                    <div className="alert alert-warning mt-4" role="status">
                        <span><Trans>Testmodus: Diese Signatur dient nur zur Erprobung.</Trans></span>
                    </div>
                )}

                {items.length === 0 ? (
                    <p className="opacity-70 mt-4">
                        <Trans>Noch keine Signaturen erfasst. Wählen Sie unten einen Anbieter für den Monat.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Monat</Trans></th>
                                    <th><Trans>Anbieter</Trans></th>
                                    <th><Trans>Status</Trans></th>
                                    <th><Trans>Signiert am</Trans></th>
                                    <th><Trans>Notiz</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((signature) => (
                                    <tr key={signature.id}>
                                        <td>{signature.month}</td>
                                        <td>
                                            {signature.provider}
                                            {isTestProvider(signature.provider) && (
                                                <span className="badge badge-warning badge-outline ml-2"><Trans>Test</Trans></span>
                                            )}
                                        </td>
                                        <td>{signature.status}</td>
                                        <td>{signature.signed_at ?? '—'}</td>
                                        <td>{signature.note ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Signatur erfassen</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Anbieter</Trans></span>
                        <select className="select select-bordered" value={form.provider}
                            onChange={(event) => update('provider', event.target.value)} required>
                            <option value="signaturdienst"><Trans>Signaturdienst</Trans></option>
                            <option value="test-signaturdienst"><Trans>Test-Signaturdienst</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Beleg</Trans></span>
                        <select className="select select-bordered" value={form.documentId}
                            onChange={(event) => update('documentId', event.target.value)}>
                            <option value="">—</option>
                            {availableDocuments.map((document) => (
                                <option key={document.id} value={document.id}>{document.original_name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Notiz</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.note} onChange={(event) => update('note', event.target.value)}/>
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={form.provider === '' || isSaving}>
                        <Trans>Signatur speichern</Trans>
                    </button>
                    <p className="text-sm opacity-70 mt-2">
                        <Trans>Der Beleg ist optional. Ohne passenden Beleg lässt sich die Signatur später trotzdem zuordnen.</Trans>
                    </p>
                </div>
            </div>
        </div>
    );
}
