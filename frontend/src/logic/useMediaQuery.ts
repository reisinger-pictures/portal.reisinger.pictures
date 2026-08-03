import { useCallback, useSyncExternalStore } from 'react';

function matchMediaSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function';
}

/**
 * Reaktiver Media-Query-Hook via `useSyncExternalStore`: liest den initialen
 * `matches`-Wert, subscribiert auf `change`-Events und räumt den Listener beim
 * Unsubscribe/Unmount auf. Falls `window.matchMedia` nicht verfügbar ist
 * (SSR/jsdom ohne Polyfill), wird `false` zurückgegeben.
 */
export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback((onStoreChange: () => void) => {
        if (!matchMediaSupported()) {
            return () => {};
        }
        const mediaQueryList = window.matchMedia(query);
        mediaQueryList.addEventListener('change', onStoreChange);
        return () => {
            mediaQueryList.removeEventListener('change', onStoreChange);
        };
    }, [query]);

    const getSnapshot = useCallback(() => {
        if (!matchMediaSupported()) {
            return false;
        }
        return window.matchMedia(query).matches;
    }, [query]);

    return useSyncExternalStore(subscribe, getSnapshot);
}

/** True, sobald der Viewport ≥ 768px breit ist (Desktop-Breakpoint, entspricht Tailwind `md`). */
export function useIsDesktop(): boolean {
    return useMediaQuery('(min-width: 768px)');
}
