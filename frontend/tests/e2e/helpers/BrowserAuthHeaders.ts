/**
 * The headers a browser adds to an unsafe same-origin API request, for harness
 * calls that reuse a browser session instead of going through the SPA.
 *
 * `backend/app/Http/Middleware/ProtectApiMutations.php` requires a matching
 * `Origin` and the signed double-submit token for every mutation that carries an
 * auth cookie. Playwright's `APIRequestContext` sends neither, so every harness
 * mutation with a `Cookie` header has to add them explicitly.
 */

import type { APIRequestContext, Page } from '@playwright/test';

/**
 * The SPA origin of the E2E stack. It is the backend's `FRONTEND_URL` in both
 * the local `scripts/e2e-up.sh` stack and the CI job.
 */
export const E2E_FRONTEND_ORIGIN = 'http://localhost:4321';

/**
 * `Origin` plus `X-XSRF-TOKEN` for a folded `Cookie` request header.
 *
 * The token is the `XSRF-TOKEN` cookie the API sets on every response and that
 * `E2ECookieJar` already folds into the header. A missing token yields no header
 * on purpose: the server then fails closed instead of accepting the request.
 */
export function browserAuthHeaders(cookieHeader: string | undefined): Record<string, string> {
    const headers: Record<string, string> = {'Origin': E2E_FRONTEND_ORIGIN};
    if (!cookieHeader) return headers;

    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(cookieHeader);
    if (!match?.[1]) return headers;

    headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1]);
    return headers;
}

/**
 * The same headers for `page.request`, which takes its cookies from the browser
 * context instead of an explicit `Cookie` header.
 */
export async function browserAuthHeadersFromPage(page: Page): Promise<Record<string, string>> {
    const cookies = await page.context().cookies();
    const token = cookies.find(cookie => cookie.name === 'XSRF-TOKEN')?.value;
    const headers: Record<string, string> = {'Origin': E2E_FRONTEND_ORIGIN};
    if (token) headers['X-XSRF-TOKEN'] = token;

    return headers;
}

/**
 * The same headers for the standalone `request` fixture.
 *
 * That fixture keeps its own cookie jar: a harness login stores the auth cookies
 * in it, and every later mutation of the same test then carries them — so the
 * server treats the call as cookie-authenticated and requires the token, even
 * when the call sends no `Cookie` header itself. The token comes from that jar;
 * when it holds none yet, one `GET /api/ping` mints the `XSRF-TOKEN` cookie the
 * middleware sets on every API response. A cookie-less jar therefore stays
 * cookie-less, and the server's cookie-less carve-out still applies.
 */
export async function browserAuthHeadersFromRequest(request: APIRequestContext): Promise<Record<string, string>> {
    const token = await csrfTokenFromJar(request);
    const headers: Record<string, string> = {'Origin': E2E_FRONTEND_ORIGIN};
    if (token) headers['X-XSRF-TOKEN'] = token;

    return headers;
}

async function csrfTokenFromJar(request: APIRequestContext): Promise<string | undefined> {
    const readToken = async () => {
        const state = await request.storageState();
        return state.cookies.find(cookie => cookie.name === 'XSRF-TOKEN')?.value;
    };

    const existing = await readToken();
    if (existing) return existing;

    const mint = await request.get('/api/ping', {headers: {'Accept': 'application/json'}});
    if (!mint.ok()) return undefined;

    return readToken();
}
