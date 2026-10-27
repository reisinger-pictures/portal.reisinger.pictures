import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen, fireEvent} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import ManagementPayoutsView from '../management/ManagementPayoutsView';

vi.mock('../../logic/usePayouts', () => ({
    useAdminPayouts: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import {useAdminPayouts} from '../../logic/usePayouts';
import {useUI} from '../components/UIContext';

function renderView() {
    return renderWithProviders(<ManagementPayoutsView />);
}

describe('ManagementPayoutsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useAdminPayouts).mockReturnValue({
            data: {pools: [], statements: []},
            isLoading: false,
            calculateMonth: vi.fn(),
            updateStatus: vi.fn(),
        } as never);
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('falls back to a valid month when the month input is cleared', () => {
        renderView();
        const monthInput = screen.getByDisplayValue(String(new Date().getMonth() + 1));

        fireEvent.change(monthInput, {target: {value: '5'}});
        expect((monthInput as HTMLInputElement).value).toBe('5');

        fireEvent.change(monthInput, {target: {value: ''}});
        expect((monthInput as HTMLInputElement).value).toBe('1');
    });

    it('falls back to the current year when the year input is cleared', () => {
        const currentYear = String(new Date().getFullYear());
        renderView();
        const yearInput = screen.getByDisplayValue(currentYear);

        fireEvent.change(yearInput, {target: {value: '2025'}});
        expect((yearInput as HTMLInputElement).value).toBe('2025');

        fireEvent.change(yearInput, {target: {value: ''}});
        expect((yearInput as HTMLInputElement).value).toBe(currentYear);
    });

    it('shows each payout correction as its own order-attributed line', () => {
        vi.mocked(useAdminPayouts).mockReturnValue({
            data: {
                pools: [],
                statements: [{
                    id: 'statement-1',
                    sequence_number: 'ST-2026-07-ABC123',
                    month: 7,
                    year: 2026,
                    total_shares_earned: 0,
                    pool_earnings_cents: 0,
                    delta_surcharge_earnings_cents: 0,
                    earned_amount_cents: -750,
                    rolled_over_amount_cents: 0,
                    total_payable_cents: -750,
                    status: 'rollover',
                    corrections: [{
                        id: 'correction-1',
                        order_id: 'order-1',
                        order_reference: 'INV-2026-001',
                        amount_cents: -750,
                        closed_month: 6,
                        closed_year: 2026,
                        settled_month: 7,
                        settled_year: 2026,
                        status: 'settled',
                    }],
                }],
            },
            isLoading: false,
            calculateMonth: vi.fn(),
            updateStatus: vi.fn(),
        } as never);

        renderView();

        const correctionLine = screen.getByText('Korrektur Bestellung INV-2026-001').closest('li');
        expect(correctionLine).toHaveTextContent(/-7,50/);
        expect(correctionLine).toHaveTextContent('Geschlossener Monat: 6 / 2026');
    });
});
