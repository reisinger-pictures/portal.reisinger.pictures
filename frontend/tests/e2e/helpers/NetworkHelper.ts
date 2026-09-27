import { Page, Response } from '@playwright/test';

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

/** Request methods that count as a management mutation. */
const MUTATION_METHODS: readonly string[] = ['POST', 'PUT', 'DELETE'];

/** Budget for a wait on one specific, named request. */
const API_RESPONSE_TIMEOUT_MS = 30000;

/** Budget for the "any management mutation" wait a modal submit uses. */
const MANAGEMENT_MUTATION_TIMEOUT_MS = 15000;

export class NetworkHelper {
    constructor(private page: Page) {}

    /**
     * Zentrale Methode um auf API-Antworten zu warten.
     * Verhindert Flakiness bei asynchronen SWR/React-Query Updates.
     *
     * Returns a real `Response` or throws. It never resolves to a stand-in:
     * "the request was never answered" and "the response arrived" must not be
     * indistinguishable to a caller. This method used to catch the timeout and
     * return `null as unknown as Response`, which pushed the real failure one
     * frame down the stack — `ModalHelper.submitModal` then asserted that the
     * modal had closed and Playwright reported `expect(locator).toBeHidden()
     * failed`, a UI symptom pointing away from the POST that was still in
     * flight.
     */
    async waitForApi(urlIncludes: string, method: HttpMethod): Promise<Response> {
        return this.waitForResponse(
            response => response.url().includes(urlIncludes) && response.request().method() === method,
            `${method} ${urlIncludes}`,
            API_RESPONSE_TIMEOUT_MS
        );
    }

    // --- Spezifische Endpunkte für das Reisinger Portal ---

    waitForUpload() { return this.waitForApi('/api/management/upload', 'POST'); }
    waitForRating() { return this.waitForApi('/rate', 'POST'); }
    waitForOptIn() { return this.waitForApi('/opt-in', 'POST'); }
    waitForUsersRefetch() { return this.waitForApi('/api/management/users', 'GET'); }
    waitForLogin() { return this.waitForApi('/api/auth/login', 'POST'); }
    waitForMe() { return this.waitForApi('/api/auth/me', 'GET'); }
    waitForGallerySave() { return this.waitForApi('/api/management/galleries', 'POST'); }
    waitForGalleryUpdate() { return this.waitForApi('/api/management/galleries', 'PUT'); }

    /**
     * Wait for the next POST/PUT/DELETE under `/api/management/`, optionally
     * narrowed to `urlPattern`.
     *
     * A timeout throws. It used to warn on the console and hand back `null`,
     * which `submitModal` read as "no response" and then used to skip its API
     * check — after which the very next line asserted on the modal and
     * misattributed an unanswered request to the modal.
     */
    waitForManagementMutation(urlPattern?: string): Promise<Response> {
        const target = urlPattern ?? '/api/management/';
        return this.waitForResponse(
            response => MUTATION_METHODS.includes(response.request().method()) && response.url().includes(target),
            `${MUTATION_METHODS.join('/')} request matching "${target}"`,
            MANAGEMENT_MUTATION_TIMEOUT_MS
        );
    }

    /**
     * `page.waitForResponse()` is already the waiting primitive, and it already
     * throws a `TimeoutError` on timeout — its own message says only
     * `page.waitForResponse: Timeout 15000ms exceeded`, which names the
     * primitive and not the request, and with this suite's ~25 `submitModal`
     * call sites that is not enough to act on.
     *
     * So the native error is propagated, enriched in place with the one fact
     * it cannot know: which request was awaited, for how long. Mutating rather
     * than replacing keeps `name === 'TimeoutError'`, the constructor and the
     * reporter's own timeout classification, so traces and the HTML report
     * still attribute this to Playwright's wait and not to a helper bug.
     *
     * A failure that is *not* a timeout (page closed, context crashed) is
     * rethrown untouched — it carries its own accurate message and must not be
     * relabelled as a missing response.
     */
    private async waitForResponse(
        predicate: (response: Response) => boolean,
        description: string,
        timeout: number
    ): Promise<Response> {
        try {
            return await this.page.waitForResponse(predicate, { timeout });
        } catch (error) {
            if (error instanceof Error && error.name === 'TimeoutError') {
                error.message =
                    `[NetworkHelper] No response for ${description} within ${timeout}ms. ` +
                    'The request was never answered, so whatever is asserted after this point ' +
                    'is measuring the failure, not the feature.\n' +
                    `Playwright: ${error.message}`;
            }
            throw error;
        }
    }
}
