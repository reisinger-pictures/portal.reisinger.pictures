import type { Page, Response } from '@playwright/test';
import { describe, expect, it, vi } from 'vitest';
import { NetworkHelper } from './NetworkHelper';

/**
 * The rejection `page.waitForResponse` produces when it runs out of time,
 * reproduced verbatim.
 *
 * Captured from Playwright 1.62.1 against a real page (`error.name` is
 * "TimeoutError", `error.constructor.name` is "TimeoutError2", and the message
 * names the primitive but not the request — the gap these tests close). The
 * real primitive cannot be exercised here: a *value* import of
 * `@playwright/test` costs ~120s of transform in the Vitest process, against a
 * ~340ms baseline, so a real browser belongs to the Playwright runner.
 */
function playwrightTimeoutError(timeout: number): Error {
    return Object.assign(
        new Error(`page.waitForResponse: Timeout ${timeout}ms exceeded while waiting for event "response"`),
        { name: 'TimeoutError' }
    );
}

/** A `Page` whose `waitForResponse` fails the way the real one fails. */
function pageFailingWith(error: Error) {
    const waitForResponse = vi.fn().mockRejectedValue(error);
    return { page: { waitForResponse } as unknown as Page, waitForResponse };
}

/** Await a promise that is expected to reject, and hand back the Error. */
async function rejection(promise: Promise<unknown>): Promise<Error> {
    try {
        await promise;
    } catch (error) {
        if (error instanceof Error) return error;
        throw new Error(`expected an Error, received ${String(error)}`, { cause: error });
    }
    throw new Error('expected the wait to reject, but it resolved');
}

describe('NetworkHelper — a timeout is a failure, not a value', () => {
    it('fails waitForApi with a message naming the endpoint, the method and the timeout', async () => {
        const { page } = pageFailingWith(playwrightTimeoutError(30000));

        const error = await rejection(new NetworkHelper(page).waitForApi('/api/management/upload', 'POST'));

        // Still Playwright's own error, so the reporter, the trace and the HTML
        // report keep classifying this as a Playwright wait timeout.
        expect(error.name).toBe('TimeoutError');
        // The three facts the native message cannot know. Without them a bare
        // "page.waitForResponse: Timeout 30000ms exceeded" is unattributable
        // across this suite's call sites.
        expect(error.message).toContain('/api/management/upload');
        expect(error.message).toContain('POST');
        expect(error.message).toContain('30000ms');
        // The original message survives, rather than being replaced.
        expect(error.message).toContain('page.waitForResponse: Timeout 30000ms exceeded');
    });

    it('never resolves a stand-in that a caller could mistake for a response', async () => {
        const { page } = pageFailingWith(playwrightTimeoutError(30000));

        const settled = await new NetworkHelper(page).waitForApi('/rate', 'POST').then(
            (value: Response) => ({ resolved: true as const, value }),
            (error: unknown) => ({ resolved: false as const, error })
        );

        // This is the regression: the old contract resolved here with
        // `null as unknown as Response`, so `if (!res)` was a caller's only way
        // to learn that nothing had answered.
        expect(settled.resolved).toBe(false);
    });

    it('names the default management scope when waitForManagementMutation gets no pattern', async () => {
        const { page } = pageFailingWith(playwrightTimeoutError(15000));

        const error = await rejection(new NetworkHelper(page).waitForManagementMutation());

        expect(error.name).toBe('TimeoutError');
        expect(error.message).toContain('/api/management/');
        expect(error.message).toContain('POST/PUT/DELETE');
        expect(error.message).toContain('15000ms');
    });

    it('names the narrowed pattern when waitForManagementMutation gets one', async () => {
        const { page } = pageFailingWith(playwrightTimeoutError(15000));

        const error = await rejection(new NetworkHelper(page).waitForManagementMutation('/api/management/galleries'));

        expect(error.message).toContain('/api/management/galleries');
        expect(error.message).toContain('15000ms');
    });

    it('rethrows a non-timeout failure untouched rather than mislabelling it as a missing response', async () => {
        // A closed page already says exactly what went wrong. Calling that a
        // timeout would replace a true statement with a false one.
        const original = new Error('Target page, context or browser has been closed');
        const { page } = pageFailingWith(original);

        const error = await rejection(new NetworkHelper(page).waitForManagementMutation());

        expect(error).toBe(original);
        expect(error.message).not.toContain('NetworkHelper');
    });

    it('returns the real response when the request is answered, on unchanged budgets', async () => {
        const response = { ok: () => true, status: () => 200 } as unknown as Response;
        const waitForResponse = vi.fn().mockResolvedValue(response);
        const helper = new NetworkHelper({ waitForResponse } as unknown as Page);

        await expect(helper.waitForApi('/api/management/galleries', 'POST')).resolves.toBe(response);
        expect(waitForResponse).toHaveBeenCalledWith(expect.any(Function), { timeout: 30000 });

        await expect(helper.waitForManagementMutation('/api/management/galleries')).resolves.toBe(response);
        expect(waitForResponse).toHaveBeenLastCalledWith(expect.any(Function), { timeout: 15000 });
    });
});
