import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface BoardUser { id: string; name: string; }

export interface PhotoJob {
    id: string;
    status: string;
    position: number;
    owner: BoardUser;
    assignee: BoardUser | null;
    created_at: string;
    title: string;
    lightroom_catalog: string | null;
    total_count: number;
    selected_count: number;
    target_gallery_id: string | null;
    is_private: boolean;
}

export interface PhotoJobInput {
    title: string;
    lightroom_catalog?: string | null;
    total_count?: number;
    selected_count?: number;
    target_gallery_id?: string | null;
    is_private?: boolean;
    assignee_id?: string;
}

export function useProductionBoard() {
    const { data, isLoading, error, mutate } = useSWR<{ photo_jobs: PhotoJob[] }>('/api/management/photo-jobs', fetcher);

    const create = async (input: PhotoJobInput) => {
        await apiMutate('/api/management/photo-jobs', 'POST', input);
        await mutate();
    };

    const update = async (id: string, input: PhotoJobInput) => {
        await apiMutate(`/api/management/photo-jobs/${id}`, 'PUT', input);
        await mutate();
    };

    const move = async (id: string, status: string, position: number) => {
        await apiMutate(`/api/management/photo-jobs/${id}/move`, 'PATCH', { status, position });
        await mutate();
    };

    const remove = async (id: string) => {
        await apiMutate(`/api/management/photo-jobs/${id}`, 'DELETE');
        await mutate();
    };

    return { photoJobs: data?.photo_jobs, isLoading, error, create, update, move, remove };
}