import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import userEvent from '@testing-library/user-event';
import {MemoryRouter} from 'react-router-dom';
import CouponInput from '../client/components/CouponInput';

const mockUseCoupon = vi.fn();

vi.mock('../../logic/useCoupon', () => ({
    default: () => mockUseCoupon(),
}));

const mockLicensingMode = vi.fn();

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: () => mockLicensingMode(),
}));

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

function setupMocks(overrides: Record<string, unknown> = {}) {
    const defaults = {
        couponCode: null,
        isValid: false,
        discount: null,
        isLoading: false,
        error: null,
        applyCoupon: vi.fn(),
        removeCoupon: vi.fn(),
    };
    mockUseCoupon.mockReturnValue({...defaults, ...overrides});
}

function setupLicensing(mode: 'volume_licensing' | 'scope_licensing' = 'volume_licensing') {
    mockLicensingMode.mockReturnValue(mode);
}

function renderCouponInput() {
    return renderWithProviders(<CouponInput />);
}

describe('CouponInput', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setupLicensing('volume_licensing');
    });

    it('renders input and button', () => {
        setupMocks();
        renderCouponInput();

        expect(screen.getByLabelText('Rabattcode')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Anwenden'})).toBeInTheDocument();
    });

    it('button disabled when input empty', () => {
        setupMocks();
        renderCouponInput();

        const button = screen.getByRole('button', {name: 'Anwenden'});
        expect(button).toBeDisabled();
    });

    it('calls onValidate with code on submit', async () => {
        const applyCoupon = vi.fn();
        setupMocks({applyCoupon});
        renderCouponInput();

        const input = screen.getByLabelText('Rabattcode');
        await userEvent.type(input, 'SAVE10');

        const button = screen.getByRole('button', {name: 'Anwenden'});
        expect(button).toBeEnabled();

        await userEvent.click(button);

        expect(applyCoupon).toHaveBeenCalledWith('SAVE10');
    });

    it('shows loading state during validation', () => {
        setupMocks({isLoading: true});
        renderCouponInput();

        expect(screen.getByText('Prüfe…')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Prüfe…'})).toBeDisabled();
    });

    it('shows valid state with discount info', () => {
        setupMocks({
            couponCode: 'SAVE10',
            isValid: true,
            discount: 1000,
        });
        renderCouponInput();

        expect(screen.getByText('SAVE10')).toBeInTheDocument();
        expect(screen.getByText(/−/)).toBeInTheDocument();
        expect(screen.getByText('Entfernen')).toBeInTheDocument();
    });

    it('shows invalid state with error message', () => {
        setupMocks({
            error: 'Rabattcode nicht gefunden.',
        });
        renderCouponInput();

        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(screen.getByText('Rabattcode nicht gefunden.')).toBeInTheDocument();
    });

    it('remove button appears when coupon active', () => {
        setupMocks({
            couponCode: 'SAVE10',
            isValid: true,
        });
        renderCouponInput();

        expect(screen.getByText('Entfernen')).toBeInTheDocument();
        expect(screen.getByLabelText('Rabattcode entfernen')).toBeInTheDocument();
    });

    it('remove button calls onRemove', async () => {
        const removeCoupon = vi.fn();
        setupMocks({
            couponCode: 'SAVE10',
            isValid: true,
            removeCoupon,
        });
        renderCouponInput();

        await userEvent.click(screen.getByText('Entfernen'));

        expect(removeCoupon).toHaveBeenCalled();
    });

    it('input hidden when coupon active (shows applied coupon instead)', () => {
        setupMocks({
            couponCode: 'SAVE10',
            isValid: true,
        });
        renderCouponInput();

        expect(screen.queryByLabelText('Rabattcode')).not.toBeInTheDocument();
        expect(screen.getByText('SAVE10')).toBeInTheDocument();
    });
});

describe('CouponInput additional', () => {
    it('passes galleryId and scopeGalleryId to validation', async () => {
        const applyCoupon = vi.fn();
        setupMocks({ applyCoupon });
        renderCouponInput();

        const input = screen.getByLabelText('Rabattcode');
        await userEvent.type(input, 'GALLERY10');

        await userEvent.click(screen.getByRole('button', { name: 'Anwenden' }));

        expect(applyCoupon).toHaveBeenCalledWith('GALLERY10');
    });

    it('shows Netzwerkfehler when fetch fails', () => {
        setupMocks({
            error: 'Netzwerkfehler: Rabattcode konnte nicht geprüft werden.',
        });
        renderCouponInput();

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