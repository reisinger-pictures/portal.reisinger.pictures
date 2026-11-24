import { describe, expect, it, vi } from 'vitest';

vi.mock('swr', () => ({
    default: vi.fn(),
    mutate: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
    apiUpload: vi.fn(),
}));

import { mutate } from 'swr';
import { revalidateEur } from '../useEur';

describe('revalidateEur', () => {
    it('only revalidates matching keys and never writes undefined into the SWR cache', async () => {
        await revalidateEur();

        // A single argument is SWR's revalidate-only path. The three-argument
        // form with `data === undefined` populates the cache with undefined,
        // which makes every EUR panel report isLoading and unmount its rows —
        // dropping the inline confirmation of the write just performed.
        expect(mutate).toHaveBeenCalledTimes(1);
        const args = vi.mocked(mutate).mock.calls[0];
        expect(args).toHaveLength(1);
        expect(typeof args[0]).toBe('function');
        expect((args[0] as (key: unknown) => boolean)('/api/management/eur/documents?year=2026')).toBe(true);
        expect((args[0] as (key: unknown) => boolean)('/api/management/other')).toBe(false);
    });
});
