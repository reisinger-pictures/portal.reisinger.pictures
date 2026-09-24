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

const mockStripeLoader = vi.hoisted(() => ({
    status: 'ready' as 'loading' | 'ready' | 'error',
    retry: vi.fn<() => Promise<boolean>>(),
    listener: null as (() => void) | null,
}));

vi.mock('../../../logic/stripe', () => ({
    getStripeLoaderStatus: () => mockStripeLoader.status,
    subscribeToStripeLoaderStatus: (listener: () => void) => {
        mockStripeLoader.listener = listener;
        return () => {
            if (mockStripeLoader.listener === listener) mockStripeLoader.listener = null;
        };
    },
    retryStripeLoader: mockStripeLoader.retry,
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
        mockUseStripe.mockReturnValue({confirmPayment: mockConfirmPayment});
        mockUseElements.mockReturnValue({});
        mockStripeLoader.status = 'ready';
        mockStripeLoader.listener = null;
        mockStripeLoader.retry.mockReset();
        mockStripeLoader.retry.mockResolvedValue(false);
    });

    afterEach(() => {
        vi.useRealTimers();
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

    it('exposes retry, error, and invoice fallback when the Stripe loader returns null', async () => {
        const user = userEvent.setup();
        mockUseStripe.mockReturnValue(null);
        mockStripeLoader.status = 'error';
        mockStripeLoader.retry.mockImplementation(async () => {
            mockStripeLoader.status = 'loading';
            mockStripeLoader.listener?.();
            return false;
        });

        renderForm();

        expect(screen.getByRole('alert')).toHaveTextContent('Sicherer Zahlungsdienst nicht verfügbar');
        expect(screen.getByRole('link', {name: 'Rechnung als PDF öffnen'}))
            .toHaveAttribute('href', '/api/orders/ord_123/invoice');
        expect(screen.queryByRole('button', {name: 'Jetzt bezahlen'})).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', {name: 'Erneut versuchen'}));

        expect(mockStripeLoader.retry).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('status')).toHaveTextContent('Sicherer Zahlungsdienst wird geladen');
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
            paymentIntent: { status: 'requires_payment_method' },
        });

        renderForm();

        await user.click(findSubmitButton());

        expect(mockConfirmPayment).toHaveBeenCalledWith({
            elements: {},
            redirect: 'if_required',
        });
    });

    it('guards duplicate submits while payment confirmation is in flight', async () => {
        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: {status: 'requires_payment_method'},
        });

        renderForm();
        const form = document.querySelector('form');
        expect(form).not.toBeNull();
        fireEvent.submit(form!);
        fireEvent.submit(form!);

        await waitFor(() => {
            expect(mockShowToast).toHaveBeenCalledWith('info', 'Zahlung unvollständig — bitte erneut versuchen.');
        });
        expect(mockConfirmPayment).toHaveBeenCalledTimes(1);
    });

    it('refreshes once after a 401 poll, retries safely, and completes when paid', async () => {
        vi.useFakeTimers();
        mockConfirmPayment.mockResolvedValue({
            error: undefined,
            paymentIntent: { status: 'succeeded' },
        });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(null, {status: 401}))
            .mockResolvedValueOnce(new Response(null, {status: 200}))
            .mockResolvedValueOnce(new Response(
                JSON.stringify({id: 'ord_123', status: 'paid'}),
                {status: 200, headers: {'Content-Type': 'application/json'}}
            ));
        vi.stubGlobal('fetch', fetchMock);

        renderForm();
        submitForm();

        expect(defaultProps.onSuccess).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(2000);

        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/api/orders/ord_123',
            '/api/auth/refresh',
            '/api/orders/ord_123',
        ]);
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(mockConfirmPayment).toHaveBeenCalledTimes(1);
        expect(defaultProps.onSuccess).toHaveBeenCalledTimes(1);
        expect(defaultProps.onSuccess).toHaveBeenCalledWith(true);
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

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            JSON.stringify({id: 'ord_123', status: 'paid'}),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        )));

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

        vi.stubGlobal('fetch', vi.fn().mockImplementation(async () => new Response(
            JSON.stringify({id: 'ord_123', status: 'pending'}),
            {status: 200, headers: {'Content-Type': 'application/json'}}
        )));

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
