import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('swr', () => ({
    default: vi.fn(),
    mutate: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
    apiUpload: vi.fn(),
}));

import { apiMutate } from '../../api';
import { createEurInvoiceCorrection } from '../useEur';

describe('createEurInvoiceCorrection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('posts the refund as whole cents to the correction endpoint', async () => {
        vi.mocked(apiMutate).mockResolvedValue(undefined);

        await createEurInvoiceCorrection({
            invoice_number: 'P-2026-0001-0001',
            refund_cents: 7000,
            reason: 'Teilweise erstattet',
            year: 2026,
            month: 5,
            day: 4,
        });

        expect(apiMutate).toHaveBeenCalledWith('/api/management/eur/entries/invoice-corrections', 'POST', {
            invoice_number: 'P-2026-0001-0001',
            refund_cents: 7000,
            reason: 'Teilweise erstattet',
            year: 2026,
            month: 5,
            day: 4,
        });
    });

    it('propagates API errors so the panel can show the server reason', async () => {
        vi.mocked(apiMutate).mockRejectedValue(new Error('Rechnung nicht gefunden.'));

        await expect(createEurInvoiceCorrection({
            invoice_number: 'P-2026-0001-9999',
            refund_cents: 100,
            reason: 'Erstattung',
            year: 2026,
            month: 5,
            day: 4,
        })).rejects.toThrow('Rechnung nicht gefunden.');
    });
});
