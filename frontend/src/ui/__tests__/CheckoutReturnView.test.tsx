import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {MemoryRouter} from 'react-router-dom';
import {renderWithProviders} from '../../test-setup';
import CheckoutReturnView, {type CheckoutReturnViewProps} from '../client/CheckoutReturnView';
import {fetcher} from '../../api';

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

// The real layout renders the authenticated dashboard shell (sidebar, cart
// badge); this suite only asserts the return-view states.
vi.mock('../components/PageLayout', () => ({
    default: ({children}: {children: React.ReactNode}) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

const renderReturnView = (query = '?order_id=ord_1', props: CheckoutReturnViewProps = {}) => renderWithProviders(
    <MemoryRouter initialEntries={[`/checkout/return${query}`]}>
        <CheckoutReturnView pollIntervalMs={10} maxPolls={3} {...props}/>
    </MemoryRouter>,
);

describe('CheckoutReturnView', () => {
    beforeEach(() => {
        vi.resetAllMocks();
    });

    it('asks for the order reference when the URL carries none', () => {
        renderReturnView('');

        expect(screen.getByRole('alert')).toHaveTextContent('fehlt die Bestellreferenz');
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('shows the paid state and the invoice number reported by the server', async () => {
        vi.mocked(fetcher).mockResolvedValue({
            order_id: 'ord_1',
            status: 'paid',
            payment_status: 'paid',
            invoice_number: 'P-2026-0007',
        });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-paid')).toHaveTextContent('Zahlung erfolgreich');
        expect(screen.getByText(/P-2026-0007/)).toBeInTheDocument();
        expect(fetcher).toHaveBeenCalledWith(
            '/api/orders/ord_1/payment-status',
            expect.objectContaining({signal: expect.anything()}),
        );
    });

    it('polls until the provider has confirmed the payment', async () => {
        vi.mocked(fetcher)
            .mockResolvedValueOnce({
                order_id: 'ord_1',
                status: 'pending_payment',
                payment_status: 'pending',
                checkout_url: null,
            })
            .mockResolvedValueOnce({
                order_id: 'ord_1',
                status: 'paid',
                payment_status: 'paid',
            });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-polling')).toBeInTheDocument();
        expect(fetcher).toHaveBeenCalledTimes(1);

        expect(await screen.findByTestId('payment-return-paid')).toBeInTheDocument();
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('treats a failed payment as not completed and links back to the cart', async () => {
        vi.mocked(fetcher).mockResolvedValue({
            order_id: 'ord_1',
            status: 'pending_payment',
            payment_status: 'failed',
            retryable: true,
        });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-not-completed')).toHaveTextContent('Die Zahlung wurde nicht abgeschlossen');
        expect(screen.getByRole('link', {name: 'Zurück zum Warenkorb'})).toBeInTheDocument();
    });

    it('shows the manual-review state for an ambiguous attempt', async () => {
        vi.mocked(fetcher).mockResolvedValue({
            order_id: 'ord_1',
            status: 'pending_payment',
            payment_status: 'ambiguous',
            payment_ambiguous: true,
        });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-ambiguous')).toHaveTextContent('Zahlung wird manuell geprüft');
    });

    it('lets the customer retry after a lookup error', async () => {
        const user = userEvent.setup();
        vi.mocked(fetcher)
            .mockRejectedValueOnce(new Error('network'))
            .mockResolvedValueOnce({
                order_id: 'ord_1',
                status: 'paid',
                payment_status: 'paid',
            });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-error')).toHaveTextContent('Der Zahlungsstatus konnte nicht geladen werden');
        await user.click(screen.getByRole('button', {name: 'Erneut prüfen'}));

        await waitFor(() => expect(screen.getByTestId('payment-return-paid')).toBeInTheDocument());
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('stops polling after the bounded number of attempts', async () => {
        vi.mocked(fetcher).mockResolvedValue({
            order_id: 'ord_1',
            status: 'pending_payment',
            payment_status: 'open',
        });

        renderReturnView();

        expect(await screen.findByTestId('payment-return-unconfirmed')).toHaveTextContent('Zahlung noch nicht bestätigt');
        expect(fetcher).toHaveBeenCalledTimes(3);
    });
});
