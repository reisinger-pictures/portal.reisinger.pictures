import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    assignEurAssetToGroup,
    createEurAssetGroup,
    deleteEurAssetGroup,
    revalidateEur,
    unassignEurAssetFromGroup,
    updateEurAssetGroup,
    useEurAssetGroups,
    useEurAssets,
} from '../../../logic/useEur';

const cents = (value: number): string => formatEuro(value / 100);

/** #57: die API akzeptiert 0–100 in 5-%-Schritten. */
const BUSINESS_SHARES = Array.from({length: 21}, (_, index) => index * 5);

// Factory, called inside the component: a `t` macro at module scope would be
// evaluated before i18n.activate() (see main.tsx).
const createGroupSchema = () => z.object({
    name: z.string().trim().min(1, t`Bitte einen Gruppennamen angeben`),
    businessShare: z.string().refine((value) => {
        if (value.trim() === '') return false;
        const share = Number(value);
        return Number.isInteger(share) && share >= 0 && share <= 100 && share % 5 === 0;
    }, t`Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben`),
});

type GroupFormValues = z.infer<ReturnType<typeof createGroupSchema>>;

const INITIAL: GroupFormValues = {
    name: '',
    businessShare: '100',
};

export default function EurAssetGroupsPanel() {
    const groups = useEurAssetGroups();
    const assets = useEurAssets();
    const [message, setMessage] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [assignments, setAssignments] = useState<Record<number, string>>({});
    const {register, handleSubmit, reset, formState: {errors}} = useForm<GroupFormValues>({
        resolver: zodResolver(createGroupSchema()),
        defaultValues: INITIAL,
        mode: 'onTouched',
    });

    const list = groups.data?.groups ?? [];
    // Legacy rows without the group field are treated as ungrouped.
    const ungrouped = (assets.data?.assets ?? []).filter((asset) => (asset.eur_asset_group_id ?? null) === null);

    const create = async (values: GroupFormValues) => {
        setCreating(true);
        setMessage(null);
        try {
            const result = await createEurAssetGroup({
                name: values.name.trim(),
                business_share: Number(values.businessShare),
            });
            await revalidateEur();
            const name = result.group.name;
            reset(INITIAL);
            setMessage(t`Gruppe ${name} wurde angelegt.`);
        } catch {
            setMessage(t`Die Gruppe konnte nicht angelegt werden. Bitte erneut versuchen.`);
        } finally {
            setCreating(false);
        }
    };

    const changeShare = async (group: {id: number; name: string; business_share: number}, value: string) => {
        const share = Number(value);
        if (!Number.isInteger(share) || share < 0 || share > 100 || share % 5 !== 0) return;
        setBusyId(group.id);
        setMessage(null);
        try {
            await updateEurAssetGroup(group.id, {name: group.name, business_share: share});
            await revalidateEur();
            const name = group.name;
            setMessage(t`Der Betriebsanteil von ${name} wurde auf ${share} % gesetzt.`);
        } catch {
            setMessage(t`Der Betriebsanteil konnte nicht geändert werden.`);
        } finally {
            setBusyId(null);
        }
    };

    const assign = async (group: {id: number}) => {
        const selection = assignments[group.id] ?? '';
        const assetId = Number(selection);
        if (selection === '' || !Number.isInteger(assetId)) return;
        setBusyId(group.id);
        setMessage(null);
        try {
            await assignEurAssetToGroup(group.id, assetId);
            await revalidateEur();
            setAssignments((previous) => ({...previous, [group.id]: ''}));
            setMessage(t`Das Anlagegut wurde der Gruppe zugeordnet.`);
        } catch {
            setMessage(t`Das Anlagegut konnte nicht zugeordnet werden.`);
        } finally {
            setBusyId(null);
        }
    };

    const unassign = async (group: {id: number}, assetId: number) => {
        setBusyId(group.id);
        setMessage(null);
        try {
            await unassignEurAssetFromGroup(group.id, assetId);
            await revalidateEur();
            setMessage(t`Das Anlagegut wurde aus der Gruppe gelöst und wird einzeln abgerechnet.`);
        } catch {
            setMessage(t`Das Anlagegut konnte nicht gelöst werden.`);
        } finally {
            setBusyId(null);
        }
    };

    const remove = async (group: {id: number}) => {
        setBusyId(group.id);
        setMessage(null);
        try {
            await deleteEurAssetGroup(group.id);
            await revalidateEur();
            setMessage(t`Die Gruppe wurde gelöscht. Die Anlagegüter werden einzeln abgerechnet.`);
        } catch {
            setMessage(t`Die Gruppe konnte nicht gelöscht werden.`);
        } finally {
            setBusyId(null);
        }
    };

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="eur-asset-groups-panel">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Anlagegruppen</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Eine Gruppe ist eine Abrechnungseinheit: Betriebliche Gruppen erzeugen eine Entnahme, private Gruppen je Jahr einen Eigenbeleg. Belege bleiben an den einzelnen Anlagegütern.</Trans>
                </p>

                {message && <p className="alert alert-info mt-3" role="status">{message}</p>}

                <form className="grid grid-cols-1 md:grid-cols-3 gap-4 items-end mt-3" onSubmit={handleSubmit(create)}>
                    <label className="form-control">
                        <span className="label-text"><Trans>Name der Gruppe</Trans></span>
                        <input type="text" className="input input-bordered" {...register('name')} required />
                        {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil</Trans></span>
                        <select className="select select-bordered" {...register('businessShare')} required>
                            {BUSINESS_SHARES.map((share) => (
                                <option key={share} value={share}>{share} %</option>
                            ))}
                        </select>
                        {errors.businessShare && <span className="text-error text-xs mt-1">{errors.businessShare.message}</span>}
                    </label>
                    <button type="submit" className="btn btn-primary" disabled={creating}>
                        <Trans>Gruppe anlegen</Trans>
                    </button>
                </form>

                {list.length === 0 ? (
                    <p className="mt-4"><Trans>Es sind noch keine Anlagegruppen angelegt.</Trans></p>
                ) : (
                    <div className="space-y-4 mt-4">
                        {list.map((group) => {
                            const memberCount = group.asset_ids.length;
                            const businessShare = group.business_share;
                            // Legacy groups carry neither field: show zero receipts.
                            const documentCount = group.document_ids
                                ? group.document_ids.length
                                : (group.documents?.length ?? 0);
                            const linkedDocuments = group.documents ?? [];
                            return (
                                <div key={group.id} className="rounded-box border border-base-300 p-4"
                                    data-testid={`eur-asset-group-${group.id}`}>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold">{group.name}</span>
                                            <span className={group.scope === 'business'
                                                ? 'badge badge-success badge-outline'
                                                : 'badge badge-warning badge-outline'}>
                                                {group.scope === 'business'
                                                    ? <Trans>Betrieblich</Trans>
                                                    : <Trans>Privat</Trans>}
                                            </span>
                                            <span className="text-sm opacity-70">
                                                <Trans>Betriebsanteil: {businessShare} %</Trans>
                                            </span>
                                            <span className="text-sm opacity-70">
                                                <Trans>{memberCount} Anlagegüter</Trans>
                                            </span>
                                        </div>
                                        <button type="button" className="btn btn-error btn-outline btn-sm"
                                            disabled={busyId === group.id}
                                            onClick={() => void remove(group)}>
                                            <Trans>Gruppe löschen</Trans>
                                        </button>
                                    </div>

                                    <div className="flex flex-wrap items-end gap-2 mt-3">
                                        <label className="form-control">
                                            <span className="label-text"><Trans>Betriebsanteil ändern</Trans></span>
                                            <select className="select select-bordered select-sm"
                                                value={group.business_share}
                                                disabled={busyId === group.id}
                                                onChange={(event) => void changeShare(group, event.target.value)}>
                                                {BUSINESS_SHARES.map((share) => (
                                                    <option key={share} value={share}>{share} %</option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="form-control">
                                            <span className="label-text"><Trans>Anlagegut zuordnen</Trans></span>
                                            <select className="select select-bordered select-sm"
                                                value={assignments[group.id] ?? ''}
                                                disabled={busyId === group.id}
                                                onChange={(event) => setAssignments((previous) => ({
                                                    ...previous, [group.id]: event.target.value,
                                                }))}>
                                                <option value=""><Trans>Anlagegut wählen</Trans></option>
                                                {ungrouped.map((asset) => (
                                                    <option key={asset.id} value={asset.id}>{asset.name}</option>
                                                ))}
                                            </select>
                                        </label>
                                        <button type="button" className="btn btn-outline btn-sm"
                                            disabled={busyId === group.id || (assignments[group.id] ?? '') === ''}
                                            onClick={() => void assign(group)}>
                                            <Trans>Zuordnen</Trans>
                                        </button>
                                    </div>

                                    <ul className="mt-3 text-sm" data-testid={`eur-asset-group-members-${group.id}`}>
                                        {group.assets.map((member) => (
                                            <li key={member.id} className="flex flex-wrap items-center justify-between gap-2">
                                                <span>{member.name}</span>
                                                <button type="button" className="btn btn-ghost btn-xs"
                                                    disabled={busyId === group.id}
                                                    onClick={() => void unassign(group, member.id)}>
                                                    <Trans>Entfernen</Trans>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>

                                    <div className="mt-3" data-testid={`eur-asset-group-documents-${group.id}`}>
                                        <h3 className="font-medium"><Trans>Belege</Trans></h3>
                                        <p className="text-sm opacity-70">
                                            <Trans>Eine Gruppe ist eine Abrechnungseinheit: Alle Anlagegüter der hier verknüpften Belege sind Mitglieder dieser Gruppe.</Trans>
                                        </p>
                                        <p className="text-sm">
                                            <Trans>{documentCount} Belege</Trans>
                                        </p>
                                        {linkedDocuments.length === 0 ? (
                                            <p className="text-sm opacity-70"><Trans>Keine Belege zugeordnet.</Trans></p>
                                        ) : (
                                            <ul className="text-sm">
                                                {linkedDocuments.map((document) => (
                                                    <li key={document.id}>{document.receipt_no ?? document.original_name ?? '—'}</li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>

                                    {group.scope === 'private' && (
                                        <div className="mt-3" data-testid={`eur-asset-group-years-${group.id}`}>
                                            <h3 className="font-medium"><Trans>Eigenbelege der Gruppe</Trans></h3>
                                            {group.years.length === 0 ? (
                                                <p className="text-sm opacity-70"><Trans>Noch keine Eigenbelege erfasst.</Trans></p>
                                            ) : (
                                                <ul className="text-sm">
                                                    {group.years.map((year) => {
                                                        const yearValue = year.year;
                                                        const eigenbelegNo = year.eigenbeleg_no ?? '—';
                                                        const amount = year.eigenbeleg_cents === null
                                                            ? '—' : cents(year.eigenbeleg_cents);
                                                        const usage = year.usage_percent === null
                                                            ? '—' : `${year.usage_percent} %`;
                                                        return (
                                                            <li key={year.year}>
                                                                <Trans>Jahr {yearValue}: Eigenbeleg {eigenbelegNo} · {amount} bei {usage} Nutzung</Trans>
                                                            </li>
                                                        );
                                                    })}
                                                </ul>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}
