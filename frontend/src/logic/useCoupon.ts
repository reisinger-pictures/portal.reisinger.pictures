/**
 * useCoupon – Frontend hook for SRP-01 coupon validation.
 *
 * Manages the lifecycle of a single, client-side coupon code: validate
 * against the backend, expose the resulting discount preview, and reset
 * the state on removal. This is a one-shot validation (no SWR needed)
 * because the user enters at most one code per checkout.
 *
 * Backend contract (POST /api/coupons/validate):
 *  - Success: { valid: true, coupon: { id, code, type, value, scope_type }, discount_cents?: number }
 *  - Failure: { valid: false, error: string }
 *  - Network/HTTP error: caught and surfaced as a German error message.
 *
 * @see features/ecommerce/08-srp-coupon-system.md
 */

import {useCallback, useState} from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface CouponSummary {
    id: number | string;
    code: string;
    type: 'fixed' | 'percentage' | 'free_items';
    value: number;
    scope_type: 'global' | 'gallery' | 'meta_gallery' | 'photographer';
}

interface ValidateSuccessResponse {
    valid: true;
    coupon: CouponSummary;
    /** Optional discount preview in cents (backend may compute this). */
    discount_cents?: number;
}

interface ValidateFailureResponse {
    valid: false;
    error: string;
}

type ValidateResponse = ValidateSuccessResponse | ValidateFailureResponse;

export interface UseCouponOptions {
    galleryId?: number | string;
    metaGalleryId?: number | string;
    scopeGalleryId?: number | string;
}

export interface UseCouponResult {
    /** Currently applied coupon code, or null if none. */
    couponCode: string | null;
    /** True iff a coupon has been successfully validated and applied. */
    isValid: boolean;
    /** Computed/returned discount in cents, or null when no coupon is active. */
    discount: number | null;
    /** True while a validation request is in-flight. */
    isLoading: boolean;
    /** Human-readable error message (German), or null. */
    error: string | null;
    /** Validate and apply a coupon code. */
    applyCoupon: (code: string) => Promise<void>;
    /** Reset the coupon state to defaults. */
    removeCoupon: () => void;
}

// ---------------------------------------------------------------------------
// Defaults
// ---------------------------------------------------------------------------

const INITIAL_STATE = {
    couponCode: null as string | null,
    isValid: false,
    discount: null as number | null,
    isLoading: false,
    error: null as string | null,
};

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

/**
 * React hook for SRP coupon validation.
 *
 * - State is fully derived from explicit user actions (`applyCoupon`, `removeCoupon`)
 *   — no `useEffect` for derived state (AGENTS.md §2).
 * - The hook is fully decoupled from React Context so multiple components
 *   can be passed a `couponCode`/`applyCoupon` pair via props.
 */
export default function useCoupon(options?: UseCouponOptions): UseCouponResult {
    const {galleryId, metaGalleryId, scopeGalleryId} = options ?? {};
    const [couponCode, setCouponCode] = useState<string | null>(INITIAL_STATE.couponCode);
    const [isValid, setIsValid] = useState<boolean>(INITIAL_STATE.isValid);
    const [discount, setDiscount] = useState<number | null>(INITIAL_STATE.discount);
    const [isLoading, setIsLoading] = useState<boolean>(INITIAL_STATE.isLoading);
    const [error, setError] = useState<string | null>(INITIAL_STATE.error);

    const applyCoupon = useCallback(async (code: string): Promise<void> => {
        const trimmed = code.trim();
        if (!trimmed) {
            setError('Bitte einen Rabattcode eingeben.');
            setIsValid(false);
            setDiscount(null);
            setCouponCode(null);
            return;
        }

        setIsLoading(true);
        setError(null);
        setIsValid(false);
        setDiscount(null);

        let response: Response;
        try {
            response = await fetch('/api/coupons/validate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    code: trimmed,
                    ...(galleryId !== undefined && {gallery_id: galleryId}),
                    ...(metaGalleryId !== undefined && {meta_gallery_id: metaGalleryId}),
                    ...(scopeGalleryId !== undefined && {scope_gallery_id: scopeGalleryId}),
                }),
            });
        } catch {
            setIsLoading(false);
            setError('Netzwerkfehler: Rabattcode konnte nicht geprüft werden.');
            setCouponCode(null);
            setIsValid(false);
            setDiscount(null);
            return;
        }

        let payload: Partial<ValidateResponse>;
        try {
            payload = (await response.json()) as Partial<ValidateResponse>;
        } catch {
            // Non-JSON body — treat as generic error.
            payload = {};
        }

        if (response.ok && payload.valid === true) {
            const successPayload = payload as ValidateSuccessResponse;
            setCouponCode(successPayload.coupon.code);
            setIsValid(true);
            setDiscount(typeof successPayload.discount_cents === 'number'
                ? successPayload.discount_cents
                : null);
            setError(null);
        } else {
            const failurePayload = payload as ValidateFailureResponse;
            setCouponCode(null);
            setIsValid(false);
            setDiscount(null);
            setError(
                (typeof failurePayload.error === 'string' && failurePayload.error)
                || 'Rabattcode konnte nicht angewendet werden.'
            );
        }
        setIsLoading(false);
    }, [galleryId, metaGalleryId, scopeGalleryId]);

    const removeCoupon = useCallback((): void => {
        setCouponCode(INITIAL_STATE.couponCode);
        setIsValid(INITIAL_STATE.isValid);
        setDiscount(INITIAL_STATE.discount);
        setIsLoading(INITIAL_STATE.isLoading);
        setError(INITIAL_STATE.error);
    }, []);

    return {
        couponCode,
        isValid,
        discount,
        isLoading,
        error,
        applyCoupon,
        removeCoupon,
    };
}
