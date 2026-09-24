import { describe, expect, it } from 'vitest';
import {
    E2ECookieJar,
    extractCookieHeader,
    type CookieHeaderSource,
} from './E2ECookieJar';

function responseWithHeaders(headers: Array<{ name: string; value: string }>): CookieHeaderSource {
    return { headersArray: () => headers };
}

describe('E2ECookieJar', () => {
    it('extracts every Set-Cookie field without carrying response attributes into Cookie', () => {
        const header = extractCookieHeader(responseWithHeaders([
            {
                name: 'Set-Cookie',
                value: 'rp_jwt=access-token; Path=/; HttpOnly; SameSite=Lax',
            },
            {
                name: 'set-cookie',
                value: 'rp_jwt_refresh=refresh-token; Expires=Wed, 21 Oct 2037 07:28:00 GMT; Path=/; HttpOnly',
            },
            { name: 'X-Request-Id', value: 'request-1' },
        ]));

        expect(header).toBe('rp_jwt=access-token; rp_jwt_refresh=refresh-token');
        expect(header).not.toContain('Path=');
        expect(header).not.toContain('Expires=');
        expect(header).not.toContain(',');
    });

    it('replaces rotated cookies and removes cookies explicitly expired by the server', () => {
        const jar = new E2ECookieJar();
        jar.update(responseWithHeaders([
            { name: 'Set-Cookie', value: 'rp_jwt=old-access; Path=/; HttpOnly' },
            { name: 'Set-Cookie', value: 'rp_jwt_refresh=old-refresh; Path=/; HttpOnly' },
        ]));
        jar.update(responseWithHeaders([
            { name: 'Set-Cookie', value: 'rp_jwt=new-access; Path=/; HttpOnly' },
            { name: 'Set-Cookie', value: 'rp_jwt_refresh=new-refresh; Path=/; HttpOnly' },
        ]));

        expect(jar.toCookieHeader()).toBe('rp_jwt=new-access; rp_jwt_refresh=new-refresh');
        expect(jar.get('rp_jwt')).toBe('rp_jwt=new-access');

        jar.update(responseWithHeaders([
            { name: 'Set-Cookie', value: 'rp_jwt=; Max-Age=0; Path=/' },
        ]));

        expect(jar.toCookieHeader()).toBe('rp_jwt_refresh=new-refresh');
    });

    it('rejects control characters and separators in cookie values', () => {
        expect(extractCookieHeader(responseWithHeaders([
            { name: 'Set-Cookie', value: 'rp_jwt=bad\nvalue; Path=/' },
        ]))).toBe('');
        expect(extractCookieHeader(responseWithHeaders([
            { name: 'Set-Cookie', value: 'rp_jwt=bad,value; Path=/' },
        ]))).toBe('');
    });
});
