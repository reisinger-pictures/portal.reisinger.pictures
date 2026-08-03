import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useMediaQuery, useIsDesktop } from '../useMediaQuery';

/**
 * Kontrollierbarer matchMedia-Mock: liefert einen initialen `matches`-Wert,
 * protokolliert die gequeryte Media-Query und erlaubt per `emit()` das Auslösen
 * von `change`-Events (die Hook-Handler lesen nur `event.matches`).
 */
function installMatchMedia(initialMatches: boolean) {
    const listeners: Array<(event: { matches: boolean }) => void> = [];
    const state = { matches: initialMatches };
    const queriedQueries: string[] = [];

    const mql = {
        media: '(min-width: 768px)',
        get matches(): boolean {
            return state.matches;
        },
        addEventListener: vi.fn((_type: string, listener: unknown) => {
            listeners.push(listener as (event: { matches: boolean }) => void);
        }),
        removeEventListener: vi.fn((_type: string, listener: unknown) => {
            const idx = listeners.indexOf(listener as (event: { matches: boolean }) => void);
            if (idx >= 0) listeners.splice(idx, 1);
        }),
    };

    window.matchMedia = (query: string) => {
        queriedQueries.push(query);
        return mql as unknown as MediaQueryList;
    };

    return {
        queriedQueries,
        listenerCount: () => listeners.length,
        emit(matches: boolean) {
            state.matches = matches;
            listeners.forEach(listener => listener({ matches }));
        },
    };
}

const originalMatchMedia = window.matchMedia;

describe('useMediaQuery', () => {
    afterEach(() => {
        window.matchMedia = originalMatchMedia;
        vi.restoreAllMocks();
    });

    it('returns the initial matches value from matchMedia', () => {
        installMatchMedia(true);
        const { result } = renderHook(() => useMediaQuery('(min-width: 768px)'));
        expect(result.current).toBe(true);
    });

    it('updates when the media query change event fires', () => {
        const mm = installMatchMedia(false);
        const { result } = renderHook(() => useMediaQuery('(min-width: 768px)'));
        expect(result.current).toBe(false);

        act(() => {
            mm.emit(true);
        });

        expect(result.current).toBe(true);

        act(() => {
            mm.emit(false);
        });

        expect(result.current).toBe(false);
    });

    it('removes the change listener on unmount', () => {
        const mm = installMatchMedia(false);
        const { unmount } = renderHook(() => useMediaQuery('(min-width: 768px)'));
        expect(mm.listenerCount()).toBe(1);

        unmount();

        expect(mm.listenerCount()).toBe(0);
    });

    it('returns false when matchMedia is unavailable', () => {
        window.matchMedia = undefined as unknown as typeof window.matchMedia;
        const { result } = renderHook(() => useMediaQuery('(min-width: 768px)'));
        expect(result.current).toBe(false);
    });

    it('queries the desktop breakpoint for useIsDesktop', () => {
        const mm = installMatchMedia(false);
        const { result } = renderHook(() => useIsDesktop());
        expect(mm.queriedQueries).toContain('(min-width: 768px)');
        expect(result.current).toBe(false);
    });
});
