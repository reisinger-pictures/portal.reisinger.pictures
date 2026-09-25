/**
 * useCoupon – Frontend hook for SRP-01 coupon validation.
 *
 * Manages the lifecycle of a single, client-side coupon code: validate
 * against the backend, expose the resulting discount preview, and reset
 * the state on removal. This is a one-shot validation (no SWR needed)
 * because the user enters at most one code per checkout.
 *
 * Backend contract (POST /api/coupons/validate):
 *  - Success: { valid: true, coupon: { code, type, value }, discount_cents?: number }
 *  - Failure: { valid: false, error: string }
 *  - Network/HTTP error: caught and surfaced as a German error message.
 *
 * @see features/ecommerce/08-srp-coupon-system.md
 */

import {useRef, useState} from 'react';
import {t} from "@lingui/core/macro";
import {apiMutate} from '../api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface CouponSummary {
    code: string;
    type: 'fixed' | 'percentage' | 'photo_package';
    value: number;
    /** Photo-package: number of photos (N). */
    package_quantity?: number;
    /** Photo-package: flat price Y in cents. */
    package_price_cents?: number;
    /** Optional server limit for percentage coupons. */
    max_items?: number | null;
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

export type CouponScopeId = number | string;
export type CouponScope = CouponScopeId | readonly CouponScopeId[];

export interface UseCouponOptions {
    /** Gallery or complete mixed-gallery scope represented by the cart. */
    galleryId?: CouponScope;
    /** Gallery group (meta-gallery) or complete mixed-group scope. */
    metaGalleryId?: CouponScope;
    /**
     * Stable identity of the cart/quote validation context. A change discards
     * any previous validation, including an in-flight response.
     */
    validationContextKey?: string;
    /**
     * Server-priced volume subtotal in cents. When supplied, the hook computes
     * the display amount from the real cart instead of the legacy sample-cart
     * response from the validation endpoint.
     */
    pricedTotalCents?: number;
    /** Actual volume item prices used for percentage/package calculations. */
    eligibleItemPricesCents?: number[];
    /** Scope pricing and signed quotes do not support coupons. */
    enabled?: boolean;
}

export interface UseCouponResult {
    /** Currently applied coupon code, or null if none. */
    couponCode: string | null;
    /** Fully resolved coupon summary (type + params), or null if none. */
    coupon: CouponSummary | null;
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
    coupon: null as CouponSummary | null,
    isValid: false,
    discount: null as number | null,
    isLoading: false,
    error: null as string | null,
};

interface CouponState {
    contextKey: string;
    contextRevision: number;
    enabled: boolean;
    couponCode: string | null;
    coupon: CouponSummary | null;
    isValid: boolean;
    discount: number | null;
    isLoading: boolean;
    error: string | null;
}

type CouponStateData = Omit<CouponState, 'contextKey' | 'contextRevision' | 'enabled'>;

const initialCouponState = (
    contextKey: string,
    contextRevision: number,
    enabled: boolean,
): CouponState => ({
    contextKey,
    contextRevision,
    enabled,
    ...INITIAL_STATE,
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

interface CouponValidationRequest {
    code: string;
    galleryId?: CouponScope;
    metaGalleryId?: CouponScope;
}

type CouponValidationResult =
    | {kind: 'response'; payload: Partial<ValidateResponse>}
    | {kind: 'network'};

const isNetworkApiError = (error: unknown): boolean => (
    typeof error === 'object'
    && error !== null
    && 'status' in error
    && error.status === 0
);

const errorPayload = (error: unknown): Partial<ValidateResponse> => {
    if (
        typeof error === 'object'
        && error !== null
        && 'info' in error
        && typeof error.info === 'object'
        && error.info !== null
    ) {
        return error.info as Partial<ValidateResponse>;
    }
    if (error instanceof Error) return {error: error.message};
    return {};
};

const finiteNumber = (value: unknown, fallback = 0): number => {
    const parsed = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizeScopeIds = (scope?: CouponScope): CouponScopeId[] => {
    if (scope === undefined) return [];
    const values: readonly CouponScopeId[] = Array.isArray(scope) ? scope : [scope];
    const normalized = values
        .map(value => String(value).trim())
        .filter(value => value.length > 0);
    return Array.from(new Set(normalized));
};

const serializeScope = (
    original: CouponScope | undefined,
    normalized: CouponScopeId[],
): CouponScope | undefined => {
    if (original === undefined || normalized.length === 0) return undefined;
    return normalized.length === 1 ? normalized[0] : normalized;
};

const derivedValidationContextKey = (
    enabled: boolean,
    galleryId: CouponScope | undefined,
    metaGalleryId: CouponScope | undefined,
    pricedTotalCents: number | undefined,
    eligibleItemPricesCents: number[] | undefined,
): string => JSON.stringify({
    enabled,
    galleryIds: normalizeScopeIds(galleryId).map(String).sort(),
    metaGalleryIds: normalizeScopeIds(metaGalleryId).map(String).sort(),
    pricedTotalCents: pricedTotalCents ?? null,
    eligibleItemPricesCents: (eligibleItemPricesCents ?? [])
        .map(price => finiteNumber(price))
        .sort((a, b) => a - b),
});

/**
 * Calculates a cart-level coupon preview from the same cent values used for
 * checkout. The validation endpoint's sample-cart amount is intentionally not
 * used by the cart UI when a real pricing context is available.
 */
export function calculateCouponDiscount(
    coupon: CouponSummary,
    currentTotalCents: number,
    itemPricesCents: number[] = [],
): number {
    const total = Math.max(0, Math.round(finiteNumber(currentTotalCents)));
    const value = finiteNumber(coupon.value);

    if (coupon.type === 'fixed') {
        return Math.min(total, Math.max(0, Math.round(value * 100)));
    }

    if (coupon.type === 'percentage') {
        const percentage = Math.min(100, Math.max(0, value));
        if (coupon.max_items !== null && coupon.max_items !== undefined) {
            const maxItems = Math.max(0, Math.floor(finiteNumber(coupon.max_items)));
            const cheapestSubtotal = itemPricesCents
                .map(price => Math.max(0, Math.round(finiteNumber(price))))
                .sort((a, b) => a - b)
                .slice(0, maxItems)
                .reduce((sum, price) => sum + price, 0);
            return Math.min(total, Math.round(cheapestSubtotal * percentage / 100));
        }
        return Math.min(total, Math.round(total * percentage / 100));
    }

    const packageQuantity = Math.max(0, Math.floor(finiteNumber(coupon.package_quantity)));
    const packagePrice = Math.max(0, Math.round(finiteNumber(coupon.package_price_cents)));
    const payablePrices = itemPricesCents
        .map(price => Math.max(0, Math.round(finiteNumber(price))))
        .filter(price => price > 0)
        .sort((a, b) => a - b);
    const covered = Math.min(payablePrices.length, packageQuantity);
    const normalCovered = payablePrices.slice(0, covered).reduce((sum, price) => sum + price, 0);
    return Math.max(0, Math.min(total, normalCovered - Math.min(packagePrice, normalCovered)));
}

/**
 * Validates through the shared authenticated API pipeline. A 401 triggers the
 * centralized refresh and one retry of this read-only validation operation.
 */
async function requestCouponValidation(body: CouponValidationRequest): Promise<CouponValidationResult> {
    const galleryIds = normalizeScopeIds(body.galleryId);
    const metaGalleryIds = normalizeScopeIds(body.metaGalleryId);
    const galleryScope = serializeScope(body.galleryId, galleryIds);
    const metaGalleryScope = serializeScope(body.metaGalleryId, metaGalleryIds);

    try {
        const payload = await apiMutate<ValidateResponse>('/api/coupons/validate', 'POST', {
            code: body.code,
            ...(galleryScope !== undefined && {gallery_id: galleryScope}),
            ...(metaGalleryScope !== undefined && {meta_gallery_id: metaGalleryScope}),
        });
        return {kind: 'response', payload};
    } catch (error: unknown) {
        return isNetworkApiError(error)
            ? {kind: 'network'}
            : {kind: 'response', payload: errorPayload(error)};
    }
}

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
    const {
        galleryId,
        metaGalleryId,
        validationContextKey,
        pricedTotalCents,
        eligibleItemPricesCents,
        enabled = true,
    } = options ?? {};
    const contextKey = validationContextKey ?? derivedValidationContextKey(
        enabled,
        galleryId,
        metaGalleryId,
        pricedTotalCents,
        eligibleItemPricesCents,
    );
    const [state, setState] = useState<CouponState>(() => initialCouponState(contextKey, 0, enabled));
    const requestSequence = useRef(0);
    const contextChanged = state.contextKey !== contextKey || state.enabled !== enabled;
    if (contextChanged) {
        // React's supported render-phase adjustment for prop-derived state. The
        // revision makes A → B → A transitions distinct for in-flight requests.
        setState(initialCouponState(contextKey, state.contextRevision + 1, enabled));
    }
    const currentState = contextChanged
        ? initialCouponState(contextKey, state.contextRevision + 1, enabled)
        : state;

    const updateCurrentState = (next: CouponStateData): void => {
        setState(current => (
            current.contextKey === contextKey
                && current.contextRevision === currentState.contextRevision
                && current.enabled === enabled
                ? {
                    contextKey,
                    contextRevision: currentState.contextRevision,
                    enabled,
                    ...next,
                }
                : current
        ));
    };

    const applyCoupon = async (code: string): Promise<void> => {
        if (!enabled) return;
        const requestId = ++requestSequence.current;
        const trimmed = code.trim();
        if (!trimmed) {
            updateCurrentState({
                ...INITIAL_STATE,
                error: t`Bitte einen Rabattcode eingeben.`,
            });
            return;
        }

        updateCurrentState({...INITIAL_STATE, isLoading: true});
        const result = await requestCouponValidation({code: trimmed, galleryId, metaGalleryId});
        if (requestId !== requestSequence.current) return;

        if (result.kind === 'network') {
            updateCurrentState({
                ...INITIAL_STATE,
                error: t`Netzwerkfehler: Rabattcode konnte nicht geprüft werden.`,
            });
            return;
        }

        const {payload = {}} = result;
        if (payload.valid === true && payload.coupon) {
            const successPayload = payload as ValidateSuccessResponse;
            const serverSampleDiscount = typeof successPayload.discount_cents === 'number'
                ? successPayload.discount_cents
                : null;
            const calculatedDiscount = pricedTotalCents === undefined
                ? serverSampleDiscount
                : calculateCouponDiscount(
                    successPayload.coupon,
                    pricedTotalCents,
                    eligibleItemPricesCents ?? [],
                );
            updateCurrentState({
                couponCode: successPayload.coupon.code,
                coupon: successPayload.coupon,
                isValid: true,
                discount: calculatedDiscount,
                isLoading: false,
                error: null,
            });
        } else {
            const failurePayload = payload as ValidateFailureResponse;
            updateCurrentState({
                ...INITIAL_STATE,
                error: (typeof failurePayload.error === 'string' && failurePayload.error)
                    || t`Rabattcode konnte nicht angewendet werden.`,
            });
        }
    };

    const removeCoupon = (): void => {
        requestSequence.current += 1;
        updateCurrentState(INITIAL_STATE);
    };

    return {
        couponCode: currentState.couponCode,
        coupon: currentState.coupon,
        isValid: currentState.isValid,
        discount: currentState.discount,
        isLoading: currentState.isLoading,
        error: currentState.error,
        applyCoupon,
        removeCoupon,
    };
}
