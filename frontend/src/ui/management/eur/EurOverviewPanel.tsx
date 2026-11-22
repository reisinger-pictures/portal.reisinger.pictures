import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import type { EurSettings, EurSummary } from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

interface Props {
    summary?: EurSummary;
    settings?: EurSettings;
    isLoading: boolean;
    error: unknown;
    onRetry: () => void;
}

export default function EurOverviewPanel({summary, settings, isLoading, error, onRetry}: Props) {
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

    const cards = [
        {label: <Trans>Einnahmen</Trans>, value: cents(summary.income_cents), tone: 'text-success'},
        {label: <Trans>Ausgaben</Trans>, value: cents(summary.expense_cents), tone: 'text-error'},
        {label: <Trans>Abschreibungen</Trans>, value: cents(summary.afa_cents), tone: 'text-warning'},
        {label: <Trans>Gewinn</Trans>, value: cents(summary.profit_cents), tone: 'text-primary'},
    ];

    const year = summary.year;
    const entryCount = summary.entry_count;
    const assetCount = summary.asset_count;
    const defaultShare = settings?.default_business_share;
    const svMode = settings?.sv_mode;
    const svLabel = svMode === 'uv_only' ? 'nur Unfallversicherung' : svMode?.toUpperCase();
    const kleinunternehmerLabel = settings?.kleinunternehmer ? 'Ja' : 'Nein';

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Übersicht {year}</Trans></h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-2">
                    {cards.map((card) => (
                        <div key={card.value} className="rounded-box border border-base-300 p-4">
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
            </div>
        </div>
    );
}
