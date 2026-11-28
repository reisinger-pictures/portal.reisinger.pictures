import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { createEaHour, useEaHours } from '../../../logic/useEa';

interface Props {
    year: number;
    month: number;
}

interface FormState {
    day: string;
    hours: string;
    project: string;
    description: string;
}

const INITIAL: FormState = {day: '', hours: '', project: '', description: ''};

const formatDuration = (minutes: number): string => {
    const wholeHours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return `${wholeHours} h ${rest} min`;
};

export default function EaHoursPanel({year, month}: Props) {
    const hours = useEaHours(year, month);
    const [form, setForm] = useState<FormState>(INITIAL);
    const [isSaving, setIsSaving] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);

    const minutes = Math.round(Number(form.hours.replace(',', '.')) * 60);
    const day = Number(form.day);
    const isValid = Number.isFinite(minutes) && minutes > 0
        && (form.day === '' || (Number.isInteger(day) && day >= 1 && day <= 31));

    const update = (key: keyof FormState, value: string) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const submit = async () => {
        if (!isValid || isSaving) return;
        setIsSaving(true);
        setSaveError(null);

        try {
            await createEaHour({
                year,
                month,
                day: form.day === '' ? null : day,
                minutes,
                project: form.project === '' ? null : form.project,
                description: form.description === '' ? null : form.description,
            });
            setForm(INITIAL);
            void hours.mutate();
        } catch {
            setSaveError(t`Die Stunden konnten nicht gespeichert werden. Prüfen Sie den Zeitraum und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    if (hours.isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Stundenbuch wird geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (hours.error || !hours.data) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Stundenbuch nicht verfügbar</Trans></h2>
                    <p>
                        <Trans>Das Stundenbuch konnte nicht geladen werden. Prüfen Sie Ihre Verbindung und laden Sie es erneut.</Trans>
                    </p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => void hours.mutate()}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const entries = hours.data.hours;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Stundenbuch</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Geleistete Stunden im Monat. Zeiten werden als Stunden und Minuten angezeigt.</Trans>
                </p>

                <div className="rounded-box border border-base-300 p-4 mt-4">
                    <div className="text-sm opacity-60"><Trans>Summe im Monat</Trans></div>
                    <div className="text-2xl font-bold">{formatDuration(hours.data.total_minutes)}</div>
                </div>

                {entries.length === 0 ? (
                    <p className="opacity-70 mt-4">
                        <Trans>Noch keine Stunden erfasst. Tragen Sie unten die erste Leistung ein.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-4">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Tag</Trans></th>
                                    <th><Trans>Dauer</Trans></th>
                                    <th><Trans>Projekt</Trans></th>
                                    <th><Trans>Beschreibung</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td>{entry.day ?? '—'}</td>
                                        <td>{formatDuration(entry.minutes)}</td>
                                        <td>{entry.project ?? '—'}</td>
                                        <td>{entry.description ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Stunden eintragen</Trans></h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Tag</Trans></span>
                        <input type="number" min={1} max={31} className="input input-bordered"
                            value={form.day} onChange={(event) => update('day', event.target.value)}/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Stunden</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered"
                            value={form.hours} onChange={(event) => update('hours', event.target.value)} required/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Projekt</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.project} onChange={(event) => update('project', event.target.value)}/>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Beschreibung</Trans></span>
                        <input type="text" className="input input-bordered"
                            value={form.description} onChange={(event) => update('description', event.target.value)}/>
                    </label>
                </div>

                {saveError && (
                    <div className="alert alert-error mt-4" role="alert"><span>{saveError}</span></div>
                )}

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isSaving}>
                        <Trans>Stunden speichern</Trans>
                    </button>
                    {!isValid && (
                        <p className="text-sm opacity-70 mt-2">
                            <Trans>Geben Sie einen positiven Zeitwert an; der Tag ist optional.</Trans>
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
