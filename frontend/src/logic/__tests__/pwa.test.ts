import {describe, expect, it} from 'vitest';
import {PWA_REGISTRATIONS, serviceWorkerRegistrationFor} from '../pwa';

describe('PWA registrations', () => {
    it('registers both the portal and the bookkeeping service worker', () => {
        expect(PWA_REGISTRATIONS).toHaveLength(2);
        expect(PWA_REGISTRATIONS).toContainEqual({scriptURL: '/sw.js', scope: '/'});
        expect(PWA_REGISTRATIONS).toContainEqual({scriptURL: '/steuern/sw.js', scope: '/steuern/'});
    });

    it.each([
        ['/', '/sw.js'],
        ['/steuern', '/steuern/sw.js'],
        ['/steuern?x=1', '/steuern/sw.js'],
        ['/steuern/buchungen', '/steuern/sw.js'],
        ['/ea', '/steuern/sw.js'],
        ['/ea?x=1', '/steuern/sw.js'],
        ['/galerie/123', '/sw.js'],
        ['/settings', '/sw.js'],
        ['/api/management/ea/entries', '/sw.js'],
    ])('resolves %s to %s', (pathname, scriptURL) => {
        expect(serviceWorkerRegistrationFor(pathname).scriptURL).toBe(scriptURL);
    });

    it('falls back to the portal worker for an unknown path', () => {
        expect(serviceWorkerRegistrationFor('/unbekannt')).toEqual({scriptURL: '/sw.js', scope: '/'});
    });
});
