import useSWR from 'swr';
import {fetcher} from '../api';

export interface StatsData {
    galleries_count: number;
    total_downloads: number;
    guest_downloads: number;
    domain_stats: { domain: string; count: number }[];
    top_galleries: { name: string; count: number }[];
    top_photos: { name: string; count: number }[];
}

export interface LogEntry {
    id: string;
    user_name_snapshot: string | null;
    gallery_name_snapshot: string | null;
    item_type: 'single_image' | 'full_zip';
    item_identifier: string;
    created_at: string;
}

export interface PaginatedLogs {
    data: LogEntry[];
    current_page: number;
    last_page: number;
}

export function useStats(page = 1) {
    const {data: stats, isLoading: statsLoading} = useSWR<StatsData>('/api/management/stats', fetcher);
    const {data: logs, isLoading: logsLoading} = useSWR<PaginatedLogs>(`/api/management/logs?page=${page}`, fetcher);

    return {stats, logs, isLoading: statsLoading || logsLoading};
}
