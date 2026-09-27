import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ManagementOrdersView from '../management/ManagementOrdersView';

vi.mock('swr', () => ({
    default: vi.fn(),
    mutate: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate } from '../../api';
import { useUI } from '../components/UIContext';

const mockOrders = [
    {
        id: 'o1',
        status: 'pending',
        is_quote_request: true,
        total_net: '10000',
        total_gross: '11900',
        tax_rate: 19,
        created_at: '2025-06-01T10:00:00Z',
        user: { id: 'u1', name: 'Max Mustermann', email: 'max@example.com' },
        invoice_snapshot: {
            invoice_number: 'R-2025-001',
            customer_details: JSON.stringify({
                quote_message: 'Bitte um Angebot',
                items: [{ filename: 'bild1.jpg', notes: 'Nur Druckrechte' }],
            }),
        },
    },
    {
        id: 'o2',
        status: 'paid',
        is_quote_request: false,
        total_net: '50000',
        total_gross: '59500',
        tax_rate: 19,
        created_at: '2025-05-15T08:00:00Z',
        user: { id: 'u2', name: 'Erika Musterfrau', email: 'erika@example.com' },
        invoice_snapshot: { invoice_number: 'R-2025-002', customer_details: '' },
    },
];

function renderView() {
    return renderWithProviders(
        <MemoryRouter>
            <ManagementOrdersView />
        </MemoryRouter>,
    );
}

describe('ManagementOrdersView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('shows loading spinner while orders are loading', () => {
        renderView();
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('shows error message on fetch error', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: new Error('Network error'),
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();
        expect(screen.getByText('Fehler beim Laden der Bestellungen.')).toBeInTheDocument();
    });

    it('shows empty state when no orders exist', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: [],
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();
        expect(screen.getByText('Noch keine Bestellungen im System.')).toBeInTheDocument();
    });

    it('renders orders table with correct data', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();

        expect(screen.getByText('Bestellungen & Anfragen')).toBeInTheDocument();
        expect(screen.getByText('Max Mustermann')).toBeInTheDocument();
        expect(screen.getByText('max@example.com')).toBeInTheDocument();
        expect(screen.getByText('Erika Musterfrau')).toBeInTheDocument();
        expect(screen.getByText('R-2025-001')).toBeInTheDocument();
        expect(screen.getByText('R-2025-002')).toBeInTheDocument();
        expect(screen.getByText('Angebot')).toBeInTheDocument();
    });

    it('opens quote modal when clicking Kalkulieren & Antworten', async () => {
        const user = userEvent.setup();

        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();

        const calcButton = screen.getByText('Kalkulieren & Antworten');
        await user.click(calcButton);

        expect(screen.getByText('Angebot kalkulieren & senden')).toBeInTheDocument();
        expect(screen.getByText('Keine generelle Nachricht')).toBeInTheDocument();
    });

    it('send quote calls apiMutate and shows success toast', async () => {
        const user = userEvent.setup();
        const showToast = vi.fn();
        const mutate = vi.fn();
        const mockApiMutate = vi.fn().mockResolvedValue(undefined);

        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);

        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });
        vi.mocked(apiMutate).mockImplementation(mockApiMutate);

        renderView();

        await user.click(screen.getByText('Kalkulieren & Antworten'));

        const priceInput = screen.getByPlaceholderText('z.B. 450.00');
        await user.type(priceInput, '450.00');

        const messageInput = screen.getByPlaceholderText(/Hallo, hier ist mein Angebot/i);
        await user.type(messageInput, 'Testnachricht');

        await user.click(screen.getByText('Kalkulieren & E-Mail senden'));

        expect(mockApiMutate).toHaveBeenCalledWith(
            '/api/management/orders/o1/send-quote',
            'POST',
            expect.objectContaining({
                custom_price: 45000,
                message: 'Testnachricht',
            }),
        );
        expect(showToast).toHaveBeenCalledWith('success', 'Angebot per E-Mail gesendet!');
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    // The quote dialog used to be a bare `div.modal` + `.modal-box`: no dialog
    // role, no accessible name, no focus trap, no Escape. It is now on
    // ModalShell, so the shared contract applies to it too.
    it('exposes the quote dialog as a named aria-modal dialog and closes it on Escape', async () => {
        const user = userEvent.setup();

        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();

        await user.click(screen.getByText('Kalkulieren & Antworten'));

        const dialog = screen.getByRole('dialog', { name: 'Angebot kalkulieren & senden' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');

        // Escape now reaches the dialog and unmounts it. It did nothing before,
        // so this is the assertion that would have failed pre-migration.
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog', { name: 'Angebot kalkulieren & senden' })).not.toBeInTheDocument();
    });

    // Pinned deliberately. The price field carries `autoFocus`, and React
    // applies that during commit — but the focus trap's effect runs afterwards
    // and moves focus to the first focusable element, which is the shell's
    // labelled close button. So on open the dialog no longer lands the caret in
    // the price field. This is a real change in where focus starts, recorded
    // here so it is a decision rather than an accident; if the price field
    // should win, the shell needs an initial-focus hook and this test moves
    // with it.
    it('puts initial focus on the labelled close button rather than the autoFocus price field', async () => {
        const user = userEvent.setup();

        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();

        await user.click(screen.getByText('Kalkulieren & Antworten'));

        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Schließen' }));
        expect(document.activeElement).not.toBe(screen.getByPlaceholderText('z.B. 450.00'));
    });

    it('does not label the rights field as optional', async () => {
        const user = userEvent.setup();

        vi.mocked(useSWR).mockReturnValue({
            data: mockOrders,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        renderView();

        await user.click(screen.getByText('Kalkulieren & Antworten'));

        expect(screen.getByText('Nutzungsrechte')).toBeInTheDocument();
        expect(screen.queryByText(/\(optional\)/i)).not.toBeInTheDocument();
    });
});
