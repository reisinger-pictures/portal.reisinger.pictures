import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { trackEvent, TRACKING_EVENTS } from './tracking';

/**
 * Sendet bei jeder Route-Änderung einen virtuellen Pageview an den Tracker.
 * Nutzung: einmal im App-Root innerhalb des Router einhängen.
 */
export function usePageViewTracking(): void {
    const location = useLocation();

    useEffect(() => {
        trackEvent(TRACKING_EVENTS.pageview, { url: location.pathname + location.search });
    }, [location.pathname, location.search]);
}
