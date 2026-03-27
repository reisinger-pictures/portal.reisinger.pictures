import {mutate} from 'swr';
import {apiMutate, fetcher} from '../api';
import { IptcData } from '../ui/components/IptcMetadataEditor';

export interface PhotoVersion extends IptcData {
    id: string;
    created_at: string;
    user?: { id: string, name: string };
}

export function usePhoto() {
    const updateMetadata = async (id: string, data: IptcData) => {
        const res = await apiMutate<{ success: boolean }>( `/api/photos/${id}/meta`, 'PUT', data);
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
        return res;
    };

    const getVersions = async (id: string) => {
        return await fetcher<PhotoVersion[]>( `/api/photos/${id}/versions`);
    };

    const revertMetadata = async (id: string, versionId: string) => {
        const res = await apiMutate( `/api/photos/${id}/revert/${versionId}`, 'POST');
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
        return res;
    };

    const deletePhoto = async (id: string) => {
        await apiMutate(`/api/photos/${id}`, 'DELETE');
        mutate(key => typeof key === 'string' && key.includes('/api/galleries/'));
    };

    return {updateMetadata, getVersions, revertMetadata, deletePhoto};
}
