/**
 * CouponInput – coupon entry field for the client checkout.
 *
 * Renders a daisyUI `join` input group for entering a coupon code, plus
 * a result panel that reflects the validation state owned by the checkout
 * view (single shared `useCoupon()` instance passed down as `state`).
 */

import {useState} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import type {UseCouponResult} from '../../../logic/useCoupon';
import {formatMoney} from '../../../logic/utils';

interface CouponInputProps {
    /** Shared coupon state owned by the checkout view (single source of truth). */
    state: UseCouponResult;
    disabled?: boolean;
    /** Current server-priced amount; falls back to the hook's legacy preview. */
    displayedDiscount?: number | null;
}

export default function CouponInput({state, disabled = false, displayedDiscount: displayedDiscountProp}: CouponInputProps) {
    const {couponCode, coupon, isValid, discount, isLoading, error, applyCoupon, removeCoupon} = state;
    const displayedDiscount = displayedDiscountProp ?? discount;
    const [inputValue, setInputValue] = useState<string>('');

    const packageQuantity = coupon?.package_quantity ?? null;
    const packagePriceText = coupon != null ? formatMoney(coupon.package_price_cents ?? 0) : null;

    const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (disabled) return;
        await applyCoupon(inputValue);
    };

    const handleRemove = () => {
        removeCoupon();
        setInputValue('');
    };

    return (
        <div
            data-testid="coupon-input"
            data-state={isValid ? 'valid' : error ? 'invalid' : isLoading ? 'validating' : 'idle'}
            className="bg-base-100 p-4 rounded-box border border-base-300 shadow-sm space-y-3"
        >
            <h3 className="font-bold text-sm flex items-center gap-2">
                <span className="iconify mdi--ticket-percent-outline text-primary"></span>
                <Trans>Rabattcode</Trans>
            </h3>

            {isValid && couponCode ? (
                <div className="flex items-center justify-between gap-3 p-3 bg-success/10 border border-success/30 rounded-box">
                    <div className="flex items-center gap-2 min-w-0">
                        <span className="badge badge-success badge-sm uppercase text-xs tracking-wider"><Trans>Aktiv</Trans></span>
                        <span className="font-mono font-bold truncate">{couponCode}</span>
                        {typeof displayedDiscount === 'number' && displayedDiscount > 0 && (
                            <span className="text-success font-semibold whitespace-nowrap" data-testid="coupon-discount">
                                −{formatMoney(displayedDiscount)}
                            </span>
                        )}
                        {coupon?.type === 'photo_package' && packageQuantity != null && packagePriceText != null && (
                            <span className="text-sm opacity-80 whitespace-nowrap">
                                <Trans>{packageQuantity} Fotos für {packagePriceText}</Trans>
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={handleRemove}
                        disabled={disabled}
                        className="btn btn-ghost btn-sm text-error"
                        aria-label={t`Rabattcode entfernen`}
                    >
                        <span className="iconify mdi--close-circle-outline"></span>
                        <Trans>Entfernen</Trans>
                    </button>
                </div>
            ) : (
                <form onSubmit={handleSubmit} className="space-y-2">
                    <div className="join w-full">
                        <input
                            type="text"
                            value={inputValue}
                            onChange={(e) => setInputValue(e.target.value)}
                            placeholder={t`Code eingeben`}
                            disabled={isLoading || disabled}
                            aria-label={t`Rabattcode`}
                            className="input input-bordered join-item w-full bg-base-100"
                        />
                        <button
                            type="submit"
                            disabled={disabled || isLoading || inputValue.trim().length === 0}
                            className="btn btn-primary join-item"
                            aria-busy={isLoading}
                        >
                            {isLoading ? (
                                <>
                                    <span className="loading loading-spinner loading-sm"></span>
                                    <Trans>Prüfe…</Trans>
                                </>
                            ) : (
                                <Trans>Anwenden</Trans>
                            )}
                        </button>
                    </div>

                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-error flex items-center gap-1"
                        >
                            <span className="iconify mdi--alert-circle-outline"></span>
                            {error}
                        </p>
                    )}
                </form>
            )}
        </div>
    );
}
