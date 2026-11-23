import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { formatEuro, formatPercent } from '../../../logic/formatCurrency';
import {
    bookEurAssetDepreciation,
    switchEurAssetToLinear,
    useEurAssets,
    useEurReference,
    type EurAsset,
    type EurPlanYear,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

// Offline-Fallback, bis die Referenzantwort den gepinnten Katalogwert liefert.
// Die API ist authoritative (EUE-051); dieser Wert ist nur ein Anzeige-Fallback.
const GWG_LIMIT_FALLBACK_CENTS = 100000;

interface Props {
    year: number;
}

type Scope = 'all' | 'private';

export default function EurAssetsPanel({ year }: Props) {
    const assets = useEurAssets();
    const reference = useEurReference();
    const gwgLimitCents = reference.data?.assets?.gwg_limit_cents ?? GWG_LIMIT_FALLBACK_CENTS;
    const [scope, setScope] = useState<Scope>('all');
    const [busyId, setBusyId] = useState<number | null>(null);
    const [switchingId, setSwitchingId] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const [message, setMessage] = useState<string | null>(null);

    const viewLabel = t`Ansicht`;
    const reasonLabel = t`Begründung`;
    const withdrawalTitle = t`Entnahme bei hohem Privatanteil prüfen (EUE-055)`;
    const all = assets.data?.assets ?? [];
    const shown = scope === 'private'
        ? all.filter((asset) => asset.is_private_investment === true)
        : all;

    const book = async (asset: EurAsset, plan: EurPlanYear) => {
        setBusyId(asset.id);
        setMessage(null);
        try {
            const result = await bookEurAssetDepreciation(asset.id, plan.year);
            setMessage(result.already_booked
                ? `AfA ${asset.name} ${plan.year} ist bereits gebucht.`
                : `AfA ${asset.name} ${plan.year} wurde gebucht (${result.entry.receipt_no ?? ''}).`);
            void assets.mutate();
        } catch {
            setMessage('Die AfA-Buchung konnte nicht erzeugt werden. Bitte erneut versuchen.');
        } finally {
            setBusyId(null);
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
    const withdrawalHint = (plan: EurPlanYear) => plan.private_use_percent > 0 && plan.private_use_percent < 100;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="eur-assets-panel">
            <div className="card-body p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="card-title text-primary"><Trans>Anlagen und Abschreibung</Trans></h2>
                    <div className="join" role="group" aria-label={viewLabel}>
                        <button type="button" className={`btn btn-sm join-item ${scope === 'all' ? 'btn-active' : ''}`}
                            aria-pressed={scope === 'all'} onClick={() => setScope('all')}>
                            <Trans>Alle Anlagen</Trans>
                        </button>
                        <button type="button" className={`btn btn-sm join-item ${scope === 'private' ? 'btn-active' : ''}`}
                            aria-pressed={scope === 'private'} onClick={() => setScope('private')}>
                            <Trans>Privateinlagen</Trans>
                        </button>
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
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((asset) => {
                                    const plan = asset.years.find((candidate) => candidate.year === year);
                                    const isGwg = asset.cost_cents < gwgLimitCents;
                                    const switched = asset.switched_to_linear_at != null;
                                    return (
                                        <tr key={asset.id}>
                                            <td>
                                                <span className="font-medium">{asset.name}</span>
                                                {isGwg && <span className="badge badge-info badge-outline ml-2"><Trans>GWG</Trans></span>}
                                                {asset.is_private_investment && <span className="badge badge-warning badge-outline ml-2"><Trans>Privat</Trans></span>}
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
                                                {plan && plan.eur_entry_id != null ? (
                                                    <span className="badge badge-success badge-outline"><Trans>Gebucht</Trans></span>
                                                ) : (
                                                    <span className="badge badge-ghost badge-outline"><Trans>Offen</Trans></span>
                                                )}
                                                {plan && withdrawalHint(plan) && (
                                                    <span className="badge badge-warning badge-outline ml-1" title={withdrawalTitle}>
                                                        <Trans>Entnahme prüfen</Trans>
                                                    </span>
                                                )}
                                                {switched && <span className="badge badge-neutral badge-outline ml-1"><Trans>Linear (fest)</Trans></span>}
                                            </td>
                                            <td>
                                                {plan && plan.eur_entry_id == null && (
                                                    <button type="button" className="btn btn-primary btn-xs"
                                                        disabled={busyId === asset.id}
                                                        onClick={() => void book(asset, plan)}>
                                                        <Trans>AfA buchen</Trans>
                                                    </button>
                                                )}
                                                {asset.method === 'degressiv' && !switched && (
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
