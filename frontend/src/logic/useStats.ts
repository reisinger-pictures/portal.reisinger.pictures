import useSWR from 'swr';
import {fetcher} from '../api';

export interface StatsData {
    galleries_count: number;
    total_downloads: number;
    guest_downloads: number;
    domain_stats: { domain: string; count: number }[];
    top_galleries: { name: string; count: number }[];
}

export interface LogEntry {
    id: string;
    user_name_snapshot: string | null;
    gallery_name_snapshot: string | null;
    item_type: 'single_image' | 'full_zip';
    item_identifier: string;
    created_at: string;
    thumb_url?: string;
    resolution_tier?: string;
    payload?: { photo_count?: number };
}

export interface PaginatedLogs {
    data: LogEntry[];
    current_page: number;
    last_page: number;
}

export function useStats(page = 1, tier: string | null = null) {
    const queryParams = new URLSearchParams();
    if (tier) queryParams.set('tier', tier);
    const qsStats = queryParams.toString() ? '?' + queryParams.toString() : '';
    
    queryParams.set('page', page.toString());
    const qsLogs = '?' + queryParams.toString();

    const {data: stats, isLoading: statsLoading} = useSWR<StatsData>('/api/management/stats' + qsStats, fetcher);
    const {data: logs, isLoading: logsLoading} = useSWR<PaginatedLogs>('/api/management/logs' + qsLogs, fetcher);

    return {stats, logs, isLoading: statsLoading || logsLoading};
}
