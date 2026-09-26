import type { APIResponse } from '@playwright/test';

export interface CookieHeaderSource {
    headersArray(): Array<{
        name: string;
        value: string;
    }>;
}

interface ParsedCookie {
    name: string;
    value: string;
    expired: boolean;
}

const cookieNamePattern = /^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$/;

function hasUnsafeCookieValue(value: string): boolean {
    for (const character of value) {
        const codePoint = character.codePointAt(0) ?? 0;
        if (codePoint <= 0x1F || codePoint === 0x7F || character === ';' || character === ',') {
            return true;
        }
    }
    return false;
}

function parseSetCookie(value: string): ParsedCookie | null {
    const cookiePair = value.split(';', 1)[0]?.trim() ?? '';
    const separator = cookiePair.indexOf('=');
    if (separator <= 0) return null;

    const name = cookiePair.slice(0, separator).trim();
    const cookieValue = cookiePair.slice(separator + 1).trim();
    if (!cookieNamePattern.test(name) || hasUnsafeCookieValue(cookieValue)) return null;

    const attributes = value.split(';').slice(1);
    const maxAgeAttribute = attributes.find((attribute) => attribute.trim().toLowerCase().startsWith('max-age='));
    const maxAge = maxAgeAttribute?.split('=', 2)[1]?.trim();
    const expiresAttribute = attributes.find((attribute) => attribute.trim().toLowerCase().startsWith('expires='));
    const expires = expiresAttribute?.split('=', 2)[1]?.trim();
    const expiredByMaxAge = maxAge !== undefined && Number(maxAge) <= 0;
    const expiresAt = expires ? Date.parse(expires) : Number.NaN;
    const expiredByDate = !Number.isNaN(expiresAt) && expiresAt <= Date.now();

    return {
        name,
        value: cookieValue,
        expired: cookieValue === '' || expiredByMaxAge || expiredByDate,
    };
}

/**
 * Small in-memory cookie store for Playwright API responses.
 *
 * The folded response-header representation combines repeated Set-Cookie fields
 * into a string. That representation is not a valid Cookie request header
 * because it still contains attributes (and can contain commas/newlines from
 * Expires). The headersArray API preserves each response field, so only its
 * name=value pair is copied into the request header.
 */
export class E2ECookieJar {
    private readonly cookies = new Map<string, string>();

    update(response: CookieHeaderSource | APIResponse): this {
        for (const header of response.headersArray()) {
            if (header.name.toLowerCase() !== 'set-cookie') continue;

            const parsed = parseSetCookie(header.value);
            if (!parsed) continue;
            if (parsed.expired) {
                this.cookies.delete(parsed.name);
            } else {
                this.cookies.set(parsed.name, parsed.value);
            }
        }

        return this;
    }

    get(name: string): string | null {
        const value = this.cookies.get(name);
        return value === undefined ? null : `${name}=${value}`;
    }

    toCookieHeader(): string {
        return [...this.cookies.entries()]
            .map(([name, value]) => `${name}=${value}`)
            .join('; ');
    }
}

export function extractCookieHeader(response: CookieHeaderSource | APIResponse): string {
    return new E2ECookieJar().update(response).toCookieHeader();
}
