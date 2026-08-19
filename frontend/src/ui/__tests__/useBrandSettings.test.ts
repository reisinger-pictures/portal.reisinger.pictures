import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useBrandSettings } from '../../logic/useBrandSettings';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate } from '../../api';

const mockResponse = {
    brands: [
        {
            id: 'rp',
            editable_fields: [
                'name', 'portal_name', 'impressum_url', 'primary_color',
                'secondary_color', 'frontend_url', 'from_address', 'from_name',
                'accounting_email', 'features.orgs',
            ],
            defaults: {
                name: 'Reisinger Pictures',
                portal_name: 'Reisinger Foto Portal',
                impressum_url: 'https://reisinger.pictures/impressum/',
                primary_color: '#1E5631',
                secondary_color: '#A4B494',
                frontend_url: 'https://portal.reisinger.pictures',
                from_address: 'portal@reisinger.pictures',
                from_name: 'Reisinger Foto Portal',
                accounting_email: 'accounting@reisinger.pictures',
                features: { orgs: true },
            },
            overrides: {},
            effective: {
                name: 'Reisinger Pictures',
                portal_name: 'Reisinger Foto Portal',
                impressum_url: 'https://reisinger.pictures/impressum/',
                primary_color: '#1E5631',
                secondary_color: '#A4B494',
                frontend_url: 'https://portal.reisinger.pictures',
                from_address: 'portal@reisinger.pictures',
                from_name: 'Reisinger Foto Portal',
                accounting_email: 'accounting@reisinger.pictures',
                features: { orgs: true },
            },
        },
    ],
};

describe('useBrandSettings', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('fetches the brand list from the management endpoint', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockResponse,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useBrandSettings());
        expect(useSWR).toHaveBeenCalledWith('/api/management/brand-settings', expect.any(Function), {
            revalidateOnFocus: false,
        });
        expect(result.current.brands).toHaveLength(1);
        expect(result.current.brands?.[0].id).toBe('rp');
        expect(result.current.isLoading).toBe(false);
        expect(result.current.error).toBeUndefined();
    });

    it('shows the loading state', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useBrandSettings());
        expect(result.current.isLoading).toBe(true);
        expect(result.current.brands).toBeUndefined();
    });

    it('updateBrandSettings PUTs to the brand-specific endpoint and revalidates', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: mockResponse,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const { result } = renderHook(() => useBrandSettings());
        await result.current.updateBrandSettings('rp', { primary_color: '#123456' });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/brand-settings/rp',
            'PUT',
            { primary_color: '#123456' }
        );
        expect(mutate).toHaveBeenCalled();
    });

    it('updateBrandSettings can reset a field with null', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: mockResponse,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const { result } = renderHook(() => useBrandSettings());
        await result.current.updateBrandSettings('rp', { primary_color: null });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/brand-settings/rp',
            'PUT',
            { primary_color: null }
        );
        expect(mutate).toHaveBeenCalled();
    });
});
