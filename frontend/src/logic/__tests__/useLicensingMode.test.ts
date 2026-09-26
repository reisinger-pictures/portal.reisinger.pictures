import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useLicensingMode, useLicensingModeStatus } from '../useLicensingMode';
import useSWR from 'swr';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../useLicenseTerms', () => ({
    useLicenseTerms: vi.fn(),
    // The hook reads this budget to bound how long a hung request may hold the
    // UI in its unresolved state, so the mock has to expose it too.
    LICENSE_TERMS_LOADING_TIMEOUT_MS: 3000,
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

// The 2026-09-26 test audit found this export untested: 0 references in the
// suite. Two component test files nominally covered it — PhotoDetailView.test.tsx
// and ManagementGalleryView.licensing.test.tsx — but both mocked 16 and 22
// modules respectively and asserted only that their own stubs rendered, so
// neither could fail. The coverage now lives here, at the level the behaviour
// actually belongs to, and those two files were removed as the duplication they
// had become.

describe('useLicensingModeStatus', () => {
    const swr = (data: unknown, isLoading: boolean) => vi.mocked(useSWR).mockReturnValue({
        data,
        error: undefined,
        isLoading,
        isValidating: false,
        mutate: vi.fn(),
    } as never);

    const brandTerms = (pricing_strategy: string, isLoading = false) => vi.mocked(useLicenseTerms).mockReturnValue({
        terms: { pricing_strategy },
        isLoading,
        updateTerms: vi.fn(),
    });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('reports the brand strategy when no galleryId is given', () => {
        brandTerms('volume_licensing');
        swr(undefined, false);

        expect(renderHook(() => useLicensingModeStatus()).result.current).toEqual({
            mode: 'volume_licensing',
            isLoading: false,
        });
    });

    it('defaults to scope_licensing for an unknown strategy', () => {
        // Fail safe: an unrecognised value must not silently enable volume
        // pricing, which would change what a customer is charged.
        brandTerms('something_unexpected');
        swr(undefined, false);

        expect(renderHook(() => useLicensingModeStatus()).result.current.mode).toBe('scope_licensing');
    });

    it('prefers the gallery override over the brand default', () => {
        brandTerms('scope_licensing');
        swr({ pricing_strategy: 'volume_licensing' }, false);

        expect(renderHook(() => useLicensingModeStatus('g1')).result.current.mode).toBe('volume_licensing');
    });

    it('keeps the brand default while the gallery terms are still loading', () => {
        // The contract callers rely on: mode may be the brand default here, so
        // isLoading is the signal that must gate the render instead.
        brandTerms('scope_licensing');
        swr(undefined, true);

        expect(renderHook(() => useLicensingModeStatus('g1')).result.current).toEqual({
            mode: 'scope_licensing',
            isLoading: true,
        });
    });

    it('stops loading once the gallery terms arrive', () => {
        brandTerms('scope_licensing');
        swr({ pricing_strategy: 'volume_licensing' }, false);

        expect(renderHook(() => useLicensingModeStatus('g1')).result.current.isLoading).toBe(false);
    });

    it('stays loading while the brand terms load for a gallery-less caller', () => {
        brandTerms('scope_licensing', true);
        swr(undefined, false);

        expect(renderHook(() => useLicensingModeStatus()).result.current.isLoading).toBe(true);
    });

    it('does not request gallery terms when no galleryId is given', () => {
        // A null key means SWR never fetches; passing a key here would issue a
        // pointless request on every gallery-less view.
        brandTerms('scope_licensing');
        swr(undefined, false);

        renderHook(() => useLicensingModeStatus());

        expect(vi.mocked(useSWR).mock.calls[0][0]).toBeNull();
    });

    it('requests the gallery-scoped terms endpoint for a gallery', () => {
        brandTerms('scope_licensing');
        swr(undefined, false);

        renderHook(() => useLicensingModeStatus('g1'));

        expect(vi.mocked(useSWR).mock.calls[0][0]).toBe('/api/settings/license-terms?gallery_id=g1');
    });
});
