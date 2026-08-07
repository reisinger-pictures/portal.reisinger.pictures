import {apiMutate, fetcher} from '../api';

export interface IptcData {
    title?: string;
    description?: string;
    artist?: string;
    headline?: string;
    keywords?: string;
    location?: string;
    city?: string;
    state?: string;
    country?: string;
    iso_country?: string;
    is_editorial_only?: boolean;
    effective_is_editorial_only?: boolean;
}

export interface PhotoVersionUser {
    id: string;
    name: string;
}

export interface PhotoVersion extends IptcData {
    id: string;
    created_at: string;
    user?: PhotoVersionUser;
}

export interface UpdateMetadataResponse {
    success: boolean;
}

export function usePhoto() {
    const updateMetadata = async (id: string, data: IptcData) => {
        const res = await apiMutate<UpdateMetadataResponse>(`/api/photos/${id}/meta`, 'PUT', data);
        return res;
    };

    const getVersions = async (id: string) => {
        return await fetcher<PhotoVersion[]>(`/api/photos/${id}/versions`);
    };

    const revertMetadata = async (id: string, versionId: string) => {
        const res = await apiMutate(`/api/photos/${id}/revert/${versionId}`, 'POST');
        return res;
    };

    const deletePhoto = async (id: string) => {
        await apiMutate(`/api/photos/${id}`, 'DELETE');
    };

    return {updateMetadata, getVersions, revertMetadata, deletePhoto};
}
