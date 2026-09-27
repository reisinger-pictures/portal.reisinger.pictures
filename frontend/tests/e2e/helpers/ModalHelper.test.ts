import type { Page } from '@playwright/test';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { playwrightExpect, toBeEnabled, toBeHidden } = vi.hoisted(() => ({
    playwrightExpect: vi.fn(),
    toBeEnabled: vi.fn(),
    toBeHidden: vi.fn(),
}));

vi.mock('@playwright/test', () => ({
    expect: playwrightExpect,
}));

// NetworkHelper is deliberately NOT mocked: these tests run the real one, so the
// message asserted below is the message a real timeout produces. Only
// Playwright's `expect` is replaced, to observe whether the modal assertion is
// reached at all.
import { ModalHelper } from './ModalHelper';

/**
 * A page with an open modal whose save button is clickable, and whose
 * `waitForResponse` never resolves. Together with the real NetworkHelper that
 * is the exact shape of the reported defect: the click happens, the request
 * does not come back.
 */
function pageWithSaveThatIsNeverAnswered(timeout: number) {
    const button = {
        scrollIntoViewIfNeeded: vi.fn().mockResolvedValue(undefined),
        click: vi.fn().mockResolvedValue(undefined),
    };
    const modal = { getByRole: vi.fn().mockReturnValue(button) };
    const last = vi.fn().mockReturnValue(modal);
    const waitForResponse = vi.fn().mockRejectedValue(
        Object.assign(
            new Error(`page.waitForResponse: Timeout ${timeout}ms exceeded while waiting for event "response"`),
            { name: 'TimeoutError' }
        )
    );
    const page = { locator: vi.fn().mockReturnValue({ last }), waitForResponse } as unknown as Page;
    return { page, button, waitForResponse };
}

describe('ModalHelper.submitModal', () => {
    beforeEach(() => {
        playwrightExpect.mockReset();
        toBeEnabled.mockReset();
        toBeHidden.mockReset();
        playwrightExpect.mockReturnValue({ toBeEnabled, toBeHidden });
    });

    it('reports an unanswered request as a request failure, naming the endpoint', async () => {
        const { page, button, waitForResponse } = pageWithSaveThatIsNeverAnswered(15000);

        const failure = await new ModalHelper(page)
            .submitModal('Speichern', '/api/management/galleries')
            .then(
                () => new Error('expected the submit to fail'),
                (error: unknown) => error
            );

        expect(button.click).toHaveBeenCalled();
        expect(waitForResponse).toHaveBeenCalledWith(expect.any(Function), { timeout: 15000 });

        // The failure is about the request, and it says which one.
        expect(failure).toBeInstanceOf(Error);
        const message = (failure as Error).message;
        expect(message).toContain('/api/management/galleries');
        expect(message).toContain('15000ms');
        expect((failure as Error).name).toBe('TimeoutError');

        // And the modal assertion is never reached, so it can no longer be
        // reported as the cause. This is the assertion that used to read
        // `expect(locator).toBeHidden() failed` for a POST still in flight.
        expect(toBeHidden).not.toHaveBeenCalled();
    });

    it('does not let a failing click surface the pending wait as an unhandled rejection', async () => {
        const { page, button } = pageWithSaveThatIsNeverAnswered(15000);
        button.click.mockRejectedValue(new Error('submit button never became enabled'));

        const unhandled: unknown[] = [];
        const onUnhandled = (reason: unknown) => unhandled.push(reason);
        process.on('unhandledRejection', onUnhandled);

        try {
            await expect(new ModalHelper(page).submitModal('Speichern')).rejects.toThrow(
                'submit button never became enabled'
            );

            // Node decides a rejection was unhandled after the microtask queue
            // drains. Without the handler attached at call time, the pending
            // wait below would be reported here — and attributed to the click
            // rather than to the response that never arrived.
            await new Promise(resolve => setTimeout(resolve, 50));

            expect(unhandled).toEqual([]);
        } finally {
            process.off('unhandledRejection', onUnhandled);
        }
    });

    it('still reports a modal that stays open after a successful save', async () => {
        const { page, waitForResponse } = pageWithSaveThatIsNeverAnswered(15000);
        waitForResponse.mockResolvedValue({
            ok: () => true,
            status: () => 200,
            json: () => Promise.resolve({ gallery: { id: 7 } }),
        });

        const result = await new ModalHelper(page).submitModal('Speichern', '/api/management/galleries');

        // A modal that refuses to close is now, and only now, a modal failure.
        expect(toBeHidden).toHaveBeenCalledWith({ timeout: 15000 });
        expect(result).toEqual({ gallery: { id: 7 } });
    });

    it('reports an answered-but-failing request with its status and body', async () => {
        const { page, waitForResponse } = pageWithSaveThatIsNeverAnswered(15000);
        waitForResponse.mockResolvedValue({
            ok: () => false,
            status: () => 422,
            text: () => Promise.resolve('{"message":"validation failed"}'),
        });

        await expect(new ModalHelper(page).submitModal('Speichern')).rejects.toThrow(
            'API Error 422: {"message":"validation failed"}'
        );
        expect(toBeHidden).not.toHaveBeenCalled();
    });
});
