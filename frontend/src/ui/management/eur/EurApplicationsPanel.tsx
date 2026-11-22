import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { createEurApplication, useEurApplications } from '../../../logic/useEur';

interface Props {
    year: number;
}

interface FormState {
    type: string;
    subject: string;
    note: string;
}

const INITIAL: FormState = {type: 'ausgleich', subject: '', note: ''};

export default function EurApplicationsPanel({year}: Props) {
    const applications = useEurApplications(year);
    const [form, setForm] = useState<FormState>(INITIAL);
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const isValid = form.subject.trim() !== '';

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (!isValid || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            await createEurApplication({
                year,
                type: form.type,
                subject: form.subject.trim(),
                note: form.note === '' ? null : form.note,
            });
            setForm(INITIAL);
            void applications.mutate();
        } catch {
            setSaveError(t`Der Antrag konnte nicht gespeichert werden. Prüfen Sie den Betreff und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    if (applications.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Anträge werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (applications.error || !applications.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Anträge nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Die Anträge konnten nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie die Liste erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void applications.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const items = applications.data.applications;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Anträge</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Vorgänge wie Ausgleich oder Fristverlängerung bleiben hier übersichtlich erfasst.</Trans>
                </p>

                {items.length === 0 ? (
                    <p className="opacity-70 mt-4">
                        <Trans>Noch keine Anträge erfasst. Legen Sie unten den ersten Antrag an.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Art</Trans></th>
                                    <th><Trans>Betreff</Trans></th>
                                    <th><Trans>Notiz</Trans></th>
                                    <th><Trans>Status</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((application) => (
                                    <tr key={application.id}>
                                        <td>{application.type}</td>
                                        <td>{application.subject}</td>
                                        <td>{application.note ?? '—'}</td>
                                        <td>
                                            <span className={`badge ${application.status === 'done' ? 'badge-success' : 'badge-outline'} badge-outline`}>
                                                {application.status === 'done' ? <Trans>Erledigt</Trans> : <Trans>Offen</Trans>}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Neuer Antrag</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Art</Trans></span>
                        <select className="select select-bordered" value={form.type}
                            onChange={(event) => update('type', event.target.value)} required>
                            <option value="ausgleich"><Trans>Antrag auf Ausgleich</Trans></option>
                            <option value="frist"><Trans>Antrag auf Fristverlängerung</Trans></option>
                            <option value="sonstiges"><Trans>Sonstiger Antrag</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betreff</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.subject} onChange={(event) => update('subject', event.target.value)} required/>
                    </label>
                    <label className="form-control md:col-span-2">
                        <span className="label-text"><Trans>Notiz</Trans></span>
                        <textarea className="textarea textarea-bordered" rows={2}
                            value={form.note} onChange={(event) => update('note', event.target.value)}/>
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isSaving}>
                        <Trans>Antrag speichern</Trans>
                    </button>
                    {!isValid && (
                        <p className="text-sm opacity-70 mt-2">
                            <Trans>Bitte einen Betreff angeben. Die Art ist voreingestellt und kann geändert werden.</Trans>
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
