import type { ReactNode } from 'react';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import type { EaReference } from '../../../logic/useEa';

const cents = (value: number): string => formatEuro(value / 100);

interface UsageListProps {
    heading: ReactNode;
    kinds: {type: string}[];
    counts: Record<string, number>;
    cap: number;
}

/** EUE-029: ❯ = ungenutzt, Zahl = Verwendungen, Obergrenze als "99+"/"999+". */
function UsageList({heading, kinds, counts, cap}: UsageListProps) {
    const label = (count: number): string => count === 0 ? '❯' : count > cap ? `${cap}+` : String(count);

    return (
        <div>
            <h4 className="font-semibold">{heading}</h4>
            <ul className="mt-1 space-y-0.5 text-sm">
                {kinds.map((kind) => (
                    <li key={kind.type} className="flex justify-between gap-3">
                        <span>{kind.type}</span>
                        <span className="tabular-nums opacity-70">{label(counts[kind.type] ?? 0)}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

interface Props {
    reference?: EaReference;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
}

export default function EaReferencePanel({reference, isLoading, error, onRetry}: Props) {
    if (isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Referenzdaten werden geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (error || !reference) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Referenzdaten nicht verfügbar</Trans></h2>
                    <p><Trans>Ohne die gepinnten Referenzdaten lassen sich Beträge nicht belastbar berechnen.</Trans></p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={onRetry}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    const catalogFacts = [
        {label: <Trans>Ausgaben-Arten</Trans>, value: reference.expense_kinds.length},
        {label: <Trans>Einnahmen-Arten</Trans>, value: reference.income_kinds.length},
        {label: <Trans>Umsatzsteuer-Arten</Trans>, value: reference.vat_kinds.length},
        {label: <Trans>Fahrzeugtypen</Trans>, value: reference.vehicle_types.length},
        {label: <Trans>Reiseziele</Trans>, value: reference.travel.countries.length},
        {label: <Trans>Feiertage</Trans>, value: reference.holidays.length},
    ];

    const businessYear = reference.business_year;
    const domesticDay = cents(reference.travel.domestic.day_cents);
    const domesticNight = cents(reference.travel.domestic.night_cents);
    const allowanceLimit = cents(reference.income_tax.basic_allowance.limit_cents);
    const allowanceRate = formatPercent(reference.income_tax.basic_allowance.rate_per_10000 / 100);
    const allowanceMaximum = cents(reference.income_tax.basic_allowance.maximum_cents);

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Stammdaten und Kataloge</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Alle Werte gelten für das Wirtschaftsjahr {businessYear} und sind serverseitig gepinnt.</Trans>
                </p>

                <div className="grid grid-cols-2 md:grid-cols-3 gap-4 mt-2">
                    {catalogFacts.map((fact) => (
                        <div key={String(fact.label)} className="rounded-box border border-base-300 p-3">
                            <div className="text-sm opacity-60">{fact.label}</div>
                            <div className="text-xl font-bold">{fact.value}</div>
                        </div>
                    ))}
                </div>

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Reise-Pauschalen</Trans></h3>
                <p className="text-sm">
                    <Trans>Inland: {domesticDay} Tag und {domesticNight} Nächtigung.</Trans>
                </p>

                <h3 className="font-semibold mt-4"><Trans>Grundfreibetrag</Trans></h3>
                <p className="text-sm">
                    <Trans>Bis {allowanceLimit} Gewinn, {allowanceRate}, höchstens {allowanceMaximum}.</Trans>
                </p>

                <h3 className="font-semibold mt-4"><Trans>Feiertage</Trans></h3>
                <ul className="flex flex-wrap gap-2 mt-1 text-sm">
                    {reference.holidays.map((holiday) => (
                        <li key={holiday.date} className="badge badge-outline">
                            {holiday.date.slice(5)} · {holiday.name}
                        </li>
                    ))}
                </ul>

                {reference.usage_counts && (
                    <>
                        <h3 className="font-semibold mt-4"><Trans>Verwendungszähler</Trans></h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-1">
                            <UsageList
                                heading={<Trans>Ausgaben-Arten</Trans>}
                                kinds={reference.expense_kinds}
                                counts={reference.usage_counts.expense}
                                cap={99}
                            />
                            <UsageList
                                heading={<Trans>Einnahmen-Arten</Trans>}
                                kinds={reference.income_kinds}
                                counts={reference.usage_counts.income}
                                cap={999}
                            />
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
