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
