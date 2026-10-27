import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import {useMyPayouts} from '../../logic/usePayouts';
import type {PhotographerStatement} from '../../logic/usePayouts';
import PhotographerPayoutsView from '../photographer/PhotographerPayoutsView';

vi.mock('../../logic/usePayouts', () => ({
    useMyPayouts: vi.fn(),
}));

const statement: PhotographerStatement = {
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
};

describe('PhotographerPayoutsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useMyPayouts).mockReturnValue({statements: [statement], isLoading: false});
    });

    it('shows the refund correction as its own order-attributed line', () => {
        renderWithProviders(<PhotographerPayoutsView />);

        const correctionLine = screen.getByText('Korrektur Bestellung INV-2026-001').closest('li');
        expect(correctionLine).toHaveTextContent(/-7,50/);
        expect(correctionLine).toHaveTextContent('Geschlossener Monat: 6 / 2026');
    });
});
