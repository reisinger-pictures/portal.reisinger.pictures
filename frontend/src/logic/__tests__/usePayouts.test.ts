import {describe, it, expect, vi, beforeEach} from 'vitest';
import {renderHook} from '@testing-library/react';
import {useAdminPayouts, useMyPayouts} from '../usePayouts';
import type {PayoutPool, PhotographerStatement} from '../usePayouts';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

import useSWR from 'swr';
import {apiMutate, fetcher} from '../../api';

const pool: PayoutPool = {
    id: 'pool-1',
    month: 8,
    year: 2026,
    net_pool_cents: 250000,
    total_unique_downloads: 120,
    total_shares: 400,
    value_per_share_cents: 625,
};

const statement: PhotographerStatement = {
    id: 'stmt-1',
    sequence_number: '2026-0001',
    month: 8,
    year: 2026,
    total_shares_earned: 40,
    pool_earnings_cents: 25000,
    delta_surcharge_earnings_cents: 0,
    earned_amount_cents: 25000,
    rolled_over_amount_cents: 0,
    total_payable_cents: 25000,
    status: 'pending',
};

describe('useAdminPayouts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('reads the management payouts endpoint through the shared fetcher', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: {pools: [pool], statements: [statement]},
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const {result} = renderHook(() => useAdminPayouts());

        expect(useSWR).toHaveBeenCalledWith('/api/management/payouts', fetcher);
        expect(result.current.data?.pools).toEqual([pool]);
        expect(result.current.data?.statements).toEqual([statement]);
        expect(result.current.isLoading).toBe(false);
    });

    it('surfaces SWR loading before the payload arrives', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const {result} = renderHook(() => useAdminPayouts());

        expect(result.current.data).toBeUndefined();
        expect(result.current.isLoading).toBe(true);
    });

    it('calculateMonth posts the period and net pool, then revalidates', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const {result} = renderHook(() => useAdminPayouts());
        await result.current.calculateMonth(8, 2026, 250000);

        expect(apiMutate).toHaveBeenCalledWith('/api/management/payouts/calculate', 'POST', {
            month: 8,
            year: 2026,
            net_pool_cents: 250000,
        });
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('does not revalidate when calculateMonth rejects', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValue(new Error('Serverfehler'));

        const {result} = renderHook(() => useAdminPayouts());

        await expect(result.current.calculateMonth(8, 2026, 250000)).rejects.toThrow('Serverfehler');
        expect(mutate).not.toHaveBeenCalled();
    });

    it('updateStatus approve posts to the statement action, then revalidates', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const {result} = renderHook(() => useAdminPayouts());
        await result.current.updateStatus('stmt-1', 'approve');

        expect(apiMutate).toHaveBeenCalledWith('/api/management/payouts/stmt-1/approve', 'POST');
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('updateStatus pay posts to the statement action, then revalidates', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const {result} = renderHook(() => useAdminPayouts());
        await result.current.updateStatus('stmt-1', 'pay');

        expect(apiMutate).toHaveBeenCalledWith('/api/management/payouts/stmt-1/pay', 'POST');
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('does not revalidate when updateStatus rejects', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockRejectedValue(new Error('Freigabe fehlgeschlagen'));

        const {result} = renderHook(() => useAdminPayouts());

        await expect(result.current.updateStatus('stmt-1', 'approve')).rejects.toThrow('Freigabe fehlgeschlagen');
        expect(mutate).not.toHaveBeenCalled();
    });
});

describe('useMyPayouts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('reads the own-statements endpoint through the shared fetcher', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: [statement],
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const {result} = renderHook(() => useMyPayouts());

        expect(useSWR).toHaveBeenCalledWith('/api/payouts/my-statements', fetcher);
        expect(result.current.statements).toEqual([statement]);
        expect(result.current.isLoading).toBe(false);
    });

    it('surfaces SWR loading before the statements arrive', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const {result} = renderHook(() => useMyPayouts());

        expect(result.current.statements).toBeUndefined();
        expect(result.current.isLoading).toBe(true);
    });
});
