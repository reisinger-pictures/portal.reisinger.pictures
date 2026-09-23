import {act, render, waitFor} from '@testing-library/react';
import {afterEach, describe, expect, it, vi} from 'vitest';
import {
    TurnstileWidget,
    type TurnstileApi,
    type TurnstileRenderOptions
} from './TurnstileWidget';

afterEach(() => {
    vi.unstubAllGlobals();
    document.getElementById('cloudflare-turnstile-api')?.remove();
});

describe('TurnstileWidget', () => {
    it('loads the explicit renderer once and configures an interactive checkout challenge', async () => {
        let renderOptions: TurnstileRenderOptions | undefined;
        const renderWidget = vi.fn((_container: HTMLElement | string, options: TurnstileRenderOptions) => {
            renderOptions = options;
            return 'widget-1';
        });
        const removeWidget = vi.fn();
        const resetWidget = vi.fn();
        const turnstileApi: TurnstileApi = {
            render: renderWidget,
            remove: removeWidget,
            reset: resetWidget
        };
        const onSuccess = vi.fn();
        const onExpire = vi.fn();
        const onError = vi.fn();

        const firstRender = render(
            <TurnstileWidget
                siteKey="site-key"
                userId="user-42"
                onSuccess={onSuccess}
                onExpire={onExpire}
                onError={onError}
            />
        );

        const script = await waitFor(() => {
            const element = document.getElementById('cloudflare-turnstile-api');
            expect(element).toBeInstanceOf(HTMLScriptElement);
            return element as HTMLScriptElement;
        });
        expect(script.src).toBe('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');

        vi.stubGlobal('turnstile', turnstileApi);
        script.dispatchEvent(new Event('load'));

        await waitFor(() => expect(renderWidget).toHaveBeenCalledTimes(1));
        expect(renderOptions).toEqual(expect.objectContaining({
            sitekey: 'site-key',
            action: 'checkout',
            cData: 'checkout-user-42',
            appearance: 'interaction-only',
            language: 'de'
        }));

        act(() => renderOptions?.callback?.('turnstile-token'));
        act(() => renderOptions?.['expired-callback']?.());
        act(() => renderOptions?.['error-callback']?.());
        expect(onSuccess).toHaveBeenCalledWith('turnstile-token');
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(resetWidget).toHaveBeenCalledWith('widget-1');
        expect(onError).toHaveBeenCalledTimes(1);

        firstRender.unmount();
        expect(removeWidget).toHaveBeenCalledWith('widget-1');

        render(
            <TurnstileWidget
                siteKey="site-key"
                userId="user-42"
                onSuccess={onSuccess}
                onExpire={onExpire}
                onError={onError}
            />
        );
        await waitFor(() => expect(renderWidget).toHaveBeenCalledTimes(2));
        expect(document.querySelectorAll('#cloudflare-turnstile-api')).toHaveLength(1);
    });
});
