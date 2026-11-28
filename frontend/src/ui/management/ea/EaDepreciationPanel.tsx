import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import { useSWRConfig } from 'swr';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    EUR_MONTHS as MONTHS,
    createEaAsset,
    fetchEaComparison,
    type EaAssetComparison,
} from '../../../logic/useEa';

const cents = (value: number): string => formatEuro(value / 100);

/** #34 P5.4: the business share is entered in 5-% steps (50–100). */
const BUSINESS_SHARES = [50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100];

interface FormState {
    name: string;
    invoiceNo: string;
    supplier: string;
    costEuro: string;
    usefulLife: string;
    ratePercent: string;
    acquisitionMonth: string;
    businessShare: string;
    isPrivate: boolean;
}

const INITIAL: FormState = {
    name: '',
    invoiceNo: '',
    supplier: '',
    costEuro: '20000',
    usefulLife: '10',
    ratePercent: '30',
    acquisitionMonth: '1',
    businessShare: '100',
    isPrivate: false,
};

interface Props {
    year: number;
}

export default function EaDepreciationPanel({ year }: Props) {
    const { mutate } = useSWRConfig();
    const [form, setForm] = useState<FormState>(INITIAL);
    const [result, setResult] = useState<EaAssetComparison | null>(null);
    const [category, setCategory] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isCreating, setIsCreating] = useState(false);

    const costCents = Math.round(Number(form.costEuro.replace(',', '.')) * 100);
    const usefulLife = Number(form.usefulLife);
    const rate = Math.round(Number(form.ratePercent.replace(',', '.')) * 100);
    const month = Number(form.acquisitionMonth);
    const businessShare = Number(form.businessShare);

    // Comparing is a calculator and needs only the calculation inputs. The
    // asset identity fields are required for the separate create action;
    // coupling them would disable the calculator for an empty new-item form.
    const canCompare = Number.isFinite(costCents) && costCents > 0
        && Number.isInteger(usefulLife) && usefulLife >= 2 && usefulLife <= 99
        && Number.isInteger(rate) && rate >= 100 && rate <= 3000
        && Number.isInteger(month) && month >= 1 && month <= 12
        && BUSINESS_SHARES.includes(businessShare);
    const canCreate = canCompare && form.name.trim() !== '' && form.invoiceNo.trim() !== ''
        && form.supplier.trim() !== '' && category.trim() !== '';

    const update = (key: keyof FormState, value: string | boolean) => {
        setForm((current) => ({...current, [key]: value}));
    };

    const compare = async () => {
        if (!canCompare || isLoading) return;
        setIsLoading(true);
        setError(null);
        try {
            const comparison = await fetchEaComparison({
                cost_cents: costCents,
                useful_life_years: usefulLife,
                degressive_rate: rate,
                acquisition_month: month,
                name: form.name.trim(),
                business_share: businessShare,
            });
            setResult(comparison);
            setCategory(comparison.suggested_category?.type ?? '');
        } catch {
            setResult(null);
            setError('Der Vergleich konnte nicht berechnet werden. Prüfen Sie die Eingaben und versuchen Sie es erneut.');
        } finally {
            setIsLoading(false);
        }
    };

    const create = async (method: 'linear' | 'degressiv') => {
        if (!result || !canCreate || isCreating) return;
        setIsCreating(true);
        setError(null);
        try {
            await createEaAsset({
                name: form.name.trim(),
                invoice_no: form.invoiceNo.trim(),
                supplier: form.supplier.trim(),
                purchase_date: `${year}-${String(month).padStart(2, '0')}-01`,
                cost_cents: costCents,
                useful_life_years: usefulLife,
                business_share: businessShare,
                method,
                degressive_rate: method === 'degressiv' ? rate : null,
                category: category.trim(),
                is_private_investment: form.isPrivate,
            });
            await mutate('/api/management/ea/assets');
            setResult(null);
            setForm(INITIAL);
        } catch {
            setError('Das Anlagegut konnte nicht angelegt werden. Bitte erneut versuchen.');
        } finally {
            setIsCreating(false);
        }
    };

    const switchYear = result?.switch_recommended_year ?? null;
    const switchAdvantage = result ? cents(result.switch_advantage_cents) : '';
    const e1a = result?.suggested_category?.e1a ?? null;
    const categoryLabel = t`AfA-Kategorie`;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6">
                <h2 className="card-title text-primary"><Trans>Abschreibung vergleichen</Trans></h2>
                <p className="text-sm opacity-70">
                    <Trans>Neue Anlagegüter werden mit beiden Verfahren verglichen. Die vorgeschlagene AfA-Kategorie können Sie bestätigen oder ändern.</Trans>
                </p>

                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-2">
                    <label className="form-control">
                        <span className="label-text"><Trans>Bezeichnung</Trans></span>
                        <input type="text" className="input input-bordered" value={form.name}
                            onChange={(event) => update('name', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Rechnungsnummer</Trans></span>
                        <input type="text" className="input input-bordered" value={form.invoiceNo}
                            onChange={(event) => update('invoiceNo', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Lieferant</Trans></span>
                        <input type="text" className="input input-bordered" value={form.supplier}
                            onChange={(event) => update('supplier', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Anschaffungskosten (€)</Trans></span>
                        <input type="text" inputMode="decimal" className="input input-bordered" value={form.costEuro}
                            onChange={(event) => update('costEuro', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Nutzungsdauer (Jahre)</Trans></span>
                        <input type="number" min={2} max={99} className="input input-bordered" value={form.usefulLife}
                            onChange={(event) => update('usefulLife', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Degressiver Satz (%)</Trans></span>
                        <input type="number" min={1} max={30} className="input input-bordered" value={form.ratePercent}
                            onChange={(event) => update('ratePercent', event.target.value)} required />
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Anschaffungsmonat</Trans></span>
                        <select className="select select-bordered" value={form.acquisitionMonth}
                            onChange={(event) => update('acquisitionMonth', event.target.value)} required>
                            {MONTHS.map((name, index) => (
                                <option key={name} value={String(index + 1)}>{name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="form-control">
                        <span className="label-text"><Trans>Betriebsanteil</Trans></span>
                        <select className="select select-bordered" value={form.businessShare}
                            onChange={(event) => update('businessShare', event.target.value)} required>
                            {BUSINESS_SHARES.map((share) => (
                                <option key={share} value={String(share)}>{share} %</option>
                            ))}
                        </select>
                    </label>
                </div>

                <label className="label mt-2 w-fit">
                    <input type="checkbox" className="checkbox checkbox-sm" checked={form.isPrivate}
                        onChange={(event) => update('isPrivate', event.target.checked)} />
                    <span className="label-text ml-2"><Trans>Privat- oder Mischnutzung</Trans></span>
                </label>

                <div className="mt-4">
                    <button type="button" className="btn btn-primary btn-sm" onClick={() => void compare()} disabled={!canCompare || isLoading}>
                        <Trans>Vergleich berechnen</Trans>
                    </button>
                </div>

                {error && (
                    <div className="alert alert-error mt-4" role="alert"><span>{error}</span></div>
                )}

                {result && (
                    <div className="mt-6">
                        {switchYear !== null ? (
                            <p className="mb-3">
                                <Trans>Empfohlener Wechsel zur linearen Abschreibung: Jahr {switchYear}. Vorteil gegenüber der durchgehenden degressiven Methode: {switchAdvantage}.</Trans>
                            </p>
                        ) : (
                            <p className="mb-3"><Trans>Für diese Angaben ist kein Wechsel zur linearen Methode vorteilhaft.</Trans></p>
                        )}

                        <h3 className="font-semibold mb-2"><Trans>Anschaffungsjahr im Vergleich</Trans></h3>
                        <div className="overflow-x-auto">
                            <table className="table table-sm">
                                <thead>
                                    <tr>
                                        <th><Trans>Position</Trans></th>
                                        <th className="text-right"><Trans>Linear</Trans></th>
                                        <th className="text-right"><Trans>Degressiv</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><Trans>Abschreibung</Trans></td>
                                        <td className="text-right">{cents(result.first_year.linear_cents)}</td>
                                        <td className="text-right">{cents(result.first_year.degressive_cents)}</td>
                                    </tr>
                                    <tr>
                                        <td><Trans>Restbuchwert</Trans></td>
                                        <td className="text-right">{cents(result.linear[0]?.book_value_cents ?? 0)}</td>
                                        <td className="text-right">{cents(result.degressive[0]?.book_value_cents ?? 0)}</td>
                                    </tr>
                                    <tr>
                                        <td><Trans>Betriebsanteil</Trans></td>
                                        <td className="text-right">{cents(result.first_year.linear_business_cents)}</td>
                                        <td className="text-right">{cents(result.first_year.degressive_business_cents)}</td>
                                    </tr>
                                    <tr>
                                        <td><Trans>Privatanteil</Trans></td>
                                        <td className="text-right">{cents(result.first_year.linear_cents - result.first_year.linear_business_cents)}</td>
                                        <td className="text-right">{cents(result.first_year.degressive_cents - result.first_year.degressive_business_cents)}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-4 max-w-md">
                            <label className="form-control">
                                <span className="label-text">{categoryLabel}{e1a ? ` · ${e1a}` : ''}</span>
                                <input type="text" className="input input-bordered" value={category}
                                    onChange={(event) => setCategory(event.target.value)} required />
                            </label>
                            <div className="mt-2 flex flex-wrap gap-2">
                                <button type="button" className="btn btn-primary btn-sm" disabled={isCreating || !canCreate}
                                    onClick={() => void create('linear')}>
                                    <Trans>Linear anlegen</Trans>
                                </button>
                                <button type="button" className="btn btn-outline btn-sm" disabled={isCreating || !canCreate}
                                    onClick={() => void create('degressiv')}>
                                    <Trans>Degressiv anlegen</Trans>
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
