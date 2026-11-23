import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    EUR_MONTHS,
    type EurSettings,
    type EurSummary,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

/** Mode codes stay uppercase abbreviations; the phrase is never uppercased. */
function svModeLabel(mode: string | undefined): string {
    switch (mode) {
        case 'uv_only': return t`nur Unfallversicherung`;
        case 'gsvg': return t`GSVG`;
        case 'ns_gsvg': return t`NS_GSVG`;
        case 'fsvg': return t`FSVG`;
        default: return mode ? mode.toUpperCase() : t`Unbekannt`;
    }
}

const SV_MODES = ['gsvg', 'fsvg', 'ns_gsvg', 'uv_only'] as const;

interface Props {
    summary?: EurSummary;
    settings?: EurSettings;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
}

export default function EurOverviewPanel({summary, settings, isLoading, error, onRetry}: Props) {
    const [customerFilter, setCustomerFilter] = useState('');
    const [projectFilter, setProjectFilter] = useState('');
    const [svModeFilter, setSvModeFilter] = useState('');

    if (isLoading) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="status">
                <div className="card-body p-6">
                    <span className="loading loading-spinner loading-md" aria-hidden="true"/>
                    <p className="mt-2"><Trans>Übersicht wird geladen …</Trans></p>
                </div>
            </div>
        );
    }

    if (error || !summary) {
        return (
            <div className="card bg-base-100 border border-base-300 shadow-sm" role="alert">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary"><Trans>Übersicht nicht verfügbar</Trans></h2>
                    <p><Trans>Die Buchhaltungsdaten konnten nicht geladen werden. Prüfen Sie Ihre Verbindung und versuchen Sie es erneut.</Trans></p>
                    <div>
                        <button type="button" className="btn btn-primary btn-sm" onClick={onRetry}>
                            <Trans>Erneut laden</Trans>
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    // Der formatierte Betrag ist kein Schlüssel: ohne Buchungen sind alle vier
    // Karten „0,00 €“ und React meldet doppelte Keys. Die Bedeutung bleibt
    // stabil, unabhängig vom Wert.
    const cards = [
        {id: 'income', label: <Trans>Einnahmen</Trans>, value: cents(summary.income_cents), tone: 'text-success'},
        {id: 'expense', label: <Trans>Ausgaben</Trans>, value: cents(summary.expense_cents), tone: 'text-error'},
        {id: 'afa', label: <Trans>Abschreibungen</Trans>, value: cents(summary.afa_cents), tone: 'text-warning'},
        {id: 'profit', label: <Trans>Gewinn</Trans>, value: cents(summary.profit_cents), tone: 'text-primary'},
    ];

    const year = summary.year;
    const entryCount = summary.entry_count;
    const assetCount = summary.asset_count;
    const defaultShare = settings?.default_business_share;
    const svMode = settings?.sv_mode;
    const svLabel = svModeLabel(svMode);
    const kleinunternehmerLabel = settings?.kleinunternehmer ? t`Ja` : t`Nein`;

    const svMonthly = summary.sv_monthly ?? [];
    const insuranceBlocks = summary.insurance_blocks ?? [];
    const afaCard = summary.afa_card;
    const byCustomer = summary.by_customer ?? [];

    const customers = Array.from(new Set(byCustomer.map((row) => row.customer))).sort();
    const projects = Array.from(new Set(byCustomer.map((row) => row.project))).sort();
    const visibleCustomerRows = byCustomer.filter((row) =>
        (customerFilter === '' || row.customer === customerFilter)
        && (projectFilter === '' || row.project === projectFilter),
    );
    const visibleInsuranceBlocks = insuranceBlocks.filter((row) => {
        if (svModeFilter === '') return true;
        const label = `${row.label} ${svModeFilter} ${svModeLabel(svModeFilter)}`.toLowerCase();
        return label.includes(svModeFilter.toLowerCase()) || label.includes(svModeLabel(svModeFilter).toLowerCase());
    });

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Übersicht {year}</Trans></h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-2">
                    {cards.map((card) => (
                        <div key={card.id} className="rounded-box border border-base-300 p-4">
                            <div className="text-sm opacity-60">{card.label}</div>
                            <div className={`text-2xl font-bold ${card.tone}`}>{card.value}</div>
                        </div>
                    ))}
                </div>
                <p className="text-sm opacity-70 mt-4">
                    <Trans>{entryCount} Buchungen und {assetCount} Anlagegüter im Wirtschaftsjahr.</Trans>
                </p>
                {settings && (
                    <p className="text-sm opacity-70">
                        <Trans>Standard-Betriebsanteil: {defaultShare} %, Sozialversicherung: {svLabel}, Kleinunternehmer: {kleinunternehmerLabel}.</Trans>
                    </p>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Sozialversicherung je Monat</Trans></h3>
                {svMonthly.length === 0 ? (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Für dieses Jahr sind keine monatlichen Sozialversicherungswerte hinterlegt.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-2">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Monat</Trans></th>
                                    <th className="text-right"><Trans>KV</Trans></th>
                                    <th className="text-right"><Trans>PV</Trans></th>
                                    <th className="text-right"><Trans>SVS</Trans></th>
                                    <th className="text-right"><Trans>UV</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {svMonthly.map((row) => (
                                    <tr key={row.month}>
                                        <td>{EUR_MONTHS[row.month - 1] ?? row.month}</td>
                                        <td className="text-right">{cents(row.kv_cents)}</td>
                                        <td className="text-right">{cents(row.pv_cents)}</td>
                                        <td className="text-right">{cents(row.svs_cents)}</td>
                                        <td className="text-right">{cents(row.uv_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="divider my-4"/>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="font-semibold"><Trans>Weitere Versicherungen</Trans></h3>
                    <label className="form-control">
                        <span className="label-text"><Trans>SV-Modus</Trans></span>
                        <select
                            className="select select-bordered select-sm"
                            value={svModeFilter}
                            onChange={(event) => setSvModeFilter(event.target.value)}
                        >
                            <option value=""><Trans>Alle Modi</Trans></option>
                            {SV_MODES.map((mode) => (
                                <option key={mode} value={mode}>{svModeLabel(mode)}</option>
                            ))}
                        </select>
                    </label>
                </div>
                {visibleInsuranceBlocks.length === 0 ? (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Keine weiteren Versicherungen für diesen Filter hinterlegt.</Trans>
                    </p>
                ) : (
                    <ul className="mt-2 space-y-2">
                        {visibleInsuranceBlocks.map((block) => (
                            <li key={block.label} className="flex flex-wrap items-center justify-between rounded-box border border-base-300 p-3">
                                <span>{block.label}</span>
                                <span className="font-semibold">{cents(block.monthly_cents)} <Trans>monatlich</Trans></span>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="divider my-4"/>

                <h3 className="font-semibold"><Trans>Abschreibungen</Trans></h3>
                {afaCard ? (
                    <dl className="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-2">
                        <div className="rounded-box border border-base-300 p-3">
                            <dt className="text-xs opacity-60"><Trans>Betriebliche AfA</Trans></dt>
                            <dd className="font-semibold">{cents(afaCard.booking_cents)}</dd>
                        </div>
                        <div className="rounded-box border border-base-300 p-3">
                            <dt className="text-xs opacity-60"><Trans>Privatanteil</Trans></dt>
                            <dd className="font-semibold">{cents(afaCard.private_cents)}</dd>
                        </div>
                        <div className="rounded-box border border-base-300 p-3">
                            <dt className="text-xs opacity-60"><Trans>Restbuchwert</Trans></dt>
                            <dd className="font-semibold">{cents(afaCard.residual_cents)}</dd>
                        </div>
                    </dl>
                ) : (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Für dieses Jahr sind keine AfA-Werte hinterlegt.</Trans>
                    </p>
                )}

                <div className="divider my-4"/>

                <div className="flex flex-wrap items-end gap-3">
                    <h3 className="font-semibold mr-auto"><Trans>Kunden und Projekte</Trans></h3>
                    <label className="form-control">
                        <span className="label-text"><Trans>Kunde</Trans></span>
                        <select
                            className="select select-bordered select-sm"
                            value={customerFilter}
                            onChange={(event) => setCustomerFilter(event.target.value)}
                        >
                            <option value=""><Trans>Alle Kunden</Trans></option>
                            {customers.map((customer) => (
                                <option key={customer} value={customer}>{customer}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Projekt</Trans></span>
                        <select
                            className="select select-bordered select-sm"
                            value={projectFilter}
                            onChange={(event) => setProjectFilter(event.target.value)}
                        >
                            <option value=""><Trans>Alle Projekte</Trans></option>
                            {projects.map((project) => (
                                <option key={project} value={project}>{project}</option>
                            ))}
                        </select>
                    </label>
                </div>
                {visibleCustomerRows.length === 0 ? (
                    <p className="text-sm opacity-70 mt-1">
                        <Trans>Keine Kunden- oder Projektdaten für diesen Filter vorhanden.</Trans>
                    </p>
                ) : (
                    <div className="overflow-x-auto mt-2">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Kunde</Trans></th>
                                    <th><Trans>Projekt</Trans></th>
                                    <th className="text-right"><Trans>Umsatz</Trans></th>
                                    <th className="text-right"><Trans>Kosten</Trans></th>
                                    <th className="text-right"><Trans>Deckungsbeitrag</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {visibleCustomerRows.map((row) => (
                                    <tr key={`${row.customer}-${row.project}`}>
                                        <td>{row.customer}</td>
                                        <td>{row.project}</td>
                                        <td className="text-right">{cents(row.revenue_cents)}</td>
                                        <td className="text-right">{cents(row.cost_cents)}</td>
                                        <td className="text-right font-semibold">{cents(row.margin_cents)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
