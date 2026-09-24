import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import ClientOrdersView from '../client/ClientOrdersView';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../../logic/useLicenseTerms', () => ({
    useBillingDetails: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div role="alert">{message}</div>,
}));

import useSWR from 'swr';
import { useBillingDetails } from '../../logic/useLicenseTerms';

const makeOrder = (id: string, status: string) => ({
    id,
    status,
    is_quote_request: false,
    total_gross: '1500',
    total_net: '1500',
    tax_rate: 0,
    created_at: '2026-09-24T10:00:00Z',
    updated_at: '2026-09-24T10:00:00Z',
    invoice_snapshot: {
        invoice_number: `INV-${id.toUpperCase()}`,
        total_gross: '1500',
        total_net: '1500',
        tax_rate: 0,
        created_at: '2026-09-24T10:00:00Z',
        customer_details: {
            items: [{ filename: 'photo.jpg', tier: 'web', price: 1500 }],
        },
    },
});

function renderView() {
    return renderWithProviders(
        <MemoryRouter>
            <ClientOrdersView />
        </MemoryRouter>,
    );
}

describe('ClientOrdersView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useSWR).mockReturnValue({
            data: [],
            error: undefined,
            isLoading: false,
        } as never);
        vi.mocked(useBillingDetails).mockReturnValue({
            billingDetails: undefined,
        } as never);
    });

    it('does not offer a ZIP download for a pending payment order', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: [makeOrder('pending', 'pending_payment'), makeOrder('paid', 'paid')],
            error: undefined,
            isLoading: false,
        } as never);

        renderView();

        const pendingCard = screen.getByText(/INV-PENDING/).closest('.card');
        const paidCard = screen.getByText(/INV-PAID/).closest('.card');
        expect(pendingCard).not.toBeNull();
        expect(paidCard).not.toBeNull();

        expect(within(pendingCard as HTMLElement).queryByRole('button', { name: 'Bilder ZIP' })).not.toBeInTheDocument();
        expect(within(pendingCard as HTMLElement).getByRole('button', { name: 'Beleg' })).toBeInTheDocument();
        expect(within(paidCard as HTMLElement).getByRole('button', { name: 'Bilder ZIP' })).toBeInTheDocument();
    });
});
