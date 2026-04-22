import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface PayoutPool { id: string; month: number; year: number; net_pool_cents: number; total_unique_downloads: number; total_shares: number; value_per_share_cents: number; }
export interface PhotographerStatement { id: string; sequence_number: string; month: number; year: number; total_shares_earned: number; pool_earnings_cents: number; delta_surcharge_earnings_cents: number; earned_amount_cents: number; rolled_over_amount_cents: number; total_payable_cents: number; status: string; user?: { id: string; name: string; email: string; }; }

export function useAdminPayouts() {
    const { data, isLoading, mutate } = useSWR<{pools: PayoutPool[], statements: PhotographerStatement[]}>('/api/management/payouts', fetcher);
    
    const calculateMonth = async (month: number, year: number, net_pool_cents: number) => {
        await apiMutate('/api/management/payouts/calculate', 'POST', { month, year, net_pool_cents });
        await mutate();
    };

    const updateStatus = async (id: string, action: 'approve' | 'pay') => {
        await apiMutate(`/api/management/payouts/${id}/${action}`, 'POST');
        await mutate();
    };

    return { data, isLoading, calculateMonth, updateStatus };
}

export function useMyPayouts() {
    const { data: statements, isLoading } = useSWR<PhotographerStatement[]>('/api/payouts/my-statements', fetcher);
    return { statements, isLoading };
}
