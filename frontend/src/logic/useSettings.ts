import useSWR from 'swr';
import {fetcher} from '../api';
import {useAuth} from './useAuth';

export interface WatermarkSettings {
    text: string;
    opacity: number;
    has_svg?: boolean;
}

export function useSettings() {
    const {user} = useAuth();
    const canFetch = user?.is_admin;

    const {data: watermark, mutate} = useSWR<WatermarkSettings>(
        canFetch ? '/api/management/settings/watermark' : null,
        fetcher
    );

    const updateWatermark = async (formData: FormData) => {
        await fetch('/api/management/settings/watermark', {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            credentials: 'include',
            body: formData
        });
        await mutate();
    };

    return {watermark, updateWatermark};
}
