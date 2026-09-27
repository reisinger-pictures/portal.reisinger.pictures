import { createHash, randomUUID } from 'node:crypto';
import { mkdirSync, readFileSync, rmSync, statSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

/**
 * Cross-worker advisory lock for E2E state that every worker on the box
 * shares.
 *
 * ## Why this exists
 *
 * Some backend state is a singleton, not per-test data. The shooting-calculator
 * configuration is the canonical example: `SettingResolver` scopes it by the
 * *brand* of the incoming request, and the portal has exactly one brand
 * (`config/brands.php` registers `rp` only, and `App\Enums\Brand` has a single
 * `B2B` case). A request cannot be pointed at a second brand, so
 * `PUT /api/management/settings/license-terms` from any user of any spec always
 * writes the same `settings` row. Playwright's `fullyParallel` plus several
 * workers therefore run *concurrent tests that share one mutable row*: a
 * `beforeEach` that writes the settings and a test that reads them through the
 * UI interleave, and the reader asserts against a value the writer replaced
 * mid-test. That is an order dependency, and a serial run hides it completely.
 *
 * Note what this rules out: the settings cannot be isolated per spec. There is
 * no second brand to isolate *into*, so "give this spec its own brand" is not
 * implementable here, and giving every dependent spec its own `beforeEach`
 * write would only narrow the collision window instead of closing it — the
 * reader still needs the writer to be somewhere else at the wrong moment.
 * Mutual exclusion is the only thing that actually closes it.
 *
 * Playwright offers no cross-file test dependency and no cross-process mutex,
 * so the exclusion is implemented here. `mkdir()` without `recursive` is atomic
 * and fails with `EEXIST` while another worker holds the directory — the only
 * portable mutual-exclusion primitive Node exposes. Two cooperating call sites
 * in the same run are all it takes to close the window; no Playwright internals
 * and no test-order bookkeeping are involved.
 *
 * ## Scope
 *
 * The lock is a *machine-local* file lock, which is exactly the scope of the
 * shared state it protects: CI shards get their own backend and database, so
 * they never contend with each other, while the workers inside a shard share
 * one filesystem.
 *
 * ## Naming
 *
 * Always use {@link SHOOTING_CALCULATOR_SETTINGS_LOCK} for the calculator
 * settings. Both the spec that writes them and the spec that reads them must
 * import the constant — a hand-typed lock name in one spec would silently
 * disable the isolation and reintroduce exactly the race this prevents.
 */

/** Canonical name of the shooting-calculator settings lock. Import it, never retype it. */
export const SHOOTING_CALCULATOR_SETTINGS_LOCK = 'shooting-calculator-settings';

const OWNER_FILE = 'owner.json';

export interface GlobalSettingsLockOptions {
    /**
     * Directory holding the lock directories. Defaults to a per-checkout
     * directory in the OS temp dir.
     */
    rootDir?: string;
    /**
     * Upper bound on waiting for a busy lock. Default 90_000 ms.
     *
     * Deliberately below Playwright's 120 s per-test budget: the wait is charged
     * to the test that is waiting, so a queue deeper than this must surface as
     * this explicit error — naming the lock and its owner — rather than as an
     * opaque `Test timeout exceeded` once the runner tears the context down. It
     * is far above any real queue: a CI shard runs each of the three
     * calculator tests once, and one critical section is ~10-25 s.
     */
    timeoutMs?: number;
    /** A lock whose heartbeat is older than this counts as abandoned and is stolen. Default 20_000 ms. */
    staleAfterMs?: number;
    /** Heartbeat period while the lock is held. Default 5_000 ms. */
    heartbeatIntervalMs?: number;
    /** Poll period while waiting for a busy lock. Default 250 ms. */
    pollIntervalMs?: number;
}

export interface GlobalSettingsLockHandle {
    /** Lock name this handle owns. */
    readonly name: string;
    /** Absolute path of the lock directory. Diagnostic use. */
    readonly path: string;
    /** Releases the lock. Idempotent, and a no-op once another worker has stolen it. */
    release: () => void;
}

/**
 * Where the lock directories live by default.
 *
 * Deliberately **not** the Playwright `outputDir`: Playwright wipes that
 * directory at the start of every run, so a second run started in the same
 * checkout while the first is still going deletes the live lock directories out
 * from under it (observed as `ENOENT … mkdir` mid-run). The OS temp dir is not
 * managed by any test runner, and the checkout hash keeps two checkouts from
 * contending with each other. The consequence is that a lock left behind by a
 * killed run is cleaned up by the staleness rule below rather than by a wipe —
 * which is the mechanism that has to work anyway.
 */
const defaultRootDir = (): string => {
    const checkout = createHash('sha1').update(process.cwd()).digest('hex').slice(0, 12);
    return path.join(tmpdir(), `playwright-e2e-locks-${checkout}`);
};

const sleep = (ms: number) => new Promise<void>(resolve => { setTimeout(resolve, ms); });

const isErrnoCode = (error: unknown, code: string): boolean =>
    typeof error === 'object' && error !== null && (error as NodeJS.ErrnoException).code === code;

/**
 * Remove a lock directory if it is still there. `rmSync` throws on a missing
 * path unless `force` is set, and a lock directory can legitimately disappear
 * under us (a concurrent steal, or the run being torn down), so the ENOENT case
 * is the normal outcome rather than an error.
 */
const removeDirIfPresent = (dir: string): void => {
    try {
        rmSync(dir, { recursive: true });
    } catch (error) {
        if (!isErrnoCode(error, 'ENOENT')) throw error;
    }
};

const isStale = (lockDir: string, staleAfterMs: number): boolean => {
    try {
        return Date.now() - statSync(lockDir).mtimeMs > staleAfterMs;
    } catch {
        // Vanished between the failed mkdir and the stat: it is free, and the
        // next mkdir attempt decides the winner.
        return false;
    }
};

const isOwnedBy = (lockDir: string, token: string): boolean => {
    try {
        const record = JSON.parse(readFileSync(path.join(lockDir, OWNER_FILE), 'utf8')) as { token?: unknown };
        return record.token === token;
    } catch {
        // Unreadable or not yet written: we cannot prove we are the owner, so
        // we do not delete a directory that may belong to somebody else.
        return false;
    }
};

/**
 * Acquire the named lock, waiting until it is free.
 *
 * Throws (with an actionable message) rather than hanging forever: an E2E
 * deadlock must surface as a failed test, not as a stalled CI job.
 */
async function acquireGlobalSettingsLock(
    name: string,
    options: GlobalSettingsLockOptions = {},
): Promise<GlobalSettingsLockHandle> {
    const {
        rootDir = defaultRootDir(),
        timeoutMs = 90_000,
        staleAfterMs = 20_000,
        heartbeatIntervalMs = 5_000,
        pollIntervalMs = 250,
    } = options;

    const lockDir = path.join(rootDir, name);
    const token = `${process.pid}-${randomUUID()}`;
    const startedAt = Date.now();

    const timeoutError = (cause: unknown) => new Error(
        `Timed out after ${timeoutMs} ms waiting for the E2E global-settings lock "${name}". `
        + `Its current owner is recorded in ${path.join(lockDir, OWNER_FILE)}. `
        + `A lock older than ${staleAfterMs} ms is treated as abandoned, so this means a live holder `
        + `is still inside its critical section — that is a suite-design problem (a test holding a `
        + `global lock far too long), not a product defect.`,
        { cause },
    );

    for (;;) {
        // Re-created on every attempt rather than once up front: the lock root
        // can be removed underneath a running suite, and a missing root must be
        // a retry, not a hard error.
        mkdirSync(rootDir, { recursive: true });

        try {
            mkdirSync(lockDir);
            writeFileSync(
                path.join(lockDir, OWNER_FILE),
                JSON.stringify({ token, pid: process.pid, acquiredAt: new Date().toISOString() }),
                'utf8',
            );
            break;
        } catch (error) {
            if (isErrnoCode(error, 'ENOENT')) {
                if (Date.now() - startedAt > timeoutMs) throw timeoutError(error);
                continue;
            }
            if (!isErrnoCode(error, 'EEXIST')) throw error;

            if (isStale(lockDir, staleAfterMs)) {
                // The holder was killed before it could release. Steal the lock;
                // the ownership token keeps the dead owner from deleting ours
                // when its own release() eventually runs.
                removeDirIfPresent(lockDir);
                continue;
            }

            if (Date.now() - startedAt > timeoutMs) throw timeoutError(error);

            await sleep(pollIntervalMs);
        }
    }

    // Keep the lock dir's mtime fresh so a waiter never mistakes a long but
    // healthy critical section for an abandoned lock.
    const heartbeat = setInterval(() => {
        const now = new Date();
        try {
            utimesSync(lockDir, now, now);
        } catch {
            // Lock already gone (stolen or run torn down) — nothing to refresh.
        }
    }, heartbeatIntervalMs);
    heartbeat.unref();

    let released = false;
    return {
        name,
        path: lockDir,
        release: () => {
            if (released) return;
            released = true;
            clearInterval(heartbeat);
            if (!isOwnedBy(lockDir, token)) return;
            removeDirIfPresent(lockDir);
        },
    };
}

/**
 * Run `body` with exclusive ownership of the named lock, releasing it
 * afterwards whatever happens.
 *
 * Every test that reads *or* writes the state behind a lock must call this
 * around the window between establishing that state and asserting on it.
 * Wrapping only part of the sequence leaves the same order dependency in place.
 */
export async function withGlobalSettingsLock<T>(
    name: string,
    body: (lock: GlobalSettingsLockHandle) => Promise<T>,
    options: GlobalSettingsLockOptions = {},
): Promise<T> {
    const lock = await acquireGlobalSettingsLock(name, options);
    try {
        return await body(lock);
    } finally {
        lock.release();
    }
}
