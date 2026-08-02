import useSWR from 'swr';
import { fetcher, apiMutate } from '../api';

export interface LightroomCatalog {
    id: string;
    name: string;
    position: number;
}

export function useLightroomCatalogs() {
    const { data, isLoading, error, mutate } = useSWR<{ lightroom_catalogs: LightroomCatalog[] }>(
        '/api/management/lightroom-catalogs',
        fetcher,
    );

    const create = async (name: string) => {
        await apiMutate('/api/management/lightroom-catalogs', 'POST', { name });
        await mutate();
    };

    const update = async (id: string, name: string) => {
        await apiMutate(`/api/management/lightroom-catalogs/${id}`, 'PUT', { name });
        await mutate();
    };

    const remove = async (id: string) => {
        await apiMutate(`/api/management/lightroom-catalogs/${id}`, 'DELETE');
        await mutate();
    };

    return { lightroomCatalogs: data?.lightroom_catalogs, isLoading, error, create, update, remove };
}
