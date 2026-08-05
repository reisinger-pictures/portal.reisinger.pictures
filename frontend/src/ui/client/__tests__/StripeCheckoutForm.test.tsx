import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, fireEvent } from '@testing-library/react';
import { renderWithProviders } from '../../../test-setup';
import userEvent from '@testing-library/user-event';
import { StripeCheckoutForm } from '../components/StripeCheckoutForm';

const mockConfirmPayment = vi.fn();
const mockUseStripe = vi.fn(() => ({
    confirmPayment: mockConfirmPayment,
}));
const mockUseElements = vi.fn(() => ({}));
let mockShowToast = vi.fn();

vi.mock('@stripe/react-stripe-js', () => ({
    useStripe: () => mockUseStripe(),
    useElements: () => mockUseElements(),
    PaymentElement: ({ options }: { options?: Record<string, unknown> }) => (
        <div data-testid="payment-element" data-options={JSON.stringify(options)} />
    ),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast: mockShowToast }),
}));

const defaultProps = {
    orderId: 'ord_123',
    defaultEmail: 'test@example.com',
    defaultName: 'Test User',
    onSuccess: vi.fn(),
};

function renderForm() {
    return renderWithProviders(<StripeCheckoutForm {...defaultProps} />);
}

function findSubmitButton() {
    return document.querySelector('button[type="submit"]') as HTMLButtonElement;
}

function submitForm() {
    const form = document.querySelector('form');
    if (form) fireEvent.submit(form);
}

describe('StripeCheckoutForm', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockShowToast = vi.fn();
        mockConfirmPayment.mockReset();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders PaymentElement with default billing details', () => {
        renderForm();

        expect(screen.getByTestId('payment-element')).toBeInTheDocument();
        const element = screen.getByTestId('payment-element');
        const options = JSON.parse(element.getAttribute('data-options') || '{}');
        expect(options.defaultValues.billingDetails.name).toBe('Test User');
        expect(options.defaultValues.billingDetails.email).toBe('test@example.com');
    });

    it('includes billing address (AT) in PaymentElement default values when provided', () => {
        renderWithProviders(<StripeCheckoutForm {...defaultProps}
                                               billingAddress={{line1: 'Quote Str 1', postalCode: '1010', city: 'Wien'}} />);

        const element = screen.getByTestId('payment-element');
        const options = JSON.parse(element.getAttribute('data-options') || '{}');
        expect(options.defaultValues.billingDetails.address).toEqual({
            line1: 'Quote Str 1',
            postal_code: '1010',
            city: 'Wien',
            country: 'AT',
        });
    });

    it('renders submit button', () => {
        renderForm();

        expect(findSubmitButton()).toBeInTheDocument();
    });

    it('disables submit button when stripe is null', () => {
        mockUseStripe.mockReturnValueOnce(null);
        renderForm();

        expect(findSubmitButton()).toBeDisabled();
    });

    it('does not disable button when only elements is null', () => {
        mockUseElements.mockReturnValueOnce(null);
        renderForm();

        expect(findSubmitButton()).toBeEnabled();
    });

    it('shows loading spinner during processing', async () => {
        mockConfirmPayment.mockImplementation(() => new Promise(() => {}));

        renderForm();

        submitForm();

        expect(screen.getByText(/zahlung wird verifiziert/i)).toBeInTheDocument();
        expect(findSubmitButton()).toBeDisabled();
    });

    it('calls stripe.confirmPayment on submit', async () => {
        const user = userEvent.setup();

        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: { status: 'succeeded' },
        });

        renderForm();

        await user.click(findSubmitButton());

        expect(mockConfirmPayment).toHaveBeenCalledWith({
            elements: {},
            redirect: 'if_required',
        });
    });

    it('calls onSuccess(true) after successful payment', async () => {
        const user = userEvent.setup();

        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: { status: 'succeeded' },
        });

        renderForm();

        await user.click(findSubmitButton());

        await waitFor(() => {
            expect(defaultProps.onSuccess).toHaveBeenCalledWith(true);
        });
    });

    it('shows toast on stripe error', async () => {
        const user = userEvent.setup();

        mockConfirmPayment.mockResolvedValue({
            error: { message: 'Karte abgelehnt' },
            paymentIntent: undefined,
        });

        renderForm();

        await user.click(findSubmitButton());

        await waitFor(() => {
            expect(mockShowToast).toHaveBeenCalledWith('error', 'Karte abgelehnt');
        });
    });

    it('polls /api/orders/{orderId} when paymentIntent status is processing', async () => {
        vi.useFakeTimers();

        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: { status: 'processing' },
        });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ id: 'ord_123', status: 'paid' }),
        }));

        renderForm();

        submitForm();

        expect(defaultProps.onSuccess).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(2000);

        expect(defaultProps.onSuccess).toHaveBeenCalledWith(true);

        vi.useRealTimers();
        vi.unstubAllGlobals();
    }, 10000);

    it('calls onSuccess(false) after 30 polling failures', async () => {
        vi.useFakeTimers();

        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: { status: 'processing' },
        });

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ id: 'ord_123', status: 'pending' }),
        }));

        renderForm();

        submitForm();

        for (let i = 0; i < 31; i++) {
            await vi.advanceTimersByTimeAsync(2000);
        }

        expect(defaultProps.onSuccess).toHaveBeenCalledWith(false);

        vi.useRealTimers();
        vi.unstubAllGlobals();
    }, 30000);

    it('returns early if stripe is null on submit', () => {
        mockUseStripe.mockReturnValueOnce(null);
        mockUseElements.mockReturnValueOnce({});
        renderForm();

        submitForm();

        expect(mockConfirmPayment).not.toHaveBeenCalled();
    });
});
