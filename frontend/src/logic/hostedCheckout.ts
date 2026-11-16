/**
 * The hosted checkout URL is the only redirect target for a new online
 * payment. The server validates it as well; this check keeps the browser from
 * following a crafted value if a response is ever tampered with.
 */
export const isHostedCheckoutUrl = (value: string): boolean => {
    try {
        const url = new URL(value);
        return url.protocol === 'https:'
            && (url.hostname === 'mollie.com' || url.hostname.endsWith('.mollie.com'));
    } catch {
        return false;
    }
};

/**
 * Top-level navigation to the hosted checkout. It is a separate function so
 * the redirect can be asserted without letting jsdom navigate.
 */
export const assignLocation = (url: string): void => {
    window.location.assign(url);
};
