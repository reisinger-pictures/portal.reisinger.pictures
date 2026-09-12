import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useVolumePresets } from '../../../logic/useVolumePresets';
import { useUI } from '../../components/UIContext';
import ModalDialogShell from '../../components/ModalDialogShell';
import { formatMoney } from '../../../logic/utils';

interface TierEditorRow {
    min_quantity: number;
    price_cents: number;
}

interface PresetEditorProps {
    initialName?: string;
    initialTiers?: TierEditorRow[];
    onSave: (name: string, tiers: TierEditorRow[]) => Promise<void>;
    onCancel: () => void;
}

/**
 * Editable string draft so partially typed decimals (e.g. "1.") survive the
 * keystroke instead of being normalised to "1.00" on every change.
 */
interface TierDraft {
    min_quantity: string;
    price: string;
}

function toTierDraft(row: TierEditorRow): TierDraft {
    return {min_quantity: String(row.min_quantity), price: (row.price_cents / 100).toFixed(2)};
}

function parsePriceCents(raw: string): number {
    const parsed = Number.parseFloat(raw.trim().replace(',', '.'));
    return Number.isFinite(parsed) ? Math.round(parsed * 100) : Number.NaN;
}

function parseMinQuantity(raw: string): number {
    const parsed = Number.parseInt(raw, 10);
    return Number.isFinite(parsed) ? parsed : Number.NaN;
}

function normalizePriceDraft(raw: string): string {
    const cents = parsePriceCents(raw);
    return Number.isFinite(cents) ? (Math.max(0, cents) / 100).toFixed(2) : '0.00';
}

function normalizeQuantityDraft(raw: string): string {
    const quantity = parseMinQuantity(raw);
    return Number.isFinite(quantity) ? String(Math.max(0, quantity)) : '0';
}

function PresetEditor({ initialName = '', initialTiers = [], onSave, onCancel }: PresetEditorProps) {
    const [name, setName] = useState(initialName);
    const [tiers, setTiers] = useState<TierDraft[]>(
        () => (initialTiers.length > 0 ? initialTiers : [{min_quantity: 0, price_cents: 3000}]).map(toTierDraft)
    );
    const [saving, setSaving] = useState(false);
    const { showToast } = useUI();

    const updateTier = (index: number, patch: Partial<TierDraft>) => {
        setTiers(prev => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const addTier = () => {
        const last = tiers[tiers.length - 1];
        const lastQuantity = last ? parseMinQuantity(last.min_quantity) : 0;
        const nextMin = (Number.isFinite(lastQuantity) ? lastQuantity : 0) + 10;
        const lastCents = last ? parsePriceCents(last.price) : Number.NaN;
        const nextCents = Math.max(0, (Number.isFinite(lastCents) ? lastCents : 0) - 500);
        setTiers(prev => [...prev, {min_quantity: String(nextMin), price: (nextCents / 100).toFixed(2)}]);
    };

    const removeTier = (index: number) => {
        setTiers(prev => prev.filter((_, i) => i !== index));
    };

    const handleSave = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!name.trim()) {
            showToast('error', t`Name ist erforderlich`);
            return;
        }
        if (tiers.length === 0) {
            showToast('error', t`Mindestens eine Preisstaffel erforderlich`);
            return;
        }
        const sorted = tiers.map(row => ({
            min_quantity: parseMinQuantity(row.min_quantity) || 0,
            price_cents: Math.max(0, parsePriceCents(row.price) || 0)
        })).sort((a, b) => a.min_quantity - b.min_quantity);
        // Die erste Staffel ist der Basispreis und gilt immer ab 0 Bildern.
        sorted[0] = { ...sorted[0], min_quantity: 0 };
        setSaving(true);
        try {
            await onSave(name.trim(), sorted);
            showToast('success', t`Preset gespeichert`);
            onCancel();
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        } finally {
            setSaving(false);
        }
    };

    const basePriceDraft = tiers[0]?.price ?? '0.00';

    return (
        <ModalDialogShell
            title={initialName ? t`Preset bearbeiten` : t`Neues Volume-Preset`}
            icon="mdi--currency-eur"
            onClose={onCancel}
            editing={!!initialName}
            isSubmitting={saving}
            onSubmit={handleSave}
            maxWidth="2xl"
        >
            <div className="form-control w-full mb-4">
                <label className="label"><span className="label-text font-bold"><Trans>Name</Trans></span></label>
                <input
                    type="text"
                    className="input input-bordered w-full"
                    value={name}
                    onChange={e => setName(e.target.value)}
                    placeholder={t`z.B. Werbung, Standard, Event`}
                    required
                />
            </div>

            <div className="mb-2 font-bold border-b border-base-300 pb-2 text-primary">
                <Trans>Preisstruktur (retroaktiv)</Trans>
            </div>
            <p className="text-sm opacity-70 mb-4">
                <Trans>Alle Bilder einer Bestellung werden zum Einheitspreis der erreichten Staffel abgerechnet (retroaktiv).</Trans>
            </p>

            {/* Basispreis: immer die erste Staffel, ab 0 Bildern */}
            <div className="rounded-box border border-primary/30 bg-base-200 p-3 sm:p-4 mb-4">
                <div className="flex flex-wrap items-center gap-2 mb-2">
                    <span className="badge badge-primary badge-sm uppercase px-2"><Trans>Basispreis</Trans></span>
                    <span className="text-sm font-bold"><Trans>pro Bild (ab 0 Bildern)</Trans></span>
                </div>
                <div className="flex items-center gap-2">
                    <input
                        type="number" min="0" step="0.01"
                        className="input input-bordered w-36 text-right text-lg"
                        value={basePriceDraft}
                        onChange={e => updateTier(0, { price: e.target.value })}
                        onBlur={() => updateTier(0, { price: normalizePriceDraft(basePriceDraft) })}
                    />
                    <span className="font-bold opacity-70 text-lg">€</span>
                </div>
            </div>

            {/* Mengenrabatt-Staffeln */}
            {tiers.length > 1 && (
                <div className="mb-2 font-bold border-b border-base-300 pb-2 opacity-80">
                    <Trans>Mengenrabatt-Staffeln</Trans>
                </div>
            )}
            <div className="flex flex-col gap-2 mb-4">
                {tiers.slice(1).map((row, index) => {
                    const realIndex = index + 1;
                    return (
                        <div key={realIndex} className="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] items-center gap-3 bg-base-200 p-3 rounded-box border border-base-300">
                            <div className="flex items-center gap-2">
                                <span className="font-bold opacity-70 whitespace-nowrap"><Trans>Ab</Trans></span>
                                <input
                                    type="number" min="0" step="1"
                                    className="input input-bordered w-24 text-right"
                                    value={row.min_quantity}
                                    onChange={e => updateTier(realIndex, { min_quantity: e.target.value })}
                                    onBlur={() => updateTier(realIndex, { min_quantity: normalizeQuantityDraft(row.min_quantity) })}
                                />
                                <span className="font-bold opacity-70 whitespace-nowrap"><Trans>Bildern</Trans></span>
                            </div>
                            <div className="flex items-center gap-1">
                                <input
                                    type="number" min="0" step="0.01"
                                    className="input input-bordered w-32 text-right"
                                    value={row.price}
                                    onChange={e => updateTier(realIndex, { price: e.target.value })}
                                    onBlur={() => updateTier(realIndex, { price: normalizePriceDraft(row.price) })}
                                />
                                <span className="font-bold opacity-70">€</span>
                            </div>
                            <div className="flex items-center gap-1">
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm btn-circle"
                                    onClick={() => removeTier(realIndex)}
                                    aria-label={t`Staffel entfernen`}
                                >
                                    <span className="iconify mdi--minus text-error"></span>
                                </button>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex">
                <button
                    type="button"
                    className="btn btn-outline btn-sm"
                    onClick={addTier}
                >
                    <span className="iconify mdi--plus"></span> <Trans>Staffel hinzufügen</Trans>
                </button>
            </div>
        </ModalDialogShell>
    );
}

export default function VolumePresetSettingsCard() {
    const { presets, isLoading, createPreset, updatePreset, deletePreset, setDefaultPreset } = useVolumePresets();
    const { showToast, confirm } = useUI();

    const [editing, setEditing] = useState<{ id?: string; name: string; tiers: TierEditorRow[] } | null>(null);

    const handleSave = async (name: string, tiers: TierEditorRow[]) => {
        if (editing?.id) {
            await updatePreset(editing.id, { name, tiers });
        } else {
            await createPreset({ name, tiers });
        }
    };

    const handleSetDefault = async (id: string) => {
        try {
            await setDefaultPreset(id);
            showToast('success', t`Als Standard gesetzt`);
        } catch {
            showToast('error', t`Fehler beim Setzen des Standards`);
        }
    };

    const handleDelete = async (id: string, name: string) => {
        if (await confirm({
            title: t`Preset löschen?`,
            message: t`Das Preset "${name}" wirklich löschen? Galerien mit diesem Preset fallen auf den Brand-Standard zurück.`,
            confirmColor: 'error',
        })) {
            try {
                await deletePreset(id);
                showToast('success', t`Preset gelöscht`);
            } catch {
                showToast('error', t`Fehler beim Löschen`);
            }
        }
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-2xl font-bold flex items-center gap-2">
                        <span className="iconify mdi--chart-gantt text-primary text-3xl"></span> Volume-Licensing Presets
                    </h2>
                    <p className="text-sm opacity-70 mt-1 max-w-3xl">
                        <Trans>
                            Definiere den Basispreis und Mengenrabatt-Staffeln pro Preset. Der Brand-Standard gilt für alle Galerien ohne eigenes Preset;
                            Galerien können im Galerie-Dialog ein anderes Preset zugewiesen bekommen.
                        </Trans>
                    </p>
                </div>
                <button className="btn btn-primary btn-sm" onClick={() => setEditing({ name: '', tiers: [] })}>
                    <span className="iconify mdi--plus"></span> <Trans>Neues Preset</Trans>
                </button>
            </div>

            {isLoading ? (
                <div className="py-8 text-center"><span className="loading loading-spinner text-primary"></span></div>
            ) : (
                <div className="overflow-x-auto rounded-box border border-base-300">
                    <table className="table table-zebra w-full">
                        <thead className="bg-base-200">
                            <tr>
                                <th>Name</th>
                                <th className="text-right">Basispreis</th>
                                <th>Staffeln</th>
                                <th className="text-right">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            {presets?.map(preset => (
                                <tr key={preset.id}>
                                    <td>
                                        <div className="font-bold flex items-center gap-2 flex-wrap">
                                            {preset.name}
                                            {preset.is_default && (
                                                <span className="badge badge-primary badge-sm uppercase px-2">Standard</span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="text-right font-mono font-bold whitespace-nowrap">
                                        {formatMoney(preset.tiers[0]?.price_cents ?? 0)}
                                    </td>
                                    <td>
                                        <div className="flex flex-wrap gap-1.5">
                                            {preset.tiers.slice(1).map((tier, index) => (
                                                <span key={index} className="badge badge-ghost badge-sm font-mono">
                                                    Ab {tier.min_quantity} → {formatMoney(tier.price_cents)}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-1">
                                            {!preset.is_default && (
                                                <button
                                                    className="btn btn-ghost btn-xs"
                                                    onClick={() => handleSetDefault(preset.id)}
                                                >
                                                    <span className="iconify mdi--star text-warning"></span> <Trans>Als Standard</Trans>
                                                </button>
                                            )}
                                            <button
                                                className="btn btn-ghost btn-xs"
                                                onClick={() => setEditing({
                                                    id: preset.id,
                                                    name: preset.name,
                                                    tiers: preset.tiers.map(t => ({ min_quantity: t.min_quantity, price_cents: t.price_cents })),
                                                })}
                                            >
                                                <span className="iconify mdi--pencil"></span> <Trans>Bearbeiten</Trans>
                                            </button>
                                            <button
                                                className="btn btn-ghost btn-xs text-error"
                                                onClick={() => handleDelete(preset.id, preset.name)}
                                                disabled={preset.is_default}
                                            >
                                                <span className="iconify mdi--delete"></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {(!presets || presets.length === 0) && (
                                <tr>
                                    <td colSpan={4} className="py-10">
                                        <div className="flex flex-col items-center gap-2 opacity-60">
                                            <span className="iconify mdi--archive-off text-3xl"></span>
                                            <span className="text-sm font-medium"><Trans>Noch keine Presets angelegt.</Trans></span>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {editing && (
                <PresetEditor
                    initialName={editing.name}
                    initialTiers={editing.tiers}
                    onSave={handleSave}
                    onCancel={() => setEditing(null)}
                />
            )}
        </div>
    );
}
