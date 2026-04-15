import useSWR from 'swr';
import {apiMutate, fetcher} from '../api';

export interface FtpTargetGallery {
    id: string;
    name: string;
    slug: string;
}

export interface FtpStatus {
    ftp_folder: string;
    file_count: number;
    current_target_gallery: FtpTargetGallery | null;
}

export interface ProcessInboxResponse {
    success: boolean;
    processed: number;
}

export function useFtp() {
    const {data: status, isLoading, mutate} = useSWR<FtpStatus>('/api/management/ftp/status', fetcher);

    const setTargetGallery = async (gallery_id: string | null) => {
        await apiMutate('/api/management/ftp/target', 'POST', {gallery_id});
        await mutate();
    };

    const processInbox = async () => {
        const data = await apiMutate<ProcessInboxResponse>('/api/management/ftp/process', 'POST');
        await mutate();
        return data;
    };

    return {status, isLoading, setTargetGallery, processInbox};
}
