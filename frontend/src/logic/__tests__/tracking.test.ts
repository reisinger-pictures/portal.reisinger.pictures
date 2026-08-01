import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { trackEvent, TRACKING_EVENTS } from '../tracking';

describe('trackEvent', () => {
    const originalTrackEvent = window.trackEvent;

    beforeEach(() => {
        delete window.trackEvent;
    });

    afterEach(() => {
        if (originalTrackEvent === undefined) {
            delete window.trackEvent;
        } else {
            window.trackEvent = originalTrackEvent;
        }
        vi.restoreAllMocks();
    });

    it('is a no-op when the tracker script is not loaded', () => {
        const spy = vi.fn();
        window.trackEvent = spy;

        delete window.trackEvent;
        trackEvent(TRACKING_EVENTS.add_to_cart, { photo_id: 'p1' });
        expect(spy).not.toHaveBeenCalled();
    });

    it('forwards event name and payload to window.trackEvent', () => {
        const spy = vi.fn();
        window.trackEvent = spy;

        trackEvent(TRACKING_EVENTS.photo_view, { photo_id: 'p1', gallery_id: 'g1' });
        expect(spy).toHaveBeenCalledWith('photo_view', { photo_id: 'p1', gallery_id: 'g1' });
    });

    it('forwards event without payload when omitted', () => {
        const spy = vi.fn();
        window.trackEvent = spy;

        trackEvent(TRACKING_EVENTS.photo_swipe_open);
        expect(spy).toHaveBeenCalledWith('photo_swipe_open', undefined);
    });

    it('passes payload through unmodified', () => {
        const spy = vi.fn();
        window.trackEvent = spy;
        const payload = { item_count: 3, total_cents: 2500, has_quotes: false, is_quote: true };

        trackEvent(TRACKING_EVENTS.checkout_started, payload);
        expect(spy).toHaveBeenCalledWith('checkout_started', payload);
    });

    it('exposes all expected tracking event names', () => {
        expect(TRACKING_EVENTS).toMatchObject({
            pageview: 'pageview',
            photo_view: 'photo_view',
            photo_download: 'photo_download',
            photo_swipe_open: 'photo_swipe_open',
            add_to_cart: 'add_to_cart',
            remove_from_cart: 'remove_from_cart',
            checkout_started: 'checkout_started',
            checkout_succeeded: 'checkout_succeeded',
            checkout_failed: 'checkout_failed',
        });
    });
});
