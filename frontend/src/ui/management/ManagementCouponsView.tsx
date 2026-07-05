import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import { useBrand } from '../../logic/useBrand';
import ErrorMessage from '../components/ErrorMessage';
import { formatMoney } from '../../logic/utils';
import CouponFormDrawer, { type Coupon } from './components/CouponFormDrawer';
import Pagination from '../components/Pagination';

interface PaginatedCoupons {
    data: Coupon[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const TYPE_LABELS: Record<Coupon['type'], string> = {
    fixed: t`Festbetrag`,
    percentage: t`Prozent`,
};

const SCOPE_LABELS: Record<Coupon['scope_type'], string> = {
    global: t`Global`,
    gallery: t`Galerie`,
    meta_gallery: t`Meta-Galerie`,
    photographer: t`Fotograf`,
    organisation: t`Organisation`,
};

const formatValue = (coupon: Coupon): string => {
    const numeric = Number(coupon.value);
    if (Number.isNaN(numeric)) return String(coupon.value);
    switch (coupon.type) {
        case 'fixed':
            return formatMoney(Math.round(numeric * 100));
        case 'percentage':
            return `${numeric} %`;
        default:
            return String(coupon.value);
    }
};

const formatUsage = (coupon: Coupon): string => {
    const used = coupon.used_count ?? 0;
    const max = coupon.max_uses_global;
    if (max === null || max === undefined) {
        return `${used} / ∞`;
    }
    return `${used} / ${max}`;
};

const formatExpiry = (iso: string | undefined): string => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('de-DE', { year: 'numeric', month: '2-digit', day: '2-digit' });
};

export default function ManagementCouponsView() {
    const { isSrp } = useBrand();
    const { showToast, confirm } = useUI();
    const [searchParams, setSearchParams] = useSearchParams();
    const page = Math.max(1, parseInt(searchParams.get('page') || '1', 10) || 1);
    const swrKey = `/api/management/coupons?page=${page}`;
    const { data, error, isLoading, mutate } = useSWR<PaginatedCoupons>(swrKey, fetcher);
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editingCoupon, setEditingCoupon] = useState<Coupon | null>(null);

    if (!isSrp) {
        return (
            <div className="p-6 md:p-10 max-w-3xl mx-auto w-full">
                <div className="bg-base-100 border border-base-300 rounded-box p-10 shadow-sm text-center">
                    <span className="iconify mdi--ticket-percent-outline text-5xl text-primary mb-4 inline-block"></span>
                    <h1 className="text-3xl font-bold mb-2"><Trans>Gutscheincode</Trans></h1>
                    <p className="opacity-70">
                        <Trans>Gutscheincodes sind nur auf buy.reisinger.pictures verfügbar.</Trans>
                    </p>
                </div>
            </div>
        );
    }

    const openCreate = () => {
        setEditingCoupon(null);
        setIsDrawerOpen(true);
    };

    const openEdit = (coupon: Coupon) => {
        setEditingCoupon(coupon);
        setIsDrawerOpen(true);
    };

    const handleSave = async (payload: Partial<Coupon>) => {
        try {
            if (editingCoupon && editingCoupon.id !== undefined) {
                await apiMutate(`/api/management/coupons/${editingCoupon.id}`, 'PUT', payload);
                showToast('success', t`Gutscheincode aktualisiert`);
            } else {
                await apiMutate('/api/management/coupons', 'POST', payload);
                showToast('success', t`Gutscheincode angelegt`);
            }
            setIsDrawerOpen(false);
            setEditingCoupon(null);
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Speichern`);
        }
    };

    const handleToggle = async (coupon: Coupon) => {
        if (coupon.id === undefined) return;
        try {
            await apiMutate(`/api/management/coupons/${coupon.id}`, 'PUT', {
                ...coupon,
                active: !coupon.active,
            });
            showToast('success', coupon.active ? t`Gutscheincode deaktiviert` : t`Gutscheincode aktiviert`);
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Umschalten`);
        }
    };

    const handleDelete = async (coupon: Coupon) => {
        if (coupon.id === undefined) return;
        if (coupon.used_count > 0) return;
        const couponCode = coupon.code;
        if (!(await confirm({
            title: t`Gutscheincode löschen?`,
                message: t`Möchtest du den Gutscheincode "${couponCode}" wirklich entfernen?`,
            confirmColor: 'error',
        }))) {
            return;
        }
        try {
            await apiMutate(`/api/management/coupons/${coupon.id}`, 'DELETE');
            showToast('success', t`Gutscheincode gelöscht`);
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Löschen`);
        }
    };

    const goToPage = (next: number) => {
        setSearchParams(prev => {
            const updated = new URLSearchParams(prev);
            if (next <= 1) {
                updated.delete('page');
            } else {
                updated.set('page', String(next));
            }
            return updated;
        });
    };

    if (isLoading && !data) {
        return (
            <div className="p-10 flex justify-center">
                <span className="loading loading-spinner loading-lg"></span>
            </div>
        );
    }
    if (error) {
        return (
            <div className="p-10">
                    <ErrorMessage message={t`Fehler beim Laden der Gutscheincodes.`} />
            </div>
        );
    }

    const coupons: Coupon[] = data?.data ?? [];
    const lastPage = data?.last_page ?? 1;
    const total = data?.total ?? 0;
    const totalPlural = total === 1 ? '' : 's';

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2 flex items-center gap-3">
                        <span className="iconify mdi--ticket-percent-outline text-primary"></span>
<Trans>Gutscheincode</Trans>
                    </h1>
                    <p className="opacity-70">
                        <Trans>Verwalte Rabattcodes für den SRP-Shop (B2C).</Trans>
                    </p>
                </div>
                <button className="btn btn-primary" onClick={openCreate}>
                    <span className="iconify mdi--plus"></span>
                    <Trans>Neuen Gutscheincode anlegen</Trans>
                </button>
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div className="text-sm opacity-70">
                        {total === 0
                            ? <Trans>Keine Gutscheincodes vorhanden.</Trans>
                            : t`${total} Gutscheincode${totalPlural} insgesamt`}
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th><Trans>Code</Trans></th>
                                <th><Trans>Typ</Trans></th>
                                <th><Trans>Wert</Trans></th>
                                <th><Trans>Scope</Trans></th>
                                <th><Trans>Verwendung</Trans></th>
                                <th><Trans>Läuft ab</Trans></th>
                                <th><Trans>Status</Trans></th>
                                <th className="text-right"><Trans>Aktionen</Trans></th>
                            </tr>
                        </thead>
                        <tbody>
                            {coupons.map(coupon => (
                                <tr key={coupon.id}>
                                    <td className="font-mono font-bold">{coupon.code}</td>
                                    <td>
                                        <span className="badge badge-outline whitespace-nowrap">
                                            {TYPE_LABELS[coupon.type]}
                                        </span>
                                    </td>
                                    <td className="font-mono">{formatValue(coupon)}</td>
                                    <td>{SCOPE_LABELS[coupon.scope_type]}</td>
                                    <td className="font-mono">{formatUsage(coupon)}</td>
                                    <td>{formatExpiry(coupon.expires_at)}</td>
                                    <td>
                                        {coupon.active ? (
                                            <span className="badge badge-success font-bold"><Trans>Aktiv</Trans></span>
                                        ) : (
                                            <span className="badge badge-ghost font-bold"><Trans>Inaktiv</Trans></span>
                                        )}
                                    </td>
                                    <td className="text-right">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                className="btn btn-ghost btn-xs btn-square"
                                                title={t`Bearbeiten`}
                                                onClick={() => openEdit(coupon)}
                                            >
                                                <span className="iconify mdi--pencil text-base"></span>
                                            </button>
                                            <button
                                                className="btn btn-ghost btn-xs btn-square"
                                                title={coupon.active ? t`Deaktivieren` : t`Aktivieren`}
                                                onClick={() => void handleToggle(coupon)}
                                            >
                                                <span
                                                    className={`iconify text-base ${coupon.active ? 'mdi--toggle-switch text-success' : 'mdi--toggle-switch-off text-base-content/50'}`}
                                                ></span>
                                            </button>
                                            <button
                                                className="btn btn-ghost btn-xs btn-square text-error"
                                                title={
                                                    coupon.used_count > 0
                                                        ? t`Löschen nicht möglich (bereits verwendet)`
                                                        : t`Löschen`
                                                }
                                                disabled={coupon.used_count > 0}
                                                onClick={() => void handleDelete(coupon)}
                                            >
                                                <span className="iconify mdi--trash-can text-base"></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {coupons.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-center py-10 opacity-50">
                                        <Trans>Keine Gutscheincodes gefunden. Lege den ersten Gutscheincode an.</Trans>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination page={page} lastPage={lastPage} onPageChange={goToPage} className="mt-6" />
            </div>

            <CouponFormDrawer
                isOpen={isDrawerOpen}
                onClose={() => {
                    setIsDrawerOpen(false);
                    setEditingCoupon(null);
                }}
                editingCoupon={editingCoupon}
                onSave={handleSave}
            />
        </div>
    );
}
