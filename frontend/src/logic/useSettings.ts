import useSWR from 'swr';
import {fetcher} from '../api';
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
        const response = await fetch('/api/management/settings/watermark', {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            credentials: 'include',
            body: formData
        });
        if (!response.ok) {
            // Ohne diesen Check würde der Aufrufer trotz Serverfehler einen
            // Erfolgs-Toast anzeigen (res wird bislang nicht geprüft).
            throw new Error('Fehler beim Speichern');
        }
        await mutate();
    };

    return {watermark, updateWatermark};
}
