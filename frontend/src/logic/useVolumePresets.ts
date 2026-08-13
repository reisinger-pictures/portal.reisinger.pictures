import useSWR from 'swr';
import { apiMutate, fetcher, VolumePreset, VolumePresetTier } from '../api';

export interface VolumePresetsResponse {
    presets: VolumePreset[];
}

export interface VolumePresetPayload {
    name: string;
    tiers: Array<Pick<VolumePresetTier, 'min_quantity' | 'price_cents'>>;
}

export function useVolumePresets() {
    const { data, isLoading, mutate } = useSWR<VolumePresetsResponse>('/api/management/settings/volume-presets', fetcher);

    const createPreset = async (payload: VolumePresetPayload) => {
        await apiMutate('/api/management/settings/volume-presets', 'POST', payload);
        await mutate();
    };

    const updatePreset = async (id: string, payload: VolumePresetPayload) => {
        await apiMutate(`/api/management/settings/volume-presets/${id}`, 'PUT', payload);
        await mutate();
    };

    const deletePreset = async (id: string) => {
        await apiMutate(`/api/management/settings/volume-presets/${id}`, 'DELETE');
        await mutate();
    };

    const setDefaultPreset = async (id: string) => {
        await apiMutate(`/api/management/settings/volume-presets/${id}/default`, 'POST', {});
        await mutate();
    };

    return { presets: data?.presets, isLoading, createPreset, updatePreset, deletePreset, setDefaultPreset };
}
