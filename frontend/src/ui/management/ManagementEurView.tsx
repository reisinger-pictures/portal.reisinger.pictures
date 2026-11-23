import { useEffect, useState, type ReactNode } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useSearchParams } from 'react-router-dom';
import { formatEuro } from '../../logic/formatCurrency';
import { useBrand } from '../../logic/useBrand';
import { useBillingDetails } from '../../logic/useLicenseTerms';
import {
    EUR_MONTHS as MONTHS,
    createEurEigenbeleg,
    createEurMonth,
    createEurYear,
    eurPdfAssetsUrl,
    eurPdfEigenbelegUrl,
    eurPdfMonthUrl,
    saveEurSettings,
    useEurDocuments,
    useEurEntries,
    useEurHours,
    useEurMonth,
    useEurReference,
    useEurSettings,
    useEurSummary,
    useEurYears,
    type EurSettings,
} from '../../logic/useEur';
import EurAnalyticsPanel from './eur/EurAnalyticsPanel';
import EurApplicationsPanel from './eur/EurApplicationsPanel';
import EurAssetsPanel from './eur/EurAssetsPanel';
import EurDepreciationPanel from './eur/EurDepreciationPanel';
import EurDocumentsPanel from './eur/EurDocumentsPanel';
import EurEntriesPanel from './eur/EurEntriesPanel';
import EurEntryDialog from './eur/EurEntryDialog';
import EurHoursPanel from './eur/EurHoursPanel';
import EurIncomeTaxPanel from './eur/EurIncomeTaxPanel';
import EurMonthPanel from './eur/EurMonthPanel';
import EurOverviewPanel from './eur/EurOverviewPanel';
import EurReferencePanel from './eur/EurReferencePanel';
import EurSignaturePanel from './eur/EurSignaturePanel';
import EurTravelPanel from './eur/EurTravelPanel';
import EurVatPanel from './eur/EurVatPanel';
import EurWarehousePanel from './eur/EurWarehousePanel';

type TabId = 'buchungen' | 'wareneingang' | 'belege' | 'anlagen' | 'monat'
    | 'auswertungen' | 'reisen' | 'steuer' | 'ust' | 'antraege' | 'signatur' | 'einstellungen';

const TABS: TabId[] = [
    'buchungen', 'wareneingang', 'belege', 'anlagen', 'monat', 'auswertungen',
    'reisen', 'steuer', 'ust', 'antraege', 'signatur', 'einstellungen',
];

function tabLabel(id: TabId): ReactNode {
    switch (id) {
        case 'buchungen': return <Trans>Buchungen</Trans>;
        case 'wareneingang': return <Trans>Wareneingang</Trans>;
        case 'belege': return <Trans>Belege</Trans>;
        case 'anlagen': return <Trans>Anlagen</Trans>;
        case 'monat': return <Trans>Monat</Trans>;
        case 'auswertungen': return <Trans>Auswertungen</Trans>;
        case 'reisen': return <Trans>Reisen</Trans>;
        case 'steuer': return <Trans>Steuer</Trans>;
        case 'ust': return <Trans>USt</Trans>;
        case 'antraege': return <Trans>Anträge</Trans>;
        case 'signatur': return <Trans>Signatur</Trans>;
        case 'einstellungen': return <Trans>Einstellungen</Trans>;
    }
}

const YEARS = [2024, 2025, 2026];
const DEFAULT_YEAR = 2026;
const BUSINESS_SHARES = Array.from({length: 21}, (_, index) => index * 5);

/** Same rounding as the booking dialog, including the finite/non-negative guards. */
const eigenbelegCentsOrNull = (value: string): number | null => {
    if (value.trim() === '') return null;
    const amount = Number(value.replace(',', '.'));
    if (!Number.isFinite(amount)) return null;
    const cents = Math.round(amount * 100);
    return Number.isFinite(cents) && cents >= 0 ? cents : null;
};

// Called inside the component: a `t` macro at module scope would evaluate
// before i18n.activate() and crash.
const createEigenbelegSchema = () => z.object({
    ebDay: z.string().refine((value) => {
        const day = Number(value);
        return value.trim() !== '' && Number.isInteger(day) && day >= 1 && day <= 31;
    }, t`Tag zwischen 1 und 31 angeben`),
    ebDescription: z.string().trim().min(1, t`Beschreibung angeben`),
    ebAmount: z.string().refine((value) => eigenbelegCentsOrNull(value) !== null,
        t`Bitte einen gültigen Betrag angeben`),
    ebShare: z.string().refine((value) => {
        if (value.trim() === '') return false;
        const share = Number(value);
        return Number.isInteger(share) && share >= 0 && share <= 100 && share % 5 === 0;
    }, t`Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben`),
});

type EigenbelegFormValues = z.infer<ReturnType<typeof createEigenbelegSchema>>;

const euro = (cents: number): string => formatEuro(cents / 100);
const display = (value: string | undefined): string => (value && value.trim() !== '' ? value : '—');

function signatureStatusLabel(status: string): string {
    switch (status) {
        case 'signed': return t`vorhanden`;
        case 'pending': return t`ausstehend`;
        case 'failed': return t`fehlgeschlagen`;
        case 'none': return t`keine`;
        default: return t`unbekannt`;
    }
}

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

/** Save form for the EAR settings; `key` on the parent remounts it when settings load. */
function EurSettingsForm({settings, onSaved}: {settings?: EurSettings; onSaved: () => void}) {
    const [share, setShare] = useState(String(settings?.default_business_share ?? 100));
    const [svMode, setSvMode] = useState(settings?.sv_mode ?? 'gsvg');
    const [kleinunternehmer, setKleinunternehmer] = useState(settings?.kleinunternehmer ?? false);
    const [isSaving, setIsSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const shareNumber = Number(share.replace(',', '.'));
    const isValidShare = Number.isInteger(shareNumber) && shareNumber >= 0 && shareNumber <= 100 && shareNumber % 5 === 0;

    const submit = async () => {
        if (!isValidShare || isSaving) return;
        setIsSaving(true);
        setMessage(null);
        setError(null);

        try {
            await saveEurSettings({default_business_share: shareNumber, sv_mode: svMode, kleinunternehmer});
            setMessage(t`Die EAR-Einstellungen wurden gespeichert.`);
            onSaved();
        } catch {
            setError(t`Die EAR-Einstellungen konnten nicht gespeichert werden. Prüfen Sie die Eingaben und versuchen Sie es erneut.`);
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div className="rounded-box border border-base-300 bg-base-100 p-6">
            <h2 className="text-xl font-semibold"><Trans>EAR-Einstellungen speichern</Trans></h2>
            <p className="text-sm opacity-70">
                <Trans>Standard-Betriebsanteil und Sozialversicherung gelten als Vorschlag für neue Buchungen.</Trans>
            </p>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 items-end">
                <label className="form-control">
                    <span className="label-text"><Trans>Standard-Betriebsanteil (%)</Trans></span>
                    <input
                        type="number"
                        className="input input-bordered"
                        min={0}
                        max={100}
                        step={5}
                        value={share}
                        onChange={(event) => setShare(event.target.value)}
                        required
                    />
                </label>
                <label className="form-control">
                    <span className="label-text"><Trans>SV-Modus</Trans></span>
                    <select
                        className="select select-bordered"
                        value={svMode}
                        onChange={(event) => setSvMode(event.target.value)}
                        required
                    >
                        <option value="gsvg"><Trans>GSVG</Trans></option>
                        <option value="fsvg"><Trans>FSVG</Trans></option>
                        <option value="ns_gsvg"><Trans>Neue Selbständige (GSVG)</Trans></option>
                        <option value="uv_only"><Trans>nur Unfallversicherung</Trans></option>
                    </select>
                </label>
                <label className="label cursor-pointer justify-start gap-3">
                    <input
                        type="checkbox"
                        className="checkbox"
                        checked={kleinunternehmer}
                        onChange={(event) => setKleinunternehmer(event.target.checked)}
                    />
                    <span className="label-text"><Trans>Kleinunternehmer</Trans></span>
                </label>
                <div className="md:col-span-3">
                    <button type="button" className="btn btn-primary btn-sm" onClick={() => void submit()}
                        disabled={!isValidShare || isSaving}>
                        <Trans>Einstellungen speichern</Trans>
                    </button>
                </div>
            </div>
            {message && <p className="alert alert-success mt-4" role="status">{message}</p>}
            {error && <p className="alert alert-error mt-4" role="alert">{error}</p>}
        </div>
    );
}

export default function ManagementEurView() {
    const {portalName} = useBrand();
    const {billingDetails} = useBillingDetails();
    const [searchParams, setSearchParams] = useSearchParams();
    const [isEntryDialogOpen, setIsEntryDialogOpen] = useState(false);
    const [monthMessage, setMonthMessage] = useState<string | null>(null);
    const [monthError, setMonthError] = useState<string | null>(null);
    const [yearMessage, setYearMessage] = useState<string | null>(null);
    const [yearError, setYearError] = useState<string | null>(null);
    const [isEigenbelegOpen, setIsEigenbelegOpen] = useState(false);
    const [ebMessage, setEbMessage] = useState<string | null>(null);
    const [ebError, setEbError] = useState<string | null>(null);
    const [lastEigenbeleg, setLastEigenbeleg] = useState<{description: string; amountCents: number} | null>(null);
    const eigenbelegSchema = createEigenbelegSchema();
    const {register, setValue, handleSubmit, formState: {errors: eigenbelegErrors}} = useForm<EigenbelegFormValues>({
        resolver: zodResolver(eigenbelegSchema),
        defaultValues: {
            ebDay: String(new Date().getDate()),
            ebDescription: '',
            ebAmount: '',
            ebShare: '100',
        },
        mode: 'onTouched',
    });

    // The bookkeeping PWA uses its own manifest while this view is mounted.
    useEffect(() => {
        const manifestLink = document.querySelector<HTMLLinkElement>('link[rel="manifest"]');
        if (!manifestLink) return;
        const previousHref = manifestLink.getAttribute('href') ?? '';
        manifestLink.setAttribute('href', '/steuern/manifest.webmanifest');
        return () => {
            manifestLink.setAttribute('href', previousHref);
        };
    }, []);

    const requestedTab = searchParams.get('bereich');
    const tab: TabId = TABS.some((candidate) => candidate === requestedTab)
        ? requestedTab as TabId
        : 'buchungen';

    const years = useEurYears();
    const activeYears = years.data?.active_years ?? [];
    const currentYear = new Date().getFullYear();
    // Ein Jahr erscheint nur, wenn es Buchungen gibt oder es das laufende Jahr ist.
    // Die API liefert die Werte; YEARS/DEFAULT_YEAR bleiben der Offline-Fallback.
    const supportedYears = years.data?.supported ?? YEARS;
    const defaultYear = years.data?.default_year ?? DEFAULT_YEAR;
    const visibleYears = supportedYears.filter((value) => activeYears.includes(value) || value === currentYear);

    const requestedYear = Number(searchParams.get('jahr'));
    // Fällt der API-Default aus dem sichtbaren Bereich, gilt das laufende Jahr
    // bzw. das erste sichtbare Jahr — nie eine Jahreszahl ohne Auswahloption.
    const fallbackYear = visibleYears.includes(defaultYear)
        ? defaultYear
        : (visibleYears.includes(currentYear) ? currentYear : (visibleYears[0] ?? defaultYear));
    const year = visibleYears.includes(requestedYear) ? requestedYear : fallbackYear;

    const reference = useEurReference();
    const settings = useEurSettings();
    const summary = useEurSummary(year);
    const entries = useEurEntries(year);

    const allEntries = entries.data?.entries ?? [];
    const monthsWithEntries = Array.from(new Set(allEntries.map((entry) => entry.month))).sort((a, b) => b - a);
    const requestedMonth = Number(searchParams.get('monat'));
    const month = monthsWithEntries.includes(requestedMonth)
        ? requestedMonth
        : (monthsWithEntries[0] ?? new Date().getMonth() + 1);

    const monthData = useEurMonth(year, month);
    const documents = useEurDocuments(year);
    const hours = useEurHours(year, month);

    const monthEntries = allEntries.filter((entry) => entry.month === month);
    const monthIncome = monthEntries.filter((entry) => entry.direction === 'income').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
    const monthExpense = monthEntries.filter((entry) => entry.direction === 'expense').reduce((sum, entry) => sum + entry.business_amount_cents, 0);
    const monthPrivate = monthEntries.reduce((sum, entry) => sum + (entry.amount_cents - entry.business_amount_cents), 0);
    const monthEntryCount = monthEntries.length;
    const businessTotal = monthIncome + monthExpense;
    const documentsForMonth = (documents.data?.documents ?? []).filter((document) => document.month === month).length;
    const totalMinutes = hours.data?.total_minutes ?? 0;
    const hoursText = `${Math.floor(totalMinutes / 60)} h ${totalMinutes % 60} min`;
    const openDocuments = summary.data?.open_documents ?? 0;
    const signatureStatus = summary.data?.signature_status ?? monthData.data?.signature.status ?? 'none';
    const isSigned = signatureStatus === 'signed';
    const monthIncomeLabel = euro(monthIncome);
    const monthExpenseLabel = euro(monthExpense);
    const monthPrivateLabel = euro(monthPrivate);
    const signatureStatusText = signatureStatusLabel(signatureStatus);

    // Functional updater form, as in every other management view: a captured
    // `searchParams` snapshot can be stale when several URL-driven controls are
    // clicked in quick succession (CI showed a tab staying unselected under
    // load). Updating from `prev` makes each write independent of render timing.
    const selectTab = (id: TabId) => {
        setSearchParams((previous) => {
            const next = new URLSearchParams(previous);
            next.set('bereich', id);
            return next;
        }, {replace: true});
    };

    const selectYear = (value: number) => {
        setSearchParams((previous) => {
            const next = new URLSearchParams(previous);
            next.set('jahr', String(value));
            return next;
        }, {replace: true});
    };

    const selectMonth = (value: number) => {
        setSearchParams((previous) => {
            const next = new URLSearchParams(previous);
            next.set('monat', String(value));
            return next;
        }, {replace: true});
    };

    const reloadAll = () => {
        void reference.mutate();
        void settings.mutate();
        void summary.mutate();
        void entries.mutate();
        void monthData.mutate();
        void documents.mutate();
        void hours.mutate();
    };

    const addMonth = async () => {
        setMonthMessage(null);
        setMonthError(null);
        try {
            const result = await createEurMonth({year, month});
            setMonthMessage(result.created
                ? t`Der Monat ${month}/${year} wurde angelegt.`
                : t`Der Monat ${month}/${year} war bereits vorhanden.`);
            reloadAll();
        } catch {
            setMonthError(t`Der Monat konnte nicht angelegt werden. Versuchen Sie es erneut.`);
        }
    };

    const addYear = async () => {
        setYearMessage(null);
        setYearError(null);
        try {
            const result = await createEurYear({year});
            const count = typeof result.created === 'number' ? result.created : result.months.length;
            setYearMessage(t`Das Jahr ${year} ist angelegt (${count} Monate).`);
            reloadAll();
        } catch {
            setYearError(t`Das Jahr konnte nicht angelegt werden. Versuchen Sie es erneut.`);
        }
    };

    const rejectEigenbeleg = () => {
        setEbError(t`Bitte geben Sie Tag, Beschreibung und einen Betrag größer als null an.`);
        setEbMessage(null);
    };

    const saveEigenbeleg = async (values: EigenbelegFormValues) => {
        const day = Number(values.ebDay);
        const amountCents = Math.round(Number(values.ebAmount.replace(',', '.')) * 100);
        const share = Number(values.ebShare);
        const description = values.ebDescription.trim();

        setEbError(null);
        setEbMessage(null);
        try {
            await createEurEigenbeleg({
                year,
                month,
                day,
                description,
                amount_cents: amountCents,
                business_share: share,
            });
            setLastEigenbeleg({description, amountCents});
            setEbMessage(t`Der Eigenbeleg wurde erfasst.`);
            setValue('ebDescription', '');
            setValue('ebAmount', '');
            reloadAll();
        } catch {
            setEbError(t`Der Eigenbeleg konnte nicht erfasst werden. Prüfen Sie die Angaben und versuchen Sie es erneut.`);
        }
    };

    return (
        <div className="min-h-dvh bg-base-200">
            <header className="bg-base-100 border-b border-base-300">
                <div className="max-w-7xl mx-auto px-4 md:px-8 py-4 flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="flex size-14 shrink-0 items-center justify-center rounded-box bg-primary text-primary-content">
                            <span className="iconify mdi--calculator text-3xl" aria-hidden="true"/>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide opacity-60"><Trans>Buchhaltung</Trans></p>
                            <h1 className="text-2xl md:text-3xl font-bold"><Trans>Einnahmen Ausgaben Rechnung</Trans>{' '}<span className="whitespace-nowrap"><Trans>· {portalName}</Trans></span></h1>
                            <p className="text-sm opacity-70">{display(billingDetails?.bank_holder)} · <Trans>Wirtschaftsjahr {year}</Trans></p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-2 text-sm">
                            <span><Trans>Jahr</Trans></span>
                            <select className="select select-bordered select-sm" value={year}
                                onChange={(event) => selectYear(Number(event.target.value))} required>
                                {visibleYears.map((value) => (
                                    <option key={value} value={value}>{value}</option>
                                ))}
                            </select>
                        </label>
                        <button type="button" className="btn btn-primary btn-sm" onClick={() => setIsEntryDialogOpen(true)}>
                            <Trans>+ Buchung</Trans>
                        </button>
                        <button type="button" className="btn btn-outline btn-sm" onClick={() => selectTab('einstellungen')}>
                            <Trans>Einstellungen</Trans>
                        </button>
                        <Link to="/" className="btn btn-ghost btn-sm"><Trans>Zurück zum Portal</Trans></Link>
                    </div>
                </div>
            </header>

            {/* Not sticky: the tab bar below must stay clickable when a tall
                panel (e.g. the asset view) scrolls the page down. A sticky
                banner at top-0 covers the tabs and swallows their clicks. */}
            <div className="border-b border-warning/30 bg-warning/10">
                <div className="max-w-7xl mx-auto px-4 md:px-8 py-2 text-sm">
                    <Trans>Betriebsanteil-Hilfe: Geben Sie den betrieblichen Anteil in 5-%-Schritten von 0 % bis 100 % an. Der Privatanteil ergibt sich aus 100 % abzüglich des Betriebsanteils.</Trans>
                </div>
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-8 pt-4">
                <div role="tablist" className="tabs tabs-box eur-tabs bg-base-100">
                    {TABS.map((id) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            aria-selected={tab === id}
                            className={`tab ${tab === id ? 'tab-active' : ''}`}
                            onClick={() => selectTab(id)}
                        >
                            {tabLabel(id)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="max-w-7xl mx-auto px-4 md:px-8 py-6 grid grid-cols-1 lg:grid-cols-5 gap-6">
                <aside className="lg:col-span-1">
                    <div className="rounded-box border border-base-300 bg-base-100 p-4">
                        <h2 className="font-semibold uppercase text-sm opacity-70"><Trans>Monate</Trans></h2>
                        <p className="text-xs opacity-60"><Trans>Es werden nur Monate mit Buchungen angezeigt.</Trans></p>
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
                                            <span>{MONTHS[value - 1]} {year}</span>
                                            <span>{euro(income - expense)}</span>
                                        </div>
                                        <div className="text-xs opacity-60"><Trans>{rowCount} Buchungen</Trans></div>
                                    </button>
                                );
                            })}
                        </div>
                        <div className="divider my-3"/>
                        <div className="space-y-2">
                            <button type="button" className="btn btn-outline btn-sm w-full" onClick={() => void addMonth()}>
                                <Trans>Neuer Monat</Trans>
                            </button>
                            <button type="button" className="btn btn-outline btn-sm w-full" onClick={() => void addYear()}>
                                <Trans>Ganzes Jahr anlegen</Trans>
                            </button>
                            {monthMessage && <p className="alert alert-success text-xs" role="status">{monthMessage}</p>}
                            {monthError && <p className="alert alert-error text-xs" role="alert">{monthError}</p>}
                            {yearMessage && <p className="alert alert-success text-xs" role="status">{yearMessage}</p>}
                            {yearError && <p className="alert alert-error text-xs" role="alert">{yearError}</p>}
                        </div>
                    </div>
                </aside>

                <main aria-label={t`Buchhaltung`} className="lg:col-span-4 space-y-6">
                    <section className="rounded-box border border-base-300 bg-base-100 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-xs uppercase tracking-wide opacity-60"><Trans>Monatsübersicht</Trans></p>
                                <h2 className="text-2xl font-bold">{MONTHS[month - 1]} {year}</h2>
                                <p className="text-sm opacity-70"><Trans>{monthEntryCount} Buchungen im Monat</Trans></p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm opacity-70"><Trans>{documentsForMonth} Belege</Trans></span>
                                <span className="text-sm opacity-70"><Trans>Stunden: {hoursText}</Trans></span>
                                <button type="button" className="btn btn-outline btn-sm"
                                    onClick={() => setIsEigenbelegOpen((open) => !open)}>
                                    <Trans>+ Eigenbeleg</Trans>
                                </button>
                                <a className="btn btn-outline btn-sm" href={eurPdfMonthUrl(year, month)} download>
                                    <Trans>PDF herunterladen</Trans>
                                </a>
                            </div>
                        </div>

                        {isEigenbelegOpen && (
                            <div className="rounded-box border border-base-300 p-4 mt-4">
                                <h3 className="font-semibold"><Trans>Eigenbeleg erfassen</Trans></h3>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mt-2 items-end">
                                    <label className="form-control">
                                        <span className="label-text"><Trans>Tag</Trans></span>
                                        <input type="number" className="input input-bordered input-sm" min={1} max={31}
                                            {...register('ebDay')} required/>
                                        {eigenbelegErrors.ebDay && <span className="text-error text-xs mt-1">{eigenbelegErrors.ebDay.message}</span>}
                                    </label>
                                    <label className="form-control md:col-span-2">
                                        <span className="label-text"><Trans>Beschreibung</Trans></span>
                                        <input type="text" className="input input-bordered input-sm"
                                            {...register('ebDescription')} required/>
                                        {eigenbelegErrors.ebDescription && <span className="text-error text-xs mt-1">{eigenbelegErrors.ebDescription.message}</span>}
                                    </label>
                                    <label className="form-control">
                                        <span className="label-text"><Trans>Betrag (€)</Trans></span>
                                        <input type="text" inputMode="decimal" className="input input-bordered input-sm"
                                            {...register('ebAmount')} required/>
                                        {eigenbelegErrors.ebAmount && <span className="text-error text-xs mt-1">{eigenbelegErrors.ebAmount.message}</span>}
                                    </label>
                                    <label className="form-control">
                                        <span className="label-text"><Trans>Betriebsanteil (%)</Trans></span>
                                        <select className="select select-bordered select-sm" {...register('ebShare')} required>
                                            {BUSINESS_SHARES.map((share) => (
                                                <option key={share} value={share}>{share} %</option>
                                            ))}
                                        </select>
                                        {eigenbelegErrors.ebShare && <span className="text-error text-xs mt-1">{eigenbelegErrors.ebShare.message}</span>}
                                    </label>
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    <button type="button" className="btn btn-primary btn-sm"
                                        onClick={handleSubmit(saveEigenbeleg, rejectEigenbeleg)}>
                                        <Trans>Eigenbeleg speichern</Trans>
                                    </button>
                                    {lastEigenbeleg && (
                                        <a className="btn btn-ghost btn-sm"
                                            href={eurPdfEigenbelegUrl(year, month, lastEigenbeleg.description, lastEigenbeleg.amountCents)}
                                            download>
                                            <Trans>Eigenbeleg-PDF herunterladen</Trans>
                                        </a>
                                    )}
                                </div>
                                {ebMessage && <p className="alert alert-success mt-3" role="status">{ebMessage}</p>}
                                {ebError && <p className="alert alert-error mt-3" role="alert">{ebError}</p>}
                            </div>
                        )}

                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mt-5">
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Einnahmen betrieblich</Trans></div>
                                <div className="text-2xl font-bold text-emerald-700">{euro(monthIncome)}</div>
                            </div>
                            <div className="rounded-box border border-base-300 p-4">
                                <div className="text-sm opacity-60"><Trans>Ausgaben betrieblich</Trans></div>
                                <div className="text-2xl font-bold text-rose-700">{euro(monthExpense)}</div>
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

                        <div className="rounded-box border border-base-300 p-4 mt-4">
                            <div className="flex justify-between text-sm font-medium">
                                <span><Trans>Betrieblich</Trans></span>
                                <span><Trans>Privat</Trans></span>
                            </div>
                            <progress className="progress progress-primary w-full mt-2"
                                value={businessTotal} max={Math.max(businessTotal + monthPrivate, 1)}/>
                            <p className="text-sm opacity-70 mt-2">
                                <Trans>Betriebliche Einnahmen {monthIncomeLabel} und Ausgaben {monthExpenseLabel} · Privatanteil {monthPrivateLabel}.</Trans>
                            </p>
                        </div>

                        <footer className="mt-4 pt-3 border-t border-base-300 flex flex-wrap gap-4 text-sm opacity-70">
                            <span><Trans>Offene Belege: {openDocuments}</Trans></span>
                            <span><Trans>Signatur: {signatureStatusText}</Trans></span>
                            {!isSigned && <span className="text-warning"><Trans>Monat nicht signiert</Trans></span>}
                        </footer>
                    </section>

                    {tab === 'buchungen' && (
                        <>
                            <EurEntriesPanel
                                data={entries.data}
                                isLoading={entries.isLoading}
                                error={entries.error}
                                onRetry={() => void entries.mutate()}
                                onCreate={() => setIsEntryDialogOpen(true)}
                            />
                            <EurOverviewPanel
                                summary={summary.data}
                                settings={settings.data}
                                isLoading={summary.isLoading}
                                error={summary.error}
                                onRetry={() => void summary.mutate()}
                            />
                        </>
                    )}

                    {tab === 'wareneingang' && <EurWarehousePanel year={year} month={month}/>}

                    {tab === 'belege' && <EurDocumentsPanel year={year} month={month}/>}

                    {tab === 'anlagen' && (
                        <>
                            <EurAssetsPanel year={year}/>
                            <EurDepreciationPanel year={year}/>
                            <div className="rounded-box border border-base-300 bg-base-100 p-5">
                                <h2 className="text-xl font-semibold"><Trans>Geringwertige Wirtschaftsgüter</Trans></h2>
                                <p className="mt-1"><Trans>Anschaffungen unter 1.000 € können Sie im Jahr des Kaufs direkt als Ausgabe erfassen.</Trans></p>
                                <a href="#eur-buchungen" className="btn btn-outline btn-sm mt-3"><Trans>Sofort als Ausgabe erfassen</Trans></a>
                                <a href={eurPdfAssetsUrl(year)} className="btn btn-outline btn-sm mt-3 ml-2" download>
                                    <Trans>Anlagenverzeichnis als PDF</Trans>
                                </a>
                            </div>
                        </>
                    )}

                    {tab === 'monat' && (
                        <>
                            <EurMonthPanel year={year} month={month} onMonthChange={selectMonth}/>
                            <EurHoursPanel year={year} month={month}/>
                        </>
                    )}

                    {tab === 'auswertungen' && <EurAnalyticsPanel year={year}/>}

                    {tab === 'reisen' && <EurTravelPanel reference={reference.data}/>}

                    {tab === 'steuer' && (
                        <EurIncomeTaxPanel
                            defaultProfitCents={summary.data?.profit_cents ?? 0}
                            e1ByCode={monthData.data?.e1_by_code}
                        />
                    )}

                    {tab === 'ust' && <EurVatPanel/>}

                    {tab === 'antraege' && <EurApplicationsPanel year={year}/>}

                    {tab === 'signatur' && <EurSignaturePanel year={year} month={month}/>}

                    {tab === 'einstellungen' && (
                        <>
                            <CompanyCard billing={billingDetails}/>
                            <EurSettingsForm
                                key={`${String(settings.data?.default_business_share)}-${String(settings.data?.sv_mode)}-${String(settings.data?.kleinunternehmer)}`}
                                settings={settings.data}
                                onSaved={reloadAll}
                            />
                            <EurReferencePanel
                                reference={reference.data}
                                isLoading={reference.isLoading}
                                error={reference.error}
                                onRetry={() => void reference.mutate()}
                            />
                        </>
                    )}

                    {isEntryDialogOpen && (
                        <EurEntryDialog
                            year={year}
                            reference={reference.data}
                            onClose={() => setIsEntryDialogOpen(false)}
                            onCreated={reloadAll}
                        />
                    )}
                </main>
            </div>
        </div>
    );
}
