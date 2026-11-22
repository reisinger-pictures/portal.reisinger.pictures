import { Trans } from '@lingui/react/macro';
import { Link, useSearchParams } from 'react-router-dom';
import { formatEuro } from '../../logic/formatCurrency';
import { useBrand } from '../../logic/useBrand';
import { useBillingDetails } from '../../logic/useLicenseTerms';
import { useEurEntries, useEurReference, useEurSettings, useEurSummary } from '../../logic/useEur';
import EurDepreciationPanel from './eur/EurDepreciationPanel';
import EurEntriesPanel from './eur/EurEntriesPanel';
import EurIncomeTaxPanel from './eur/EurIncomeTaxPanel';
import EurOverviewPanel from './eur/EurOverviewPanel';
import EurReferencePanel from './eur/EurReferencePanel';
import EurTravelPanel from './eur/EurTravelPanel';
import EurVatPanel from './eur/EurVatPanel';

type TabId = 'buchungen' | 'anlagen' | 'monat' | 'reisen' | 'steuer' | 'ust' | 'stammdaten';

const TABS: {id: TabId; label: string}[] = [
    {id: 'buchungen', label: 'Buchungen'},
    {id: 'anlagen', label: 'Anlagen'},
    {id: 'monat', label: 'Monat'},
    {id: 'reisen', label: 'Reisen'},
    {id: 'steuer', label: 'Steuer'},
    {id: 'ust', label: 'USt'},
    {id: 'stammdaten', label: 'Einstellungen'},
];

const MONTHS = [
    'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
    'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
];

const euro = (cents: number): string => formatEuro(cents / 100);
const display = (value: string | undefined): string => (value && value.trim() !== '' ? value : '—');

function CompanyCard({billing}: {billing: ReturnType<typeof useBillingDetails>['billingDetails']}) {
    const address = [
        billing?.company_street,
        [billing?.company_zip, billing?.company_city].filter(Boolean).join(' '),
        billing?.company_country,
    ].filter((part) => part && part.trim() !== '').join(', ');

    return (
        <div className="rounded-box border border-base-300 bg-base-100 p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold"><Trans>Unternehmensdaten</Trans></h2>
                    <p className="text-sm opacity-70"><Trans>Quelle sind die Einstellungen des Portals.</Trans></p>
                </div>
                <Link to="/settings" className="btn btn-outline btn-sm"><Trans>Bearbeiten</Trans></Link>
            </div>
            <dl className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 mt-5">
                <div><dt className="text-sm opacity-60"><Trans>Firmenname</Trans></dt><dd className="font-medium">{display(billing?.bank_holder)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>Rechtsform</Trans></dt><dd className="font-medium">{display(billing?.rechtsform)}</dd></div>
                <div className="md:col-span-2"><dt className="text-sm opacity-60"><Trans>Anschrift</Trans></dt><dd className="font-medium">{display(address)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>Branchenkennzahl</Trans></dt><dd className="font-medium">{display(billing?.branchenkennzahl)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>Steuernummer</Trans></dt><dd className="font-medium">{display(billing?.steuernummer)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>Finanzamt</Trans></dt><dd className="font-medium">{display(billing?.finanzamt)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>UID-Nummer</Trans></dt><dd className="font-medium">{display(billing?.uid)}</dd></div>
                <div><dt className="text-sm opacity-60"><Trans>Bankverbindung</Trans></dt><dd className="font-medium font-mono">{display(billing?.bank_iban)}</dd></div>
            </dl>
        </div>
    );
}

export default function ManagementEurView() {
    const {portalName} = useBrand();
    const {billingDetails} = useBillingDetails();
    const [searchParams, setSearchParams] = useSearchParams();

    const requestedTab = searchParams.get('bereich');
    const tab: TabId = TABS.some((candidate) => candidate.id === requestedTab)
        ? requestedTab as TabId
        : 'buchungen';

    const reference = useEurReference();
    const settings = useEurSettings();
    const summary = useEurSummary();
    const entries = useEurEntries();

    const allEntries = entries.data?.entries ?? [];
    const monthsWithEntries = Array.from(new Set(allEntries.map((entry) => entry.month))).sort((a, b) => b - a);
    const requestedMonth = Number(searchParams.get('monat'));
    const month = monthsWithEntries.includes(requestedMonth)
        ? requestedMonth
        : (monthsWithEntries[0] ?? new Date().getMonth() + 1);

    const monthEntries = allEntries.filter((entry) => entry.month === month);
    const monthIncome = monthEntries.filter((entry) => entry.direction === 'income').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
    const monthExpense = monthEntries.filter((entry) => entry.direction === 'expense').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
    const monthPrivate = monthEntries.reduce((sum, entry) => sum + (entry.amount_cents - entry.business_amount_cents), 0);
    const monthTotal = monthEntries.reduce((sum, entry) => sum + entry.amount_cents, 0);
    const monthBusiness = monthEntries.reduce((sum, entry) => sum + entry.business_amount_cents, 0);
    const businessPercent = monthTotal > 0 ? Math.round((monthBusiness * 100) / monthTotal) : 100;
    const monthEntryCount = monthEntries.length;

    const selectTab = (id: TabId) => {
        const next = new URLSearchParams(searchParams);
        next.set('bereich', id);
        setSearchParams(next, {replace: true});
    };

    const selectMonth = (value: number) => {
        const next = new URLSearchParams(searchParams);
        next.set('monat', String(value));
        setSearchParams(next, {replace: true});
    };

    const reloadAll = () => {
        void reference.mutate();
        void settings.mutate();
        void summary.mutate();
        void entries.mutate();
    };

    return (
        <div className="min-h-dvh bg-base-200">
            <header className="bg-base-100 border-b border-base-300">
                <div className="max-w-7xl mx-auto px-4 md:px-8 py-4 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="rounded-box bg-primary text-primary-content p-3">
                            <span className="iconify mdi--calculator text-3xl" aria-hidden="true"/>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide opacity-60"><Trans>Buchhaltung</Trans></p>
                            <h1 className="text-2xl md:text-3xl font-bold">EÜR · {portalName}</h1>
                            <p className="text-sm opacity-70">{display(billingDetails?.bank_holder)} · <Trans>Wirtschaftsjahr 2026</Trans></p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => selectTab('buchungen')}>
                            <Trans>+ Buchung</Trans>
                        </button>
                        <button type="button" className="btn btn-outline btn-sm" onClick={() => selectTab('stammdaten')}>
                            <Trans>Einstellungen</Trans>
                        </button>
                        <Link to="/" className="btn btn-ghost btn-sm"><Trans>Zurück zum Portal</Trans></Link>
                    </div>
                </div>
            </header>

            <div className="max-w-7xl mx-auto px-4 md:px-8 py-6 grid grid-cols-1 lg:grid-cols-5 gap-6">
                <aside className="lg:col-span-1">
                    <div className="rounded-box border border-base-300 bg-base-100 p-4">
                        <h2 className="font-semibold uppercase text-sm opacity-70"><Trans>Monate</Trans></h2>
                        <div className="mt-3 space-y-2">
                            {monthsWithEntries.length === 0 && (
                                <p className="text-sm opacity-70"><Trans>Noch keine Buchungen. Legen Sie im Tab Buchungen die erste an.</Trans></p>
                            )}
                            {monthsWithEntries.map((value) => {
                                const rows = allEntries.filter((entry) => entry.month === value);
                                const income = rows.filter((entry) => entry.direction === 'income').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
                                const expense = rows.filter((entry) => entry.direction === 'expense').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
                                const rowCount = rows.length;
                                return (
                                    <button
                                        key={value}
                                        type="button"
                                        className={`w-full text-left rounded-box border p-3 ${month === value ? 'border-primary bg-primary/5' : 'border-base-300'}`}
                                        onClick={() => selectMonth(value)}
                                    >
                                        <div className="flex justify-between font-medium">
                                            <span>{MONTHS[value - 1]} 2026</span>
                                            <span>{euro(income - expense)}</span>
                                        </div>
                                        <div className="text-xs opacity-60"><Trans>{rowCount} Buchungen</Trans></div>
                                    </button>
                                );
                            })}
                        </div>
                        <div className="mt-4 text-xs opacity-70">
                            <Trans>Jede Buchung wird in 5-%-Schritten zwischen privat und betrieblich aufgeteilt.</Trans>
                        </div>
                    </div>
                </aside>

                <section aria-label="Buchhaltung" className="lg:col-span-4 space-y-6">
                    <section className="rounded-box border border-base-300 bg-base-100 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-xs uppercase tracking-wide opacity-60"><Trans>Monatsübersicht</Trans></p>
                                <h2 className="text-2xl font-bold">{MONTHS[month - 1]} 2026</h2>
                                <p className="text-sm opacity-70"><Trans>{monthEntryCount} Buchungen im Monat</Trans></p>
                            </div>
                            <div className="text-right">
                                <div className="text-xs uppercase opacity-60"><Trans>Betrieblich vs. privat</Trans></div>
                                <div className="text-sm font-medium">{businessPercent} % / {100 - businessPercent} %</div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mt-5">
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Einnahmen betrieblich</Trans></div>
                                <div className="text-2xl font-bold text-success">{euro(monthIncome)}</div>
                            </div>
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Ausgaben betrieblich</Trans></div>
                                <div className="text-2xl font-bold text-error">{euro(monthExpense)}</div>
                            </div>
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Gewinn</Trans></div>
                                <div className="text-2xl font-bold text-primary">{euro(monthIncome - monthExpense)}</div>
                            </div>
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Privatanteil</Trans></div>
                                <div className="text-2xl font-bold">{euro(monthPrivate)}</div>
                            </div>
                        </div>
                    </section>

                    <div role="tablist" className="tabs tabs-box bg-base-100">
                        {TABS.map((entry) => (
                            <button
                                key={entry.id}
                                type="button"
                                role="tab"
                                aria-selected={tab === entry.id}
                                className={`tab ${tab === entry.id ? 'tab-active' : ''}`}
                                onClick={() => selectTab(entry.id)}
                            >
                                {entry.label}
                            </button>
                        ))}
                    </div>

                    {tab === 'buchungen' && (
                        <EurEntriesPanel
                            data={entries.data}
                            reference={reference.data}
                            isLoading={entries.isLoading}
                            error={entries.error}
                            onRetry={() => void entries.mutate()}
                            onCreated={reloadAll}
                        />
                    )}

                    {tab === 'anlagen' && (
                        <>
                            <EurDepreciationPanel/>
                            <div className="rounded-box border border-base-300 bg-base-100 p-5">
                                <h2 className="text-xl font-semibold"><Trans>Geringwertige Wirtschaftsgüter</Trans></h2>
                                <p className="mt-1"><Trans>Anschaffungen unter 1.000 € können Sie im Jahr des Kaufs direkt als Ausgabe erfassen.</Trans></p>
                                <a href="#eur-buchungen" className="btn btn-outline btn-sm mt-3"><Trans>Sofort als Ausgabe erfassen</Trans></a>
                            </div>
                        </>
                    )}

                    {tab === 'monat' && (
                        <div className="rounded-box border border-base-300 bg-base-100 p-6">
                            <h2 className="text-xl font-semibold">{MONTHS[month - 1]} 2026</h2>
                            <p className="text-sm opacity-70"><Trans>Zeilen des Monats aus dem Buchungsjournal.</Trans></p>
                            {monthEntries.length === 0 ? (
                                <p className="mt-4 opacity-70"><Trans>Noch keine Buchungen in diesem Monat.</Trans></p>
                            ) : (
                                <div className="overflow-x-auto mt-4">
                                    <table className="table table-sm">
                                        <thead>
                                            <tr>
                                                <th><Trans>Art</Trans></th>
                                                <th><Trans>Beleg</Trans></th>
                                                <th className="text-right"><Trans>Betrag</Trans></th>
                                                <th className="text-right"><Trans>Anteil</Trans></th>
                                                <th className="text-right"><Trans>Betrieblich</Trans></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {monthEntries.map((entry) => (
                                                <tr key={entry.id}>
                                                    <td>{entry.direction === 'income' ? <Trans>Einnahme</Trans> : <Trans>Ausgabe</Trans>} · {entry.kind_type}</td>
                                                    <td>{entry.receipt_no ?? '—'}</td>
                                                    <td className="text-right">{euro(entry.amount_cents)}</td>
                                                    <td className="text-right">{entry.business_share} %</td>
                                                    <td className="text-right">{euro(entry.business_amount_cents)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
                                <div><div className="text-sm opacity-60"><Trans>Einnahmen</Trans></div><div className="text-lg font-semibold">{euro(monthIncome)}</div></div>
                                <div><div className="text-sm opacity-60"><Trans>Ausgaben</Trans></div><div className="text-lg font-semibold">{euro(monthExpense)}</div></div>
                                <div><div className="text-sm opacity-60"><Trans>Gewinn</Trans></div><div className="text-lg font-semibold">{euro(monthIncome - monthExpense)}</div></div>
                            </div>
                        </div>
                    )}

                    {tab === 'reisen' && <EurTravelPanel reference={reference.data}/>}

                    {tab === 'steuer' && <EurIncomeTaxPanel defaultProfitCents={summary.data?.profit_cents ?? 0}/>}

                    {tab === 'ust' && <EurVatPanel/>}

                    {tab === 'stammdaten' && (
                        <>
                            <CompanyCard billing={billingDetails}/>
                            <EurReferencePanel
                                reference={reference.data}
                                isLoading={reference.isLoading}
                                error={reference.error}
                                onRetry={() => void reference.mutate()}
                            />
                        </>
                    )}

                    <EurOverviewPanel
                        summary={summary.data}
                        settings={settings.data}
                        isLoading={summary.isLoading}
                        error={summary.error}
                        onRetry={() => void summary.mutate()}
                    />
                </section>
            </div>
        </div>
    );
}
