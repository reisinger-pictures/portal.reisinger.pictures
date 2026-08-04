/**
 * CouponInput – coupon entry field for the client checkout.
 *
 * Renders a daisyUI `join` input group for entering a coupon code, plus
 * a result panel that reflects the validation state of `useCoupon()`.
 */

import {useState, useCallback} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import useCoupon from '../../../logic/useCoupon';
import {formatMoney} from '../../../logic/utils';

interface CouponInputProps {
    galleryId?: string;
}

export default function CouponInput({galleryId}: CouponInputProps) {
    const {couponCode, isValid, discount, isLoading, error, applyCoupon, removeCoupon} = useCoupon({galleryId});
    const [inputValue, setInputValue] = useState<string>('');

    const handleSubmit = useCallback(async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        await applyCoupon(inputValue);
    }, [applyCoupon, inputValue]);

    const handleRemove = useCallback(() => {
        removeCoupon();
        setInputValue('');
    }, [removeCoupon]);

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
                        {typeof discount === 'number' && discount > 0 && (
                            <span className="text-success font-semibold whitespace-nowrap">
                                −{formatMoney(discount)}
                            </span>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={handleRemove}
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
                            disabled={isLoading}
                            aria-label={t`Rabattcode`}
                            className="input input-bordered join-item w-full bg-base-100"
                        />
                        <button
                            type="submit"
                            disabled={isLoading || inputValue.trim().length === 0}
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
