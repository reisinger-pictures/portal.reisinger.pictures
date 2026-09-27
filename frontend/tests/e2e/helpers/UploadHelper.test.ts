import type { Page, Response } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const { playwrightExpect, toBeTruthy, toBeAttached, toBeVisible } = vi.hoisted(() => ({
    playwrightExpect: vi.fn(),
    toBeTruthy: vi.fn(),
    toBeAttached: vi.fn(),
    toBeVisible: vi.fn(),
}));

vi.mock('@playwright/test', () => ({
    expect: playwrightExpect,
}));

// NetworkHelper and LightboxHelper are deliberately NOT mocked: these tests run
// the real ones, so the message asserted below is the message a real timeout
// produces. Only Playwright's `expect` is replaced, to observe which assertion
// the upload reaches at all.
import { NetworkHelper } from './NetworkHelper';
import { UploadHelper } from './UploadHelper';

/**
 * The rejection `page.waitForResponse` produces when it runs out of time,
 * reproduced verbatim (see NetworkHelper.test.ts for the captured shape).
 */
function playwrightTimeoutError(timeout: number): Error {
    return Object.assign(
        new Error(`page.waitForResponse: Timeout ${timeout}ms exceeded while waiting for event "response"`),
        { name: 'TimeoutError' }
    );
}

/** A `Response` as Playwright hands one over: always a real one, ok or not. */
function response({ ok, status, body = '' }: { ok: boolean; status: number; body?: string }): Response {
    return { ok: () => ok, status: () => status, text: () => Promise.resolve(body) } as unknown as Response;
}

/** Marks "the API assertion passed and the helper moved on to the DOM". */
class ReachedDomAssertions extends Error {}

const DOM_ASSERTIONS_REACHED = 'DOM assertions reached';

/**
 * A page with a file input and a `waitForResponse` that settles the way the
 * real one does — either with a response, or not at all.
 */
function uploadPage(settle: () => Promise<Response>) {
    const fileInput = {
        evaluate: vi.fn().mockResolvedValue(undefined),
        setInputFiles: vi.fn().mockResolvedValue(undefined),
    };
    const locator = vi.fn().mockReturnValue({
        first: () => fileInput,
        filter: () => ({ first: () => ({}) }),
    });
    const waitForResponse = vi.fn(settle);
    return { page: { locator, waitForResponse } as unknown as Page, fileInput, waitForResponse };
}

/** Run the upload and hand back the failure, whatever shape it arrives in. */
async function failureOf(promise: Promise<unknown>): Promise<Error> {
    try {
        await promise;
    } catch (error) {
        if (error instanceof Error) return error;
        throw new Error(`expected an Error, received ${String(error)}`, { cause: error });
    }
    throw new Error('expected the upload to fail, but it resolved');
}

describe('UploadHelper.uploadSampleImage', () => {
    let consoleError: ReturnType<typeof vi.spyOn>;
    // Playwright's shape is `expect(value, message).matcher()`: the value and the
    // message arrive at `expect`, the matcher takes neither. Mirroring that is
    // what makes the failure message the one a reader would see.
    let received: unknown;
    let receivedMessage: string | undefined;

    beforeEach(() => {
        received = undefined;
        receivedMessage = undefined;
        playwrightExpect.mockReset();
        toBeTruthy.mockReset();
        toBeAttached.mockReset();
        toBeVisible.mockReset();
        // `toBeTruthy` is the API assertion and it carries the message, so it has
        // to actually fail — a spy that always passes would hide the very output
        // under test.
        toBeTruthy.mockImplementation(() => {
            if (!received) throw new Error(receivedMessage ?? 'expect(value).toBeTruthy() failed');
        });
        // The DOM assertions are not what is under test; stopping at the first
        // one marks how far the helper got.
        toBeVisible.mockImplementation(() => {
            throw new ReachedDomAssertions(DOM_ASSERTIONS_REACHED);
        });
        playwrightExpect.mockImplementation((value: unknown, message?: string) => {
            received = value;
            receivedMessage = message;
            return { toBeTruthy, toBeAttached, toBeVisible };
        });
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
    });

    it('reports an answered-but-failing upload with its status and its body', async () => {
        const body = '{"message":"Das Bild ist groesser als erlaubt"}';
        const { page, fileInput } = uploadPage(() =>
            Promise.resolve(response({ ok: false, status: 413, body }))
        );

        const failure = await failureOf(new UploadHelper(page).uploadSampleImage());

        expect(fileInput.setInputFiles).toHaveBeenCalled();

        // Status and body — the diagnostics that are real, because the response
        // exists. This is the whole point of keeping the body read.
        expect(failure.message).toBe(
            `Upload API request failed with status 413. Details: ${body}`
        );

        // …and the body is also on the console, for the case where the report
        // truncates the assertion message.
        expect(consoleError).toHaveBeenCalledWith(body);
    });

    it('never states a timeout it could not have observed', async () => {
        const { page } = uploadPage(() => Promise.resolve(response({ ok: false, status: 500, body: 'boom' })));

        const failure = await failureOf(new UploadHelper(page).uploadSampleImage());

        // The message can no longer carry a claim about a missing response:
        // `waitForUpload` throws instead of handing one back, so this code has
        // only ever seen a real Response.
        expect(failure.message).not.toContain('timed out');
        expect(failure.message).not.toContain('no response received');
    });

    it('surfaces an unanswered upload as NetworkHelper\'s TimeoutError, not as an upload failure', async () => {
        const timeoutError = playwrightTimeoutError(30000);
        const { page } = uploadPage(() => Promise.reject(timeoutError));

        const failure = await failureOf(new UploadHelper(page).uploadSampleImage());

        // Still Playwright's own TimeoutError, so the reporter, the trace and
        // the HTML report keep classifying this as a wait timeout.
        expect(failure.name).toBe('TimeoutError');
        expect(failure).toBe(timeoutError);

        // The three facts the native message cannot know: which request, which
        // method, which budget. These are what reach the reader — the upload
        // helper neither swallows nor rewrites them.
        expect(failure.message).toContain('/api/management/upload');
        expect(failure.message).toContain('POST');
        expect(failure.message).toContain('30000ms');
        expect(failure.message).toContain('page.waitForResponse: Timeout 30000ms exceeded');

        // The upload assertion is never reached, so it cannot be the reported
        // cause. This is the assertion that used to read
        // "Upload API request failed (timed out). Details: Upload request timed
        // out (no response received)" for a POST that was still in flight.
        expect(toBeTruthy).not.toHaveBeenCalled();
        expect(failure.message).not.toContain('Upload API request failed');
    });

    it('reaches the upload assertion for exactly the two outcomes that exist', async () => {
        // 1. answered and ok — the assertion passes, the helper continues.
        const ok = uploadPage(() => Promise.resolve(response({ ok: true, status: 201 })));
        await expect(new UploadHelper(ok.page).uploadSampleImage()).rejects.toThrow(DOM_ASSERTIONS_REACHED);
        expect(playwrightExpect).toHaveBeenCalledWith(true, expect.any(String));
        expect(toBeVisible).toHaveBeenCalled();

        // 2. answered and not ok — the assertion is the report.
        playwrightExpect.mockClear();
        toBeTruthy.mockClear();
        toBeVisible.mockClear();
        const failing = uploadPage(() => Promise.resolve(response({ ok: false, status: 500, body: 'boom' })));
        await failureOf(new UploadHelper(failing.page).uploadSampleImage());
        expect(playwrightExpect).toHaveBeenCalledWith(false, expect.stringContaining('500'));
        expect(toBeVisible).not.toHaveBeenCalled();

        // 3. never answered — nothing at the assertion site is reached at all.
        playwrightExpect.mockClear();
        toBeTruthy.mockClear();
        const unanswered = uploadPage(() => Promise.reject(playwrightTimeoutError(30000)));
        await failureOf(new UploadHelper(unanswered.page).uploadSampleImage());
        expect(playwrightExpect).not.toHaveBeenCalled();

        // Three outcomes, two reach the assertion, and the third is a throw
        // upstream of it. There is no fourth case in which `res` could be
        // falsy, which is why `if (res)` and its else arm were unreachable.
    });

    it('cannot be handed a stand-in for a response', async () => {
        // Compile-time half of the reachability proof: this only type-checks
        // while the resolved type of the wait excludes `null`. Should the wait
        // ever resolve a stand-in again, `npx tsc -b` fails here — the branch
        // removal does not silently become a lie.
        type IsNullable<T> = null extends T ? true : false;
        const waitIsNotNullable: IsNullable<Awaited<ReturnType<NetworkHelper['waitForUpload']>>> = false;
        expect(waitIsNotNullable).toBe(false);

        // …and the same fact at runtime: the wait rejects rather than resolving.
        const { page } = uploadPage(() => Promise.reject(playwrightTimeoutError(30000)));
        const settled = await new NetworkHelper(page).waitForUpload().then(
            (value: Response) => ({ resolved: true as const, value }),
            (error: unknown) => ({ resolved: false as const, error })
        );
        expect(settled.resolved).toBe(false);
    });

    it('carries no timeout wording left to assert', () => {
        // The behaviour tests above cannot detect a *reintroduced* dead branch:
        // it would be dead again, and they would still pass. Only the source
        // can be asked whether the fiction is gone.
        const source = readFileSync(
            path.resolve(process.cwd(), 'tests/e2e/helpers/UploadHelper.ts'),
            'utf8'
        );

        expect(source).not.toContain('timed out');
        expect(source).not.toContain('no response received');
        // The genuinely useful half survives both this guard and the tests above.
        expect(source).toContain('await res.text()');
    });
});
