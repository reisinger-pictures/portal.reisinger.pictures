import { useState } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    calculateEurTravel,
    type EurReference,
    type EurTravelResult,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    reference?: EurReference;
}

interface FormState {
    vehicle: string;
    kilometres: string;
    passengers: string;
    country: string;
    days: string;
    meals: string;
    withReceipts: boolean;
    businessShare: string;
}

const INITIAL: FormState = {
    vehicle: '',
    kilometres: '100',
    passengers: '0',
    country: 'Österreich',
    days: '1',
    meals: '0',
    withReceipts: true,
    businessShare: '100',
};

export default function EurTravelPanel({reference}: Props) {
    const [form, setForm] = useState<FormState>(INITIAL);
    const [result, setResult] = useState<EurTravelResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);

    const vehicles = reference?.vehicle_types ?? [];
    const countries = reference?.travel.countries ?? [];
    const vehicle = form.vehicle === '' ? (vehicles[0]?.name ?? '') : form.vehicle;

    const update = (key: keyof FormState, value: string | boolean) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const isValid = vehicle !== ''
        && Number(form.kilometres) >= 0
        && Number(form.days) >= 1
        && Number(form.businessShare) % 5 === 0;

    const submit = async () => {
        if (!isValid || isLoading) return;

        setIsLoading(true);
        setError(null);

        try {
            const response = await calculateEurTravel({
                year: 2026,
                vehicle,
                kilometres: Number(form.kilometres),
                passengers: Number(form.passengers),
                country: form.country,
                days: Number(form.days),
                meals: Number(form.meals),
                with_receipts: form.withReceipts,
                business_share: Number(form.businessShare),
            });
            setResult(response);
        } catch {
            setResult(null);
            setError('Die Reisekosten konnten nicht berechnet werden. Prüfen Sie Fahrzeug, Land und Zeitraum und versuchen Sie es erneut.');
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Reisekosten</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Kilometergeld, Tagesgeld und Nächtigung mit Betriebsanteil. Ab dem 5. Tag entfällt das Tagesgeld, ab dem 15. Tag die Nächtigung.</Trans>
                </p>

                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Fahrzeug</Trans></span>
                        <select
                            className="select select-bordered"
                            value={vehicle}
                            onChange={(event) => update('vehicle', event.target.value)}
                            required
                        >
                            {vehicles.map((entry) => (
                                <option key={entry.name} value={entry.name}>{entry.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Kilometer</Trans></span>
                        <input
                            type="number"
                            min={0}
                            className="input input-bordered"
                            value={form.kilometres}
                            onChange={(event) => update('kilometres', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Mitfahrer</Trans></span>
                        <input
                            type="number"
                            min={0}
                            max={6}
                            className="input input-bordered"
                            value={form.passengers}
                            onChange={(event) => update('passengers', event.target.value)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Reiseziel</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.country}
                            onChange={(event) => update('country', event.target.value)}
                            required
                        >
                            {countries.map((entry) => (
                                <option key={entry.country} value={entry.country}>{entry.country}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Tage</Trans></span>
                        <input
                            type="number"
                            min={1}
                            className="input input-bordered"
                            value={form.days}
                            onChange={(event) => update('days', event.target.value)}
                            required
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Mahlzeiten</Trans></span>
                        <select
                            className="select select-bordered"
                            value={form.meals}
                            onChange={(event) => update('meals', event.target.value)}
                        >
                            <option value="0"><Trans>keine</Trans></option>
                            <option value="1"><Trans>eine</Trans></option>
                            <option value="2"><Trans>zwei</Trans></option>
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Belege vorhanden</Trans></span>
                        <input
                            type="checkbox"
                            className="toggle toggle-primary mt-2"
                            checked={form.withReceipts}
                            onChange={(event) => update('withReceipts', event.target.checked)}
                        />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil (%)</Trans></span>
                        <input
                            type="number"
                            min={0}
                            max={100}
                            step={5}
                            className="input input-bordered"
                            value={form.businessShare}
                            onChange={(event) => update('businessShare', event.target.value)}
                            required
                        />
                    </label>
                </div>

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={submit} disabled={!isValid || isLoading}>
                        <Trans>Reisekosten berechnen</Trans>
                    </button>
                </div>

                {error && (
                    <div className="alert alert-error mt-4" role="alert">
                        <span>{error}</span>
                    </div>
                )}

                {result && (
                    <dl className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Kilometergeld</Trans></dt>
                            <dd className="text-xl font-semibold">{cents(result.kilometre_cents)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Tagesgeld</Trans></dt>
                            <dd className="text-xl font-semibold">{cents(result.day_cents)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Nächtigung</Trans></dt>
                            <dd className="text-xl font-semibold">{cents(result.night_cents)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Betrieblicher Anteil</Trans></dt>
                            <dd className="text-xl font-semibold text-primary">{cents(result.business_cents)}</dd>
                        </div>
                    </dl>
                )}
            </div>
        </div>
    );
}
