/*
 * Bookkeeping PWA service worker (scope: /steuern/).
 * Only same-origin navigation and static assets are handled; /api is never
 * intercepted or cached. No dependencies, no build step.
 */
const PREFIX = 'rp-steuern-';
const SHELL_CACHE = `${PREFIX}shell-v1`;
const ASSET_CACHE = `${PREFIX}assets-v1`;
const OFFLINE_URL = '/index.html';
const STATIC_DESTINATIONS = new Set(['script', 'style', 'font', 'image']);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, {cache: 'reload'})))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names
                .filter((name) => name.startsWith(PREFIX) && name !== SHELL_CACHE && name !== ASSET_CACHE)
                .map((name) => caches.delete(name))))
            .then(() => self.clients.claim())
    );
});

function isExcluded(url) {
    return url.pathname === '/api' || url.pathname.startsWith('/api/');
}

function networkFirst(event, request) {
    const network = fetch(request).then(async (response) => {
        if (response.ok) {
            const cache = await caches.open(SHELL_CACHE);
            await cache.put(OFFLINE_URL, response.clone());
        }
        return response;
    });
    event.waitUntil(network);
    return network.catch(async () => {
        const cache = await caches.open(SHELL_CACHE);
        return (await cache.match(OFFLINE_URL)) || Response.error();
    });
}

function staleWhileRevalidate(event, request) {
    const cacheReady = caches.open(ASSET_CACHE);
    const revalidate = cacheReady
        .then((cache) => fetch(request).then(async (response) => {
            if (response.ok) await cache.put(request, response.clone());
            return response;
        }))
        .catch(() => undefined);
    event.waitUntil(revalidate);
    return cacheReady
        .then((cache) => cache.match(request))
        .then(async (cached) => cached || (await revalidate) || Response.error());
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin || isExcluded(url)) return;

    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(event, request));
    } else if (STATIC_DESTINATIONS.has(request.destination)) {
        event.respondWith(staleWhileRevalidate(event, request));
    }
});
