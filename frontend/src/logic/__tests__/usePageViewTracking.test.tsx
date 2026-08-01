import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, fireEvent } from '@testing-library/react';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { usePageViewTracking } from '../usePageViewTracking';
import { TRACKING_EVENTS } from '../tracking';

describe('usePageViewTracking', () => {
    const originalTrackEvent = window.trackEvent;

    beforeEach(() => {
        window.trackEvent = vi.fn();
    });

    afterEach(() => {
        if (originalTrackEvent === undefined) {
            delete window.trackEvent;
        } else {
            window.trackEvent = originalTrackEvent;
        }
        vi.restoreAllMocks();
    });

    function PageViewProbe() {
        usePageViewTracking();
        const navigate = useNavigate();
        return <button onClick={() => navigate('/photos/abc')}>go</button>;
    }

    function renderInRouter(initialPath: string) {
        return render(
            <MemoryRouter initialEntries={[initialPath]}>
                <PageViewProbe />
            </MemoryRouter>,
        );
    }

    it('fires a pageview event with the current path on mount', () => {
        renderInRouter('/galleries');
        expect(window.trackEvent).toHaveBeenCalledWith(TRACKING_EVENTS.pageview, { url: '/galleries' });
    });

    it('re-fires a pageview event when the route changes', () => {
        const view = renderInRouter('/galleries');
        expect(window.trackEvent).toHaveBeenLastCalledWith(TRACKING_EVENTS.pageview, { url: '/galleries' });

        act(() => {
            fireEvent.click(view.getByRole('button', { name: 'go' }));
        });

        expect(window.trackEvent).toHaveBeenLastCalledWith(TRACKING_EVENTS.pageview, { url: '/photos/abc' });
    });

    it('includes search params in the tracked url', () => {
        renderInRouter('/galleries/slug?view=client&page=2');
        expect(window.trackEvent).toHaveBeenCalledWith(TRACKING_EVENTS.pageview, {
            url: '/galleries/slug?view=client&page=2',
        });
    });

    it('is a no-op when the tracker script is not loaded', () => {
        delete window.trackEvent;
        renderInRouter('/galleries');
        expect(window.trackEvent).toBeUndefined();
    });
});
