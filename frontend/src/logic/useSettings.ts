import useSWR from 'swr';
import {apiUpload, fetcher} from '../api';
import {usePermissions} from './usePermissions';

export interface WatermarkSettings {
    text: string;
    opacity: number;
    has_svg?: boolean;
}

export function useSettings() {
    const {isAdmin} = usePermissions();
    const canFetch = isAdmin;

    const {data: watermark, mutate} = useSWR<WatermarkSettings>(
        canFetch ? '/api/management/settings/watermark' : null,
        fetcher
    );

    const updateWatermark = async (formData: FormData) => {
        try {
            await apiUpload<unknown>('/api/management/settings/watermark', formData);
        } catch (error: unknown) {
            // Keep the form-level error stable for the UI while the shared
            // helper has already applied refresh/retry and normalised details.
            throw new Error('Fehler beim Speichern', {cause: error});
        }
        await mutate();
    };

    return {watermark, updateWatermark};
}
