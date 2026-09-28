import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import ManagementCouponsView from '../management/ManagementCouponsView';

// `value` is cents for `fixed` and a percent for `percentage` (owner decision
// 2026-09-28) — the same column, two units, discriminated by `type`.
const COUPONS = [
    { id: 1, code: 'ORG10', type: 'fixed', value: 1000, scope_type: 'organisation', active: true, used_count: 0 },
    { id: 2, code: 'PCT10', type: 'percentage', value: 10, scope_type: 'organisation', active: true, used_count: 0 },
    { id: 3, code: 'PKG40', type: 'photo_package', value: 0, package_quantity: 10, package_price_cents: 4000, scope_type: 'organisation', active: true, used_count: 0 },
];

vi.mock('swr', () => ({
    default: () => ({
        data: {
            data: COUPONS,
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: COUPONS.length,
        },
        error: null,
        isLoading: false,
        mutate: vi.fn(),
    }),
}));

vi.mock('swr/mutation', () => ({
    default: () => ({ trigger: vi.fn().mockResolvedValue(undefined), isMutating: false }),
}));

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: () => 'volume_licensing',
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
            expect(screen.getAllByText('Organisation').length).toBeGreaterThan(0);
        });
    });

    /**
     * The displayed amount is the part that must not move. All three types come
     * out of one `value`-shaped fixture set, and each has to render what the
     * admin entered: 1000 cents as 10,00 €, 10 % as 10 %, 4000 cents as
     * 40,00 €.
     *
     * The `fixed` and `photo_package` rows are the two that a stray `* 100`
     * would silently inflate (1.000.000,00 € and 400.000,00 €), and the
     * `percentage` row is the one a stray `/ 100` would deflate to 0,1 %.
     */
    it('renders each coupon type in its own unit', async () => {
        renderWithProviders(
            <MemoryRouter>
                <ManagementCouponsView />
            </MemoryRouter>,
        );

        const row = async (code: string) => {
            const found = await screen.findByRole('row', { name: new RegExp(code) });
            return within(found);
        };

        const fixed = await row('ORG10');
        expect(fixed.getByText(/10,00\s*€/)).toBeInTheDocument();
        expect(fixed.queryByText(/1\.000\.000,00\s*€/)).not.toBeInTheDocument();

        const percentage = await row('PCT10');
        expect(percentage.getByText('10 %')).toBeInTheDocument();
        expect(percentage.queryByText('0,1 %')).not.toBeInTheDocument();

        const pkg = await row('PKG40');
        expect(pkg.getByText(/10 Fotos \/ 40,00\s*€/)).toBeInTheDocument();
        expect(pkg.queryByText(/400\.000,00\s*€/)).not.toBeInTheDocument();
    });
});
