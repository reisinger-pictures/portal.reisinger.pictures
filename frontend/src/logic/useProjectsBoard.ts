import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface BoardUser { id: string; name: string; }

export interface Project {
    id: string;
    status: string;
    position: number;
    owner: BoardUser;
    assignee: BoardUser | null;
    created_at: string;
    client_name: string;
    email: string;
    phone: string | null;
    package: string | null;
    price_cents: number;
    payment_status: string;
    linked_photo_job_id: string | null;
}

export interface ProjectInput {
    client_name: string;
    email?: string;
    phone?: string | null;
    package?: string | null;
    price_cents?: number;
    payment_status?: string;
    assignee_id?: string;
}

export function useProjectsBoard() {
    const { data, isLoading, error, mutate } = useSWR<{ projects: Project[] }>('/api/management/projects', fetcher);

    const create = async (input: ProjectInput) => {
        const res = await apiMutate<{ project: Project }>('/api/management/projects', 'POST', input);
        await mutate();
        return res.project.id;
    };

    const update = async (id: string, input: ProjectInput) => {
        await apiMutate(`/api/management/projects/${id}`, 'PUT', input);
        await mutate();
    };

    const move = async (id: string, status: string, position: number) => {
        await apiMutate(`/api/management/projects/${id}/move`, 'PATCH', { status, position });
        await mutate();
    };

    const remove = async (id: string) => {
        await apiMutate(`/api/management/projects/${id}`, 'DELETE');
        await mutate();
    };

    const handoff = async (id: string) => {
        await apiMutate(`/api/management/projects/${id}/handoff`, 'POST', {});
        await mutate();
    };

    return { projects: data?.projects, isLoading, error, create, update, move, remove, handoff };
}