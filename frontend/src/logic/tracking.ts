/**
 * Tracking-Helper für die eigene DSGVO-konforme Analytics-Software
 * (https://stats.reisinger.pictures/x7k2p.js, geladen in index.html).
 *
 * `window.trackEvent(name, payload?)` ist global verfügbar, sobald das Skript
 * geladen ist. Dieser Wrapper kapselt den Aufruf typsicher und guardet auf
 * das Vorhandensein des Trackers (Tests, kein Netzwerk etc.).
 */

declare global {
    interface Window {
        trackEvent?: (name: string, payload?: Record<string, string | number | boolean | null | undefined>) => void;
    }
}

export interface TrackingPayload {
    [key: string]: string | number | boolean | null | undefined;
}

export const TRACKING_EVENTS = {
    pageview: 'pageview',
    photo_view: 'photo_view',
    photo_download: 'photo_download',
    photo_swipe_open: 'photo_swipe_open',
    photo_rated: 'photo_rated',
    add_to_cart: 'add_to_cart',
    remove_from_cart: 'remove_from_cart',
    checkout_started: 'checkout_started',
    checkout_succeeded: 'checkout_succeeded',
    checkout_failed: 'checkout_failed',
} as const;

export type TrackingEventName = (typeof TRACKING_EVENTS)[keyof typeof TRACKING_EVENTS];

/**
 * Sendet ein Custom-Event an den Tracker. No-Op, wenn das Tracking-Skript
 * nicht geladen ist (z. B. in Tests oder bei fehlgeschlagenem Skript-Load).
 */
export function trackEvent(name: TrackingEventName, payload?: TrackingPayload): void {
    if (typeof window === 'undefined' || typeof window.trackEvent !== 'function') return;
    window.trackEvent(name, payload);
}
