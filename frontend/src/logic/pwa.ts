export interface PwaRegistration {
    readonly scriptURL: string;
    readonly scope: string;
}

/** Both workers are registered on every page so SPA navigation keeps the matching controller. */
export const PWA_REGISTRATIONS: readonly PwaRegistration[] = [
    {scriptURL: '/sw.js', scope: '/'},
    {scriptURL: '/steuern/sw.js', scope: '/steuern/'},
];

function normalizedPath(pathname: string): string {
    const withoutQuery = pathname.split('?')[0];
    const path = withoutQuery.split('#')[0];
    // /eur is a legacy alias of /steuern and must resolve to the bookkeeping worker.
    if (path === '/eur') return '/steuern';
    if (path.startsWith('/eur/')) return `/steuern${path.slice('/eur'.length)}`;
    return path;
}

function matchesScope(path: string, scope: string): boolean {
    const exact = scope.endsWith('/') ? scope.slice(0, -1) : scope;
    return path === exact || path.startsWith(scope);
}

/** Returns the registration with the longest matching scope; the portal worker is the fallback. */
export function serviceWorkerRegistrationFor(pathname: string): PwaRegistration {
    const path = normalizedPath(pathname);
    let best = PWA_REGISTRATIONS[0];
    for (const registration of PWA_REGISTRATIONS) {
        if (matchesScope(path, registration.scope) && registration.scope.length > best.scope.length) {
            best = registration;
        }
    }
    return best;
}

/** Best-effort registration: unsupported browsers and development never break app startup. */
export async function registerPwas(): Promise<void> {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;
    for (const {scriptURL, scope} of PWA_REGISTRATIONS) {
        try {
            await navigator.serviceWorker.register(scriptURL, {scope});
        } catch {
            // A failed registration must never block the app.
        }
    }
}
