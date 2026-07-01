import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
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
    fixed: 'Festbetrag',
    percentage: 'Prozent',
    free_items: 'Gratis-Bilder',
};

const formatValue = (coupon: Coupon): string => {
    const numeric = Number(coupon.value);
    if (Number.isNaN(numeric)) return String(coupon.value);
    switch (coupon.type) {
        case 'fixed':
            return `${numeric.toFixed(2).replace('.', ',')} €`;
        case 'percentage':
            return `${numeric} %`;
        case 'free_items':
            return `${numeric} Bilder`;
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
    const swrKey = `/api/management/galleries/${galleryId}/coupons`;
    const { data, error, isLoading, mutate } = useSWR<PaginatedCoupons>(swrKey, fetcher);
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
            showToast('success', 'Coupon angelegt');
            setIsDrawerOpen(false);
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Speichern');
        }
    };

    const handleDelete = async (coupon: Coupon) => {
        if (coupon.id === undefined) return;
        if (coupon.used_count > 0) return;
        if (!(await confirm({
            title: 'Coupon löschen?',
            message: `Möchtest du den Coupon "${coupon.code}" wirklich entfernen?`,
            confirmColor: 'error',
        }))) {
            return;
        }
        try {
            await apiMutate(`/api/management/coupons/${coupon.id}`, 'DELETE');
            showToast('success', 'Coupon gelöscht');
            void mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Löschen');
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
                <ErrorMessage message="Fehler beim Laden der Coupons." />
            </div>
        );
    }

    const coupons: Coupon[] = data?.data ?? [];

    return (
        <div>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div className="text-sm opacity-70">
                    {coupons.length === 0
                        ? 'Keine Coupons für diese Galerie.'
                        : `${coupons.length} Coupon${coupons.length === 1 ? '' : 's'} für diese Galerie`}
                </div>
                <button className="btn btn-primary btn-sm" onClick={openCreate}>
                    <span className="iconify mdi--plus"></span>
                    Coupon hinzufügen
                </button>
            </div>

            <div className="overflow-x-auto">
                <table className="table table-zebra w-full">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Typ</th>
                            <th>Wert</th>
                            <th>Verwendung</th>
                            <th>Status</th>
                            <th className="text-right">Aktionen</th>
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
                                        <span className="badge badge-success font-bold">Aktiv</span>
                                    ) : (
                                        <span className="badge badge-ghost font-bold">Inaktiv</span>
                                    )}
                                </td>
                                <td className="text-right">
                                    <button
                                        className="btn btn-ghost btn-xs btn-square text-error"
                                        title={
                                            coupon.used_count > 0
                                                ? 'Löschen nicht möglich (bereits verwendet)'
                                                : 'Löschen'
                                        }
                                        disabled={coupon.used_count > 0}
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
                                    Keine Coupons für diese Galerie vorhanden.
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
