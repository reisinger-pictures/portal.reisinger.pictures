import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import {
    attachEurAssetDocument,
    bookEurAssetDepreciation,
    carryOverEurAssets,
    detachEurAssetDocument,
    switchEurAssetToLinear,
    useEurAssets,
    useEurDocuments,
    useEurReference,
    type EurAsset,
    type EurAssetDocument,
    type EurPlanYear,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

// Offline-Fallback, bis die Referenzantwort den gepinnten Katalogwert liefert.
// Die API ist authoritative (EUE-051); dieser Wert ist nur ein Anzeige-Fallback.
const GWG_LIMIT_FALLBACK_CENTS = 100000;

// EUE-051: wörtlich gepinnter Hinweis aus rules.json (AV!B3). Die Grenze selbst
// kommt aus der Referenz-API; der Text ist ein unveränderliches Artefakt und
// bleibt ausdrücklich nicht blockierend (asset-register.md: das Gut bleibt erfassbar).
const GWG_HINT = '• Unter GWG-Grenze (1000€) - evtl. sofort im A-Blatt erfassen';

// DEG-007: Anzeige der gepinnten Enum-Werte; die Zulässigkeit prüft die API
// gegen das DEG-Artefakt, nicht diese Übersetzung.
const EXCEPTION_LABELS: Record<string, string> = {
    digitalisation: 'Digitalisierung',
    eco: 'Ökologisierung',
    health: 'Gesundheit/Life-Science',
};

interface Props {
    year: number;
}

type Scope = 'all' | 'business' | 'private';

/**
 * D-85: `scope` is authoritative; older cached rows only carry the legacy
 * `is_private_investment` flag and therefore still land in the right directory.
 */
const scopeOf = (asset: EurAsset): 'business' | 'private' =>
    asset.scope ?? (asset.is_private_investment === true ? 'private' : 'business');

export default function EurAssetsPanel({ year }: Props) {
    const assets = useEurAssets();
    const documents = useEurDocuments(year);
    const reference = useEurReference();
    const gwgLimitCents = reference.data?.assets?.gwg_limit_cents ?? GWG_LIMIT_FALLBACK_CENTS;
    const [scope, setScope] = useState<Scope>('all');
    const [busyId, setBusyId] = useState<number | null>(null);
    const [switchingId, setSwitchingId] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [carryOverBusy, setCarryOverBusy] = useState(false);
    const [documentBusyId, setDocumentBusyId] = useState<number | null>(null);
    const [linkSelection, setLinkSelection] = useState<Record<number, string>>({});

    const viewLabel = t`Ansicht`;
    const reasonLabel = t`Begründung`;
    const documentLabel = t`Beleg`;
    const withdrawalTitle = t`Entnahme bei Anschaffung (EUE-055)`;
    const all = assets.data?.assets ?? [];
    const shown = scope === 'all' ? all : all.filter((asset) => scopeOf(asset) === scope);

    const book = async (asset: EurAsset, plan: EurPlanYear) => {
        const isPrivate = scopeOf(asset) === 'private';
        setBusyId(asset.id);
        setMessage(null);
        try {
            const result = await bookEurAssetDepreciation(asset.id, plan.year);
            if (result.eigenbeleg) {
                const eigenbeleg = result.eigenbeleg;
                const amount = eigenbeleg.eigenbeleg_cents === null ? '' : cents(eigenbeleg.eigenbeleg_cents);
                const usage = eigenbeleg.usage_percent ?? 0;
                // D-85: ein Eigenbeleg ist reine Dokumentation — keine Buchung,
                // kein Aufwand, keine Auswirkung auf den Gewinn.
                setMessage(result.already_created
                    ? `Eigenbeleg ${eigenbeleg.eigenbeleg_no ?? ''} für ${asset.name} ${plan.year} ist bereits vorhanden.`
                    : `Eigenbeleg ${eigenbeleg.eigenbeleg_no ?? ''} für ${asset.name} ${plan.year} wurde erstellt (${amount} bei ${usage} % Nutzung).`);
            } else {
                setMessage(result.already_booked
                    ? `AfA ${asset.name} ${plan.year} ist bereits gebucht.`
                    : `AfA ${asset.name} ${plan.year} wurde gebucht (${result.entry?.receipt_no ?? ''}).`);
            }
            void assets.mutate();
        } catch {
            setMessage(isPrivate
                ? 'Der Eigenbeleg konnte nicht erstellt werden. Bitte erneut versuchen.'
                : 'Die AfA-Buchung konnte nicht erzeugt werden. Bitte erneut versuchen.');
        } finally {
            setBusyId(null);
        }
    };

    const carryOver = async () => {
        setCarryOverBusy(true);
        setMessage(null);
        try {
            const result = await carryOverEurAssets(year);
            setMessage(result.created > 0
                ? `Jahr ${year} übernommen: ${result.created} ${result.created === 1 ? 'Anlage' : 'Anlagen'} ergänzt.`
                : `Jahr ${year} ist bereits vollständig übernommen.`);
            void assets.mutate();
        } catch {
            setMessage(`Das Jahr ${year} konnte nicht übernommen werden. Bitte erneut versuchen.`);
        } finally {
            setCarryOverBusy(false);
        }
    };

    const attach = async (asset: EurAsset) => {
        const selection = linkSelection[asset.id] ?? '';
        const documentId = Number(selection);
        if (selection === '' || !Number.isInteger(documentId)) return;
        setDocumentBusyId(asset.id);
        setMessage(null);
        try {
            const result = await attachEurAssetDocument(asset.id, documentId);
            setMessage(result.already_linked
                ? `Beleg ${result.document.original_name} ist bereits mit ${asset.name} verknüpft.`
                : `Beleg ${result.document.original_name} wurde mit ${asset.name} verknüpft.`);
            setLinkSelection((previous) => ({...previous, [asset.id]: ''}));
            void assets.mutate();
        } catch {
            setMessage('Der Beleg konnte nicht verknüpft werden.');
        } finally {
            setDocumentBusyId(null);
        }
    };

    const detach = async (asset: EurAsset, document: EurAssetDocument) => {
        setDocumentBusyId(asset.id);
        setMessage(null);
        try {
            await detachEurAssetDocument(asset.id, document.id);
            setMessage(`Beleg ${document.original_name} wurde von ${asset.name} gelöst.`);
            void assets.mutate();
        } catch {
            setMessage('Der Beleg konnte nicht gelöst werden.');
        } finally {
            setDocumentBusyId(null);
        }
    };

    const switchToLinear = async (asset: EurAsset) => {
        if (reason.trim().length < 5) {
            setMessage('Bitte eine Begründung mit mindestens fünf Zeichen angeben.');
            return;
        }
        setBusyId(asset.id);
        setMessage(null);
        try {
            await switchEurAssetToLinear(asset.id, year, reason.trim());
            setSwitchingId(null);
            setReason('');
            void assets.mutate();
        } catch {
            setMessage('Der Wechsel zur linearen AfA konnte nicht vollzogen werden.');
        } finally {
            setBusyId(null);
        }
    };

    // EUE-055 fires when the business share is below 100 % but at least the
    // EUE-054 minimum of 50 %, i.e. any private share above 0 %. Since the
    // backend caps private_use_percent at 50 % through business_share >= 50,
    // a "> 50 %" threshold would be unreachable for every creatable asset.
    // D-85 routes sub-50 % assets to the private directory instead, where the
    // EUE-054 withdrawal question does not apply.
    const withdrawalHint = (plan: EurPlanYear) => plan.private_use_percent > 0 && plan.private_use_percent < 100;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="eur-assets-panel">
            <div className="card-body p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="card-title text-primary"><Trans>Anlagen und Abschreibung</Trans></h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="button" className="btn btn-sm btn-outline" disabled={carryOverBusy}
                            onClick={() => void carryOver()}>
                            <Trans>Jahr übernehmen</Trans>
                        </button>
                        <div className="join" role="group" aria-label={viewLabel}>
                            <button type="button" className={`btn btn-sm join-item ${scope === 'all' ? 'btn-active' : ''}`}
                                aria-pressed={scope === 'all'} onClick={() => setScope('all')}>
                                <Trans>Alle Anlagen</Trans>
                            </button>
                            <button type="button" className={`btn btn-sm join-item ${scope === 'business' ? 'btn-active' : ''}`}
                                aria-pressed={scope === 'business'} onClick={() => setScope('business')}>
                                <Trans>Betriebsanlagen</Trans>
                            </button>
                            <button type="button" className={`btn btn-sm join-item ${scope === 'private' ? 'btn-active' : ''}`}
                                aria-pressed={scope === 'private'} onClick={() => setScope('private')}>
                                <Trans>Privateinlagen</Trans>
                            </button>
                        </div>
                    </div>
                </div>
                <p className="text-sm opacity-70">
                    <Trans>Die Abschreibung wird je Jahr direkt angezeigt. Gebuchte Jahre bleiben unverändert; der Wechsel zur linearen AfA ist einmalig und dauerhaft.</Trans>
                </p>

                {message && <p className="alert alert-info mt-3" role="status">{message}</p>}

                {shown.length === 0 ? (
                    <p className="mt-4"><Trans>Für diese Ansicht sind keine Anlagen erfasst.</Trans></p>
                ) : (
                    <div className="overflow-x-auto mt-3">
                        <table className="table table-sm">
                            <thead>
                                <tr>
                                    <th><Trans>Anlagegut</Trans></th>
                                    <th><Trans>Methode</Trans></th>
                                    <th className="text-right"><Trans>Satz</Trans></th>
                                    <th className="text-right"><Trans>Anschaffung</Trans></th>
                                    <th className="text-right"><Trans>AfA {year}</Trans></th>
                                    <th className="text-right"><Trans>Betriebl. Anteil</Trans></th>
                                    <th className="text-right"><Trans>Privatanteil</Trans></th>
                                    <th><Trans>Status</Trans></th>
                                    <th><Trans>Aktion</Trans></th>
                                    <th><Trans>Belege</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((asset) => {
                                    const plan = asset.years.find((candidate) => candidate.year === year);
                                    const isGwg = asset.cost_cents < gwgLimitCents;
                                    const switched = asset.switched_to_linear_at != null;
                                    const isPrivate = scopeOf(asset) === 'private';
                                    const eigenbelegNo = plan?.eigenbeleg_no ?? '';
                                    const eigenbelegCreated = plan?.eigenbeleg_no != null || plan?.eigenbeleg_created_at != null;
                                    // Lingui-Makros erlauben im Text nur einfache
                                    // Variablen, keine Ausdrücke oder Property-Zugriffe.
                                    const theoreticalText = plan?.theoretical_depreciation_cents != null
                                        ? cents(plan.theoretical_depreciation_cents)
                                        : (plan ? cents(plan.depreciation_cents) : '—');
                                    const usageText = plan?.usage_percent != null ? `${plan.usage_percent} %` : '—';
                                    const eigenbelegAmountText = plan?.eigenbeleg_cents != null
                                        ? cents(plan.eigenbeleg_cents) : '—';
                                    const hint = asset.switch_hint;
                                    const hintYear = hint?.year ?? 0;
                                    const hintAdvantage = hint ? cents(hint.advantage_cents) : '';
                                    const exceptionLabel = asset.immaterial_exception
                                        ? EXCEPTION_LABELS[asset.immaterial_exception] ?? asset.immaterial_exception
                                        : '';
                                    return (
                                        <tr key={asset.id}>
                                            <td>
                                                <span className="font-medium">{asset.name}</span>
                                                {isGwg && <span className="badge badge-info badge-outline ml-2"><Trans>GWG</Trans></span>}
                                                {isGwg && <span className="ml-2 text-xs text-info" data-testid="eur-gwg-hint">{GWG_HINT}</span>}
                                                {isPrivate && <span className="badge badge-warning badge-outline ml-2"><Trans>Privat</Trans></span>}
                                                {asset.is_used && <span className="badge badge-warning badge-outline ml-2"><Trans>Gebraucht</Trans></span>}
                                                {asset.is_immaterial && <span className="badge badge-info badge-outline ml-2"><Trans>Immateriell</Trans></span>}
                                                {asset.immaterial_exception && (
                                                    <span className="block text-xs text-info mt-1">
                                                        <Trans>Ausnahme: {exceptionLabel}</Trans>
                                                    </span>
                                                )}
                                                {asset.special_depreciation && <span className="badge badge-warning badge-outline ml-2"><Trans>Sonder-AfA</Trans></span>}
                                                {asset.is_electric_vehicle && <span className="badge badge-success badge-outline ml-2"><Trans>Elektroauto</Trans></span>}
                                                {asset.warnings?.map((warning) => (
                                                    <span key={warning.code} className="block text-xs text-warning mt-1">
                                                        {warning.text}
                                                    </span>
                                                ))}
                                                {hint && !isPrivate && (
                                                    <span className="block text-xs text-warning mt-1" role="status">
                                                        <Trans>
                                                            Wechsel zur linearen AfA ab Jahr {hintYear} empfohlen; Vorteil {hintAdvantage}.
                                                        </Trans>
                                                    </span>
                                                )}
                                            </td>
                                            <td>{asset.method}</td>
                                            <td className="text-right">
                                                {asset.method === 'degressiv' && asset.degressive_rate !== null
                                                    ? formatPercent(asset.degressive_rate)
                                                    : formatPercent(Math.round(10000 / asset.useful_life_years))}
                                            </td>
                                            <td className="text-right">{cents(asset.cost_cents)}</td>
                                            <td className="text-right">{plan ? cents(plan.depreciation_cents) : '—'}</td>
                                            <td className="text-right">{plan ? cents(plan.business_use_cents) : '—'}</td>
                                            <td className="text-right">
                                                {plan ? cents(plan.depreciation_cents - plan.business_use_cents) : '—'}
                                            </td>
                                            <td>
                                                {isPrivate ? (
                                                    eigenbelegCreated ? (
                                                        <span className="block">
                                                            <span className="badge badge-success badge-outline">
                                                                <Trans>Eigenbeleg {eigenbelegNo}</Trans>
                                                            </span>
                                                            <span className="block text-xs">
                                                                <Trans>
                                                                    Theoretische AfA {theoreticalText} · Nutzung {usageText} · Eigenbeleg {eigenbelegAmountText}
                                                                </Trans>
                                                            </span>
                                                        </span>
                                                    ) : (
                                                        <span className="badge badge-ghost badge-outline"><Trans>Eigenbeleg offen</Trans></span>
                                                    )
                                                ) : (
                                                    plan && plan.eur_entry_id != null ? (
                                                        <span className="badge badge-success badge-outline"><Trans>Gebucht</Trans></span>
                                                    ) : (
                                                        <span className="badge badge-ghost badge-outline"><Trans>Offen</Trans></span>
                                                    )
                                                )}
                                                {plan && !isPrivate && withdrawalHint(plan) && (
                                                    asset.entnahme?.done ? (
                                                        <span className="badge badge-success badge-outline ml-1" title={withdrawalTitle}>
                                                            <Trans>Entnahme erledigt</Trans>
                                                        </span>
                                                    ) : (
                                                        <span className="badge badge-warning badge-outline ml-1" title={withdrawalTitle}>
                                                            <Trans>Entnahme fehlt</Trans>
                                                        </span>
                                                    )
                                                )}
                                                {switched && <span className="badge badge-neutral badge-outline ml-1"><Trans>Linear (fest)</Trans></span>}
                                            </td>
                                            <td>
                                                {plan && isPrivate && !eigenbelegCreated && (
                                                    <button type="button" className="btn btn-primary btn-xs"
                                                        disabled={busyId === asset.id}
                                                        onClick={() => void book(asset, plan)}>
                                                        <Trans>Eigenbeleg erstellen</Trans>
                                                    </button>
                                                )}
                                                {plan && !isPrivate && plan.eur_entry_id == null && (
                                                    <button type="button" className="btn btn-primary btn-xs"
                                                        disabled={busyId === asset.id}
                                                        onClick={() => void book(asset, plan)}>
                                                        <Trans>AfA buchen</Trans>
                                                    </button>
                                                )}
                                                {!isPrivate && asset.method === 'degressiv' && !switched && (
                                                    switchingId === asset.id ? (
                                                        <span className="flex flex-wrap items-center gap-1">
                                                            <label className="sr-only" htmlFor={`reason-${asset.id}`}>{reasonLabel}</label>
                                                            <input id={`reason-${asset.id}`} className="input input-bordered input-xs"
                                                                value={reason} onChange={(event) => setReason(event.target.value)} />
                                                            <button type="button" className="btn btn-primary btn-xs"
                                                                disabled={busyId === asset.id || reason.trim().length < 5}
                                                                onClick={() => void switchToLinear(asset)}>
                                                                <Trans>Bestätigen</Trans>
                                                            </button>
                                                        </span>
                                                    ) : (
                                                        <button type="button" className="btn btn-outline btn-xs ml-1"
                                                            disabled={busyId === asset.id}
                                                            onClick={() => { setSwitchingId(asset.id); setReason(''); }}>
                                                            <Trans>Zu linear wechseln</Trans>
                                                        </button>
                                                    )
                                                )}
                                            </td>
                                            <td>
                                                <ul className="text-xs" data-testid="eur-asset-documents">
                                                    {(asset.documents ?? []).map((document) => (
                                                        <li key={document.id} className="flex items-center gap-1">
                                                            <span>{document.original_name}</span>
                                                            <button type="button" className="btn btn-ghost btn-xs"
                                                                disabled={documentBusyId === asset.id}
                                                                onClick={() => void detach(asset, document)}>
                                                                <Trans>Lösen</Trans>
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                                <div className="flex flex-wrap items-center gap-1 mt-1">
                                                    <label className="sr-only" htmlFor={`document-${asset.id}`}>{documentLabel}</label>
                                                    <select id={`document-${asset.id}`} className="select select-bordered select-xs"
                                                        value={linkSelection[asset.id] ?? ''}
                                                        onChange={(event) => setLinkSelection((previous) => ({
                                                            ...previous, [asset.id]: event.target.value,
                                                        }))}>
                                                        <option value=""><Trans>Beleg wählen</Trans></option>
                                                        {(documents.data?.documents ?? []).map((document) => (
                                                            <option key={document.id} value={document.id}>
                                                                {document.receipt_no ?? document.original_name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <button type="button" className="btn btn-outline btn-xs"
                                                        disabled={documentBusyId === asset.id || (linkSelection[asset.id] ?? '') === ''}
                                                        onClick={() => void attach(asset)}>
                                                        <Trans>Beleg anhängen</Trans>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
