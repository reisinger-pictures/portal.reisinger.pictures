import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { createBrandSettingsSchema, useBrandSettings } from '../useBrandSettings';

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
                'accounting_email', 'features.orgs', 'pricing_strategy',
            ],
            defaults: {
                name: 'Reisinger Pictures',
                portal_name: 'Reisinger Foto Portal',
                impressum_url: 'https://example.invalid/impressum/',
                primary_color: '#1E5631',
                secondary_color: '#A4B494',
                frontend_url: 'https://portal.example.invalid',
                from_address: 'portal@example.invalid',
                from_name: 'Reisinger Foto Portal',
                accounting_email: 'accounting@example.invalid',
                features: { orgs: true },
                pricing_strategy: null,
            },
            overrides: {},
            effective: {
                name: 'Reisinger Pictures',
                portal_name: 'Reisinger Foto Portal',
                impressum_url: 'https://example.invalid/impressum/',
                primary_color: '#1E5631',
                secondary_color: '#A4B494',
                frontend_url: 'https://portal.example.invalid',
                from_address: 'portal@example.invalid',
                from_name: 'Reisinger Foto Portal',
                accounting_email: 'accounting@example.invalid',
                features: { orgs: true },
                pricing_strategy: 'scope_licensing',
            },
            pricing_strategy_source: 'default',
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
        // Proves the hook revalidates after a successful write. A bare
        // toHaveBeenCalled() would also pass on a second spurious revalidation.
        expect(mutate).toHaveBeenCalledTimes(1);
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
        // Proves the hook revalidates after a successful write. A bare
        // toHaveBeenCalled() would also pass on a second spurious revalidation.
        expect(mutate).toHaveBeenCalledTimes(1);
    });

    it('updateBrandSettings carries the pricing strategy overlay', async () => {
        const mutate = vi.fn();
        vi.mocked(useSWR).mockReturnValue({
            data: mockResponse,
            error: undefined,
            isLoading: false,
            mutate,
        } as never);
        vi.mocked(apiMutate).mockResolvedValue({} as never);

        const { result } = renderHook(() => useBrandSettings());
        await result.current.updateBrandSettings('rp', { pricing_strategy: 'volume_licensing' });

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/management/brand-settings/rp',
            'PUT',
            { pricing_strategy: 'volume_licensing' }
        );

        // The reset of the overlay is a null — it deletes the override row.
        await result.current.updateBrandSettings('rp', { pricing_strategy: null });

        expect(apiMutate).toHaveBeenLastCalledWith(
            '/api/management/brand-settings/rp',
            'PUT',
            { pricing_strategy: null }
        );
    });

    it('exposes the resolved pricing strategy and its source per brand', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: mockResponse,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useBrandSettings());
        const rp = result.current.brands?.[0];

        expect(rp?.effective.pricing_strategy).toBe('scope_licensing');
        expect(rp?.defaults.pricing_strategy).toBeNull();
        expect(rp?.pricing_strategy_source).toBe('default');
    });

    it('the schema accepts the empty selection and both pricing modes', () => {
        const schema = createBrandSettingsSchema();
        const base = {
            name: 'Reisinger Pictures',
            portal_name: 'Reisinger Foto Portal',
            from_name: 'Reisinger Foto Portal',
            from_address: 'portal@example.invalid',
            accounting_email: 'accounting@example.invalid',
            impressum_url: 'https://example.invalid/impressum/',
            frontend_url: 'https://portal.example.invalid',
            primary_color: '#1E5631',
            secondary_color: '#A4B494',
            features: { orgs: true },
        };

        expect(schema.safeParse({ ...base, pricing_strategy: '' }).success).toBe(true);
        expect(schema.safeParse({ ...base, pricing_strategy: 'scope_licensing' }).success).toBe(true);
        expect(schema.safeParse({ ...base, pricing_strategy: 'volume_licensing' }).success).toBe(true);
        expect(schema.safeParse({ ...base, pricing_strategy: 'bogus' }).success).toBe(false);
    });
});
