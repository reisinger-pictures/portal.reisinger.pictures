import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useLicensingMode } from '../useLicensingMode';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../useLicenseTerms', () => ({
    useLicenseTerms: vi.fn(),
}));

import { useLicenseTerms } from '../useLicenseTerms';

describe('useLicensingMode', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('returns scope_licensing when no galleryId and pricing_strategy is scope_licensing', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: { pricing_strategy: 'scope_licensing' },
            isLoading: false,
            updateTerms: vi.fn(),
        });
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            isValidating: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLicensingMode());
        expect(result.current).toBe('scope_licensing');
    });

    it('returns volume_licensing from pricing_strategy when no galleryId', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: { pricing_strategy: 'volume_licensing' },
            isLoading: false,
            updateTerms: vi.fn(),
        });
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            isValidating: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLicensingMode());
        expect(result.current).toBe('volume_licensing');
    });

    it('falls back to scope_licensing when terms not loaded', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: undefined,
            isLoading: true,
            updateTerms: vi.fn(),
        });
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            isValidating: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLicensingMode());
        expect(result.current).toBe('scope_licensing');
    });

    it('uses gallery-specific endpoint result over global terms when galleryId is provided', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: { pricing_strategy: 'scope_licensing' },
            isLoading: false,
            updateTerms: vi.fn(),
        });
        vi.mocked(useSWR).mockReturnValue({
            data: { pricing_strategy: 'volume_licensing' },
            error: undefined,
            isLoading: false,
            isValidating: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLicensingMode('gallery-123'));
        expect(result.current).toBe('volume_licensing');
    });

    it('falls back to global terms when gallery terms not loaded', () => {
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: { pricing_strategy: 'scope_licensing' },
            isLoading: false,
            updateTerms: vi.fn(),
        });
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            isValidating: false,
            mutate: vi.fn(),
        } as never);

        const { result } = renderHook(() => useLicensingMode('gallery-456'));
        expect(result.current).toBe('scope_licensing');
    });
});
