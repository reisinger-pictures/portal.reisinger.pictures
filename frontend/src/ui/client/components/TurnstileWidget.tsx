import {useEffect, useRef} from 'react';

const TURNSTILE_SCRIPT_ID = 'cloudflare-turnstile-api';
const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

export interface TurnstileRenderOptions {
    sitekey: string;
    action?: string;
    cData?: string;
    appearance?: string;
    language?: string;
    callback?: (token: string) => void;
    'expired-callback'?: () => void;
    'error-callback'?: () => void;
}

export interface TurnstileApi {
    render: (container: HTMLElement | string, options: TurnstileRenderOptions) => string;
    remove: (widgetId: string) => void;
    reset: (widgetId: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

let turnstileApiPromise: Promise<TurnstileApi> | null = null;

const waitForTurnstileScript = (script: HTMLScriptElement): Promise<TurnstileApi> => new Promise((resolve, reject) => {
    const cleanUp = () => {
        script.removeEventListener('load', handleLoad);
        script.removeEventListener('error', handleError);
    };
    const handleLoad = () => {
        cleanUp();
        if (window.turnstile) {
            resolve(window.turnstile);
            return;
        }
        script.remove();
        reject(new Error('Cloudflare Turnstile wurde ohne API geladen.'));
    };
    const handleError = () => {
        cleanUp();
        script.remove();
        reject(new Error('Cloudflare Turnstile konnte nicht geladen werden.'));
    };

    script.addEventListener('load', handleLoad, {once: true});
    script.addEventListener('error', handleError, {once: true});
});

const loadTurnstileApi = (): Promise<TurnstileApi> => {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (turnstileApiPromise) return turnstileApiPromise;

    const existingScript = document.getElementById(TURNSTILE_SCRIPT_ID);
    const script = existingScript instanceof HTMLScriptElement ? existingScript : document.createElement('script');
    const pendingPromise = waitForTurnstileScript(script);

    if (!existingScript) {
        script.id = TURNSTILE_SCRIPT_ID;
        script.src = TURNSTILE_SCRIPT_URL;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    turnstileApiPromise = pendingPromise.catch((error: unknown) => {
        turnstileApiPromise = null;
        throw error;
    });
    return turnstileApiPromise;
};

export interface TurnstileWidgetProps {
    siteKey: string;
    userId: string;
    onSuccess: (token: string) => void;
    onExpire: () => void;
    onError: () => void;
}

export function TurnstileWidget({siteKey, userId, onSuccess, onExpire, onError}: TurnstileWidgetProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const successCallbackRef = useRef(onSuccess);
    const expireCallbackRef = useRef(onExpire);
    const errorCallbackRef = useRef(onError);

    useEffect(() => {
        successCallbackRef.current = onSuccess;
        expireCallbackRef.current = onExpire;
        errorCallbackRef.current = onError;
    }, [onSuccess, onExpire, onError]);

    useEffect(() => {
        if (!siteKey.trim()) return;

        let active = true;
        let widgetId: string | null = null;

        void loadTurnstileApi().then((turnstile) => {
            const container = containerRef.current;
            if (!active || !container) return;

            widgetId = turnstile.render(container, {
                sitekey: siteKey,
                action: 'checkout',
                cData: `checkout-${userId.slice(0, 23)}`,
                appearance: 'interaction-only',
                language: 'de',
                callback: (token) => {
                    if (active) successCallbackRef.current(token);
                },
                'expired-callback': () => {
                    if (!active) return;
                    expireCallbackRef.current();
                    if (widgetId) {
                        try {
                            turnstile.reset(widgetId);
                        } catch {
                            // The parent still clears the expired token and can
                            // recreate the widget after a failed checkout.
                        }
                    }
                },
                'error-callback': () => {
                    if (active) errorCallbackRef.current();
                }
            });
        }).catch(() => {
            if (active) errorCallbackRef.current();
        });

        return () => {
            active = false;
            if (!widgetId || !window.turnstile) return;
            try {
                window.turnstile.remove(widgetId);
            } catch {
                // The external API may already have removed a failed widget.
            }
        };
    }, [siteKey, userId]);

    return <div ref={containerRef} data-testid="turnstile-widget" aria-label="Cloudflare Turnstile"/>;
}
