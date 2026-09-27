import { existsSync, mkdirSync, mkdtempSync, rmSync, utimesSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { withGlobalSettingsLock } from './GlobalSettingsLock';

const roots: string[] = [];

// `rmSync` throws on a missing path unless `force` is set, and the repo's E2E
// lint rules forbid that option, so the existence check stays explicit.
const removeDirIfPresent = (dir: string) => {
    if (existsSync(dir)) rmSync(dir, { recursive: true });
};

function makeRootDir(): string {
    const root = mkdtempSync(path.join(tmpdir(), 'e2e-lock-'));
    roots.push(root);
    return root;
}

afterEach(() => {
    for (const root of roots.splice(0)) removeDirIfPresent(root);
});

// Timings an order of magnitude below the production defaults: the properties
// under test (atomic exclusion, staleness steal, bounded wait) do not depend on
// the absolute values, and the suite runs on an oversubscribed host.
const fast = { pollIntervalMs: 5, heartbeatIntervalMs: 25, staleAfterMs: 500 } as const;

const wait = (ms: number) => new Promise<void>(resolve => { setTimeout(resolve, ms); });

describe('withGlobalSettingsLock', () => {
    it('excludes a second holder of the same lock until the first releases it', async () => {
        const rootDir = makeRootDir();
        let firstEntered = false;
        let secondEntered = false;

        const first = withGlobalSettingsLock('calculator', async () => {
            firstEntered = true;
            await wait(120);
        }, { ...fast, rootDir });

        const second = withGlobalSettingsLock('calculator', async () => {
            secondEntered = true;
        }, { ...fast, rootDir });

        await wait(40);
        expect(firstEntered).toBe(true);
        expect(secondEntered, 'second holder entered while the first was inside').toBe(false);

        await first;
        await second;
        expect(secondEntered).toBe(true);
    });

    it('serialises three waiters without ever overlapping them', async () => {
        const rootDir = makeRootDir();
        const inside = new Set<number>();
        const order: number[] = [];

        const run = (id: number) => withGlobalSettingsLock('calculator', async () => {
            expect(inside.has(id), 'two holders were inside the critical section at once').toBe(false);
            inside.add(id);
            await wait(10);
            inside.delete(id);
            order.push(id);
        }, { ...fast, rootDir });

        await Promise.all([run(1), run(2), run(3)]);

        expect(order).toHaveLength(3);
        expect([...order].sort()).toEqual([1, 2, 3]);
    });

    it('does not block holders of a different lock', async () => {
        const rootDir = makeRootDir();
        await withGlobalSettingsLock('calculator', async () => {
            await withGlobalSettingsLock('brand-settings', async () => {
                // Nested acquisition of an unrelated lock must not deadlock.
            }, { ...fast, rootDir });
        }, { ...fast, rootDir });
    });

    it('releases the lock even when the body throws', async () => {
        const rootDir = makeRootDir();

        await expect(withGlobalSettingsLock('calculator', async () => {
            throw new Error('body failed');
        }, { ...fast, rootDir })).rejects.toThrow('body failed');

        // A leaked lock would make this wait for staleAfterMs and then steal it,
        // which is far slower than the immediate acquisition asserted here.
        await withGlobalSettingsLock('calculator', async () => { /* acquired again */ }, { ...fast, rootDir });
    });

    it('steals a lock whose holder stopped heartbeating', async () => {
        const rootDir = makeRootDir();
        const lockDir = path.join(rootDir, 'calculator');
        mkdirSync(lockDir, { recursive: true });
        const abandoned = new Date(Date.now() - 60_000);
        utimesSync(lockDir, abandoned, abandoned);

        await withGlobalSettingsLock('calculator', async () => { /* stolen */ }, {
            ...fast,
            rootDir,
            staleAfterMs: 1_000,
            timeoutMs: 2_000,
        });
    });

    it('re-creates the lock root if it is deleted underneath a running suite', async () => {
        const rootDir = makeRootDir();

        // Reproduces the failure mode seen in a real run: a second Playwright run
        // wiped the shared lock directory while the first was still using it,
        // and the next acquisition died with a bare ENOENT from mkdir.
        const holder = withGlobalSettingsLock('calculator', async () => {
            removeDirIfPresent(rootDir);
            await wait(40);
        }, { ...fast, rootDir });

        await withGlobalSettingsLock('calculator', async () => { /* acquired after the root vanished */ }, {
            ...fast,
            rootDir,
            timeoutMs: 2_000,
        });

        await holder;
    });

    it('fails with an actionable error instead of waiting forever', async () => {
        const rootDir = makeRootDir();
        const holder = withGlobalSettingsLock('calculator', async () => {
            await wait(200);
        }, { ...fast, rootDir });

        await expect(
            withGlobalSettingsLock('calculator', async () => { /* never reached */ }, { ...fast, rootDir, timeoutMs: 40 }),
        ).rejects.toThrow(/Timed out after 40 ms waiting for the E2E global-settings lock "calculator"/);

        await holder;
    });
});
