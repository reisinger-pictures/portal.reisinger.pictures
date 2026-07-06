import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState } from 'react';
import useSWR from 'swr';
import useSWRMutation from 'swr/mutation';
import { fetcher, apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import { useAuth } from '../../../logic/useAuth';
import ErrorMessage from '../../components/ErrorMessage';
import CouponFormDrawer, { type Coupon } from './CouponFormDrawer';

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

const formatValue = (coupon: Coupon): string => {
    const numeric = Number(coupon.value);
    if (Number.isNaN(numeric)) return String(coupon.value);
    switch (coupon.type) {
        case 'fixed':
            return `${numeric.toFixed(2).replace('.', ',')} €`;
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

interface Props {
    galleryId: string;
}

export default function GalleryCouponsTab({ galleryId }: Props) {
    const { showToast, confirm } = useUI();
    const { user } = useAuth();
    const swrKey = `/api/management/galleries/${galleryId}/coupons`;
    const { data, error, isLoading, mutate } = useSWR<PaginatedCoupons>(swrKey, fetcher);
    const { trigger: deleteCoupon } = useSWRMutation<PaginatedCoupons, unknown, string, { id: number }>(
        swrKey,
        async (_key, { arg }) => apiMutate<PaginatedCoupons>(`/api/management/coupons/${arg.id}`, 'DELETE'),
    );
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);

    const openCreate = () => {
        setIsDrawerOpen(true);
    };

    const handleSave = async (payload: Partial<Coupon>) => {
        try {
            await apiMutate(`/api/management/galleries/${galleryId}/coupons`, 'POST', {
                ...payload,
                scope_type: 'gallery',
                scope_id: galleryId,
            });
            showToast('success', t`Coupon angelegt`);
            setIsDrawerOpen(false);
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Speichern`);
        }
    };

    const handleDelete = async (coupon: Coupon) => {
        const couponId = coupon.id;
        if (couponId === undefined) return;
        if (!user?.is_admin && !user?.is_super_admin && coupon.used_count > 0) return;
        const couponCode = coupon.code;
        if (!(await confirm({
            title: t`Coupon löschen?`,
            message: t`Möchtest du den Coupon "${couponCode}" wirklich entfernen?`,
            confirmColor: 'error',
        }))) {
            return;
        }
        try {
            await deleteCoupon(
                { id: couponId },
                {
                    optimisticData: (currentData) => currentData
                        ? { ...currentData, data: currentData.data.filter(c => c.id !== couponId), total: currentData.total - 1 }
                        : { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 },
                    rollbackOnError: true,
                },
            );
            showToast('success', t`Coupon gelöscht`);
        } catch {
            showToast('error', t`Fehler beim Löschen`);
        }
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
                <ErrorMessage message={t`Fehler beim Laden der Coupons.`} />
            </div>
        );
    }

    const coupons: Coupon[] = data?.data ?? [];
    const couponsLength = coupons.length;
    const couponsPlural = couponsLength === 1 ? '' : 's';

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div className="text-sm opacity-70">
                    {coupons.length === 0
                        ? <Trans>Keine Coupons für diese Galerie.</Trans>
                        : t`${couponsLength} Coupon${couponsPlural} für diese Galerie`}
                </div>
                <button className="btn btn-primary btn-sm" onClick={openCreate}>
                    <span className="iconify mdi--plus"></span>
                    <Trans>Coupon hinzufügen</Trans>
                </button>
            </div>

            <div className="overflow-x-auto">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                                <th><Trans>Code</Trans></th>
                                <th><Trans>Typ</Trans></th>
                                <th><Trans>Wert</Trans></th>
                                <th><Trans>Verwendung</Trans></th>
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
                                <td className="font-mono">{formatUsage(coupon)}</td>
                                <td>
                                    {coupon.active ? (
                                        <span className="badge badge-success font-bold"><Trans>Aktiv</Trans></span>
                                    ) : (
                                        <span className="badge badge-ghost font-bold"><Trans>Inaktiv</Trans></span>
                                    )}
                                </td>
                                <td className="text-right">
                                    <button
                                        className="btn btn-ghost btn-xs btn-square text-error"
                                        title={
                                            !user?.is_admin && !user?.is_super_admin && coupon.used_count > 0
                                                ? t`Löschen nicht möglich (bereits verwendet)`
                                                : t`Löschen`
                                        }
                                        disabled={!user?.is_admin && !user?.is_super_admin && coupon.used_count > 0}
                                        onClick={() => void handleDelete(coupon)}
                                    >
                                        <span className="iconify mdi--trash-can text-base"></span>
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {coupons.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-center py-10 opacity-50">
                                    <Trans>Keine Coupons für diese Galerie vorhanden.</Trans>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <CouponFormDrawer
                isOpen={isDrawerOpen}
                onClose={() => setIsDrawerOpen(false)}
                editingCoupon={null}
                onSave={handleSave}
            />
        </div>
    );
}
