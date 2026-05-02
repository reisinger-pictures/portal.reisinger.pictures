import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface LicenseUseCase { id: string; name: string; description: string; base_price: number; flatrate_tier: string; sort_order: number; is_commercial?: boolean; }
export interface LicenseModifier { id: string; name: string; description: string; percent_surcharge: number; is_included_in_flatrate: boolean; sort_order: number; }
export interface LicenseCatalog { use_cases: LicenseUseCase[]; modifiers: LicenseModifier[]; }

export function useLicenseCatalog() {
    const { data, isLoading, mutate } = useSWR<LicenseCatalog>('/api/settings/license-catalog', fetcher);
    
    const createUseCase = async (payload: Partial<LicenseUseCase>) => { await apiMutate('/api/management/settings/license-use-cases', 'POST', payload); await mutate(); };
    const updateUseCase = async (id: string, payload: Partial<LicenseUseCase>) => { await apiMutate(`/api/management/settings/license-use-cases/${id}`, 'PUT', payload); await mutate(); };
    const deleteUseCase = async (id: string) => { await apiMutate(`/api/management/settings/license-use-cases/${id}`, 'DELETE'); await mutate(); };
    
    const createModifier = async (payload: Partial<LicenseModifier>) => { await apiMutate('/api/management/settings/license-modifiers', 'POST', payload); await mutate(); };
    const updateModifier = async (id: string, payload: Partial<LicenseModifier>) => { await apiMutate(`/api/management/settings/license-modifiers/${id}`, 'PUT', payload); await mutate(); };
    const deleteModifier = async (id: string) => { await apiMutate(`/api/management/settings/license-modifiers/${id}`, 'DELETE'); await mutate(); };
    
    return { catalog: data, isLoading, createUseCase, updateUseCase, deleteUseCase, createModifier, updateModifier, deleteModifier };
}
