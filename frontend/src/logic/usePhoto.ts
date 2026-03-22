import {mutate} from 'swr';
import {apiMutate, fetcher} from '../api';
import { IptcData } from '../ui/components/IptcMetadataEditor';

export interface PhotoVersion extends IptcData {
    id: number;
    created_at: string;
    user?: { id: number, name: string };
}

export function usePhoto() {
    const updateMetadata = async (id: number, data: IptcData) => {
        const res = await apiMutate<{ success: boolean }>( `/api/photos/${id}/meta`, 'PUT', data);
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
        return res;
    };

    const getVersions = async (id: number) => {
        return await fetcher<PhotoVersion[]>( `/api/photos/${id}/versions`);
    };

    const revertMetadata = async (id: number, versionId: number) => {
        const res = await apiMutate( `/api/photos/${id}/revert/${versionId}`, 'POST');
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
        return res;
    };

    const deletePhoto = async (id: number) => {
        await apiMutate(`/api/photos/${id}`, 'DELETE');
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
    };

    return {updateMetadata, getVersions, revertMetadata, deletePhoto};
}
