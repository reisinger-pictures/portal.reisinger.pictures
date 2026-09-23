import {describe, expect, it, vi} from 'vitest';
import type {ReactNode} from 'react';

const stripeModuleLoad = vi.hoisted(() => vi.fn());

vi.mock('./logic/stripe', () => {
    stripeModuleLoad();
    return {stripePromise: Promise.resolve(null)};
});

vi.mock('./logic/I18nProvider', () => ({}));
vi.mock('./index.css', () => ({}));
vi.mock('./logic/useBrand', () => ({applyTheme: vi.fn()}));
vi.mock('./App', () => ({default: () => null}));
vi.mock('react-dom/client', () => ({
    createRoot: () => ({render: vi.fn()})
}));
vi.mock('react-router-dom', () => ({
    BrowserRouter: ({children}: {children: ReactNode}) => <>{children}</>
}));

describe('application entrypoint', () => {
    it('does not load Stripe on every page', async () => {
        await import('./main');

        expect(stripeModuleLoad).not.toHaveBeenCalled();
    });
});
