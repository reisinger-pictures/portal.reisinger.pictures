import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import userEvent from '@testing-library/user-event';
import {MemoryRouter} from 'react-router-dom';
import CouponInput from '../client/components/CouponInput';
import type {UseCouponResult} from '../../logic/useCoupon';

vi.mock('swr', () => ({
    default: () => ({
        data: {
            data: [
                { id: 1, code: 'ORG10', type: 'fixed', value: 10, scope_type: 'organisation', active: true, used_count: 0 },
            ],
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: 1,
        },
        error: null,
        isLoading: false,
        mutate: vi.fn(),
    }),
}));

vi.mock('../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn(), confirm: vi.fn().mockResolvedValue(true) }),
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div>{message}</div>,
}));

import ManagementCouponsView from '../management/ManagementCouponsView';

function makeState(overrides: Partial<UseCouponResult> = {}): UseCouponResult {
    return {
        couponCode: null,
        coupon: null,
        isValid: false,
        discount: null,
        isLoading: false,
        error: null,
        applyCoupon: vi.fn(),
        removeCoupon: vi.fn(),
        ...overrides,
    };
}

function renderCouponInput(state: UseCouponResult) {
    return renderWithProviders(<CouponInput state={state} />);
}

/** `Intl` separates amount and symbol with U+00A0 so the pair cannot wrap. */
const NBSP = String.fromCharCode(0xa0);

function photoPackageState(packageQuantity: number | null, packagePriceCents: number | null) {
    return makeState({
        couponCode: 'PAKET10',
        isValid: true,
        discount: 0,
        coupon: {
            code: 'PAKET10',
            type: 'photo_package',
            value: 0,
            package_quantity: packageQuantity ?? undefined,
            package_price_cents: packagePriceCents ?? undefined,
        },
    });
}

describe('CouponInput', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders input and button', () => {
        renderCouponInput(makeState());

        expect(screen.getByLabelText('Rabattcode')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Anwenden'})).toBeInTheDocument();
    });

    it('button disabled when input empty', () => {
        renderCouponInput(makeState());

        const button = screen.getByRole('button', {name: 'Anwenden'});
        expect(button).toBeDisabled();
    });

    it('calls onValidate with code on submit', async () => {
        const applyCoupon = vi.fn();
        renderCouponInput(makeState({applyCoupon}));

        const input = screen.getByLabelText('Rabattcode');
        await userEvent.type(input, 'SAVE10');

        const button = screen.getByRole('button', {name: 'Anwenden'});
        expect(button).toBeEnabled();

        await userEvent.click(button);

        expect(applyCoupon).toHaveBeenCalledWith('SAVE10');
    });

    it('shows loading state during validation', () => {
        renderCouponInput(makeState({isLoading: true}));

        expect(screen.getByText('Prüfe…')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Prüfe…'})).toBeDisabled();
    });

    it('shows valid state with discount info', () => {
        renderCouponInput(makeState({
            couponCode: 'SAVE10',
            isValid: true,
            discount: 1000,
        }));

        expect(screen.getByText('SAVE10')).toBeInTheDocument();
        expect(screen.getByText(/−/)).toBeInTheDocument();
        expect(screen.getByText('Entfernen')).toBeInTheDocument();
    });

    it('uses the current cart-priced discount instead of a stale sample amount', () => {
        renderWithProviders(
            <CouponInput
                state={makeState({couponCode: 'SAVE10', isValid: true, discount: 1000})}
                displayedDiscount={2500}
            />,
        );

        expect(screen.getByTestId('coupon-discount')).toHaveTextContent('25,00 €');
        expect(screen.queryByText('10,00 €')).not.toBeInTheDocument();
    });

    it('shows invalid state with error message', () => {
        renderCouponInput(makeState({
            error: 'Rabattcode nicht gefunden.',
        }));

        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('Rabattcode nicht gefunden.')).toBeInTheDocument();
    });

    it('remove button appears when coupon active', () => {
        renderCouponInput(makeState({
            couponCode: 'SAVE10',
            isValid: true,
        }));

        expect(screen.getByText('Entfernen')).toBeInTheDocument();
        expect(screen.getByLabelText('Rabattcode entfernen')).toBeInTheDocument();
    });

    it('remove button calls onRemove', async () => {
        const removeCoupon = vi.fn();
        renderCouponInput(makeState({
            couponCode: 'SAVE10',
            isValid: true,
            removeCoupon,
        }));

        await userEvent.click(screen.getByText('Entfernen'));

        expect(removeCoupon).toHaveBeenCalled();
    });

    it('input hidden when coupon active (shows applied coupon instead)', () => {
        renderCouponInput(makeState({
            couponCode: 'SAVE10',
            isValid: true,
        }));

        expect(screen.queryByLabelText('Rabattcode')).not.toBeInTheDocument();
        expect(screen.getByText('SAVE10')).toBeInTheDocument();
    });
});

/**
 * The photo-package line used to interpolate a `formatMoney` string — which
 * already carried the "€" and the non-breaking space — as a single placeholder
 * value. Number and symbol were welded into one opaque token, so no translator
 * could reorder or localise them. The amount now goes in as a symbol-free value
 * (`formatEuroDecimal`) and the message owns the `NBSP` + "€".
 */
/**
 * The package line has to be located by regex and then compared on raw
 * `textContent`: Testing Library's text matcher normalises the *element* string
 * with `/\s+/g` — which in JavaScript also swallows U+00A0 — but compares the
 * *matcher* string verbatim, so a `getByText` call containing a NBSP can never
 * match. Comparing `textContent` directly is what actually pins the code points,
 * which is the whole point of this refactor.
 */
function packageLine(): string {
    return screen.getByText(/Fotos für/).textContent ?? '';
}

describe('CouponInput photo package line', () => {
    /**
     * The acceptance criterion for moving the symbol out of the value: the
     * rendered text is *identical* to the pre-refactor output. German grouping
     * (period), decimal comma and the U+00A0 before the symbol must all survive.
     */
    it('renders grouping, decimal comma and the non-breaking space before the symbol', () => {
        renderCouponInput(photoPackageState(10, 123450));

        expect(packageLine()).toBe(`10 Fotos für 1.234,50${NBSP}€`);
    });

    /**
     * Guards the weld itself: a plain formatted amount would already satisfy the
     * assertion above, so the space is pinned as its own code point. A regular
     * space would let "1.234,50 €" break across a line end.
     */
    it('keeps the space before the symbol non-breaking', () => {
        renderCouponInput(photoPackageState(10, 123450));

        const line = packageLine();
        expect(line).toContain(NBSP);
        expect(line).not.toContain(' €');
    });

    /**
     * The symbol must not travel inside the value, and a package price is a cent
     * amount, so `formatEuroDecimal` (not `formatEuroWhole`) is the formatter
     * that keeps the cents: 1.234,50 must not be rounded to "1.235".
     */
    it('does not round the cents away', () => {
        renderCouponInput(photoPackageState(10, 123450));

        expect(packageLine()).toBe(`10 Fotos für 1.234,50${NBSP}€`);
    });

    it('renders a whole-euro price with two decimals', () => {
        renderCouponInput(photoPackageState(5, 7500));

        expect(packageLine()).toBe(`5 Fotos für 75,00${NBSP}€`);
    });

    it('keeps the placeholder for a non-finite package price', () => {
        renderCouponInput(photoPackageState(10, Number.NaN));

        expect(packageLine()).toBe(`10 Fotos für ---${NBSP}€`);
    });

    it('renders no package line without a quantity', () => {
        renderCouponInput(photoPackageState(null, 123450));

        expect(screen.queryByText(/Fotos für/)).not.toBeInTheDocument();
    });
});

describe('CouponInput additional', () => {
    it('forwards the typed code to the shared applyCoupon', async () => {
        const applyCoupon = vi.fn();
        renderCouponInput(makeState({ applyCoupon }));

        const input = screen.getByLabelText('Rabattcode');
        await userEvent.type(input, 'GALLERY10');

        await userEvent.click(screen.getByRole('button', { name: 'Anwenden' }));

        expect(applyCoupon).toHaveBeenCalledWith('GALLERY10');
    });

    it('shows Netzwerkfehler when fetch fails', () => {
        renderCouponInput(makeState({
            error: 'Netzwerkfehler: Rabattcode konnte nicht geprüft werden.',
        }));

        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('Netzwerkfehler: Rabattcode konnte nicht geprüft werden.')).toBeInTheDocument();
        expect(screen.getByLabelText('Rabattcode')).toBeInTheDocument();
    });
});

describe('organisation scope label in ManagementCouponsView', () => {
    it('renders "Organisation" label for organisation-scoped coupons', async () => {
        renderWithProviders(
            <MemoryRouter>
                <ManagementCouponsView />
            </MemoryRouter>,
        );

        await vi.waitFor(() => {
            expect(screen.getByText('Organisation')).toBeInTheDocument();
        });
    });
});
