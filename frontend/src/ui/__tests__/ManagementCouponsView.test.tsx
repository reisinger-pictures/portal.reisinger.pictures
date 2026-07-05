import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import ManagementCouponsView from '../management/ManagementCouponsView';

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

vi.mock('../../logic/useBrand', () => ({
    useBrand: () => ({ isSrp: true }),
}));

vi.mock('../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn(), confirm: vi.fn().mockResolvedValue(true) }),
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div>{message}</div>,
}));

describe('ManagementCouponsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders organisation label for organisation-scoped coupon', async () => {
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
