import {spawnSync} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {afterAll, beforeAll, describe, expect, it, vi} from 'vitest';
import {
    checkStripeBuild,
    detectBundleKeyModes,
    getStripeBuildError,
    isDeliberateTestBuild,
    PRODUCTION_ENV_FILE_NAME
} from './check-stripe-build.mjs';

const scriptPath = resolve(dirname(fileURLToPath(import.meta.url)), 'check-stripe-build.mjs');
const realBundleDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'dist');

const LIVE_KEY = 'pk_live_51ExampleBodyValue';
const TEST_KEY = 'pk_test_51ExampleBodyValue';
/** Anchored on prefix + body, exactly like the grep documented in AGENTS.md §14/D-24. */
const KEY_MATERIAL_PATTERN = /pk_(live|test)_[A-Za-z0-9]{12}/;

/** @param {string} contents */
const bundleFile = contents => [{path: 'dist/assets/index.js', contents}];

/** @param {string} contents */
const keyModesFor = contents => detectBundleKeyModes(bundleFile(contents));

/** @param {Record<string, string | undefined>} environment */
const errorFor = (environment, productionEnvFileExists, contents) => getStripeBuildError({
    environment,
    productionEnvFileExists,
    keyModes: keyModesFor(contents)
});

/** Runs the check against a throwaway root and returns what it wrote to stdout/stderr. */
const runAgainst = (rootDirectory, environment) => {
    const stdout = vi.spyOn(process.stdout, 'write').mockImplementation(() => true);
    const stderr = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);

    try {
        const passed = checkStripeBuild({environment, rootDirectory});

        return {
            passed,
            stdout: stdout.mock.calls.map(([chunk]) => String(chunk)).join(''),
            stderr: stderr.mock.calls.map(([chunk]) => String(chunk)).join('')
        };
    } finally {
        stdout.mockRestore();
        stderr.mockRestore();
    }
};

describe('Stripe build key detection', () => {
    it('counts a real inlined publishable key', () => {
        expect(keyModesFor(`const k="${LIVE_KEY}";`)).toEqual({live: 1, test: 0});
        expect(keyModesFor(`const k="${TEST_KEY}";`)).toEqual({live: 0, test: 1});
    });

    it('counts both modes independently across files', () => {
        const modes = detectBundleKeyModes([
            {path: 'dist/assets/a.js', contents: `"${LIVE_KEY}"`},
            {path: 'dist/assets/b.js', contents: `"${TEST_KEY}"`}
        ]);

        expect(modes).toEqual({live: 1, test: 1});
    });

    it('does not count the bare prefix literal of the Stripe mode detector', () => {
        // `ManagementSettingsView` matches keys with /^(pk|sk|rk)_(test|live)(?:_|$)/i;
        // a plain includes('pk_live_') would report a live key for every build,
        // including a test build.
        const detector = "const match = key?.match(/^(pk|sk|rk)_(test|live)(?:_|$)/i); return match[2] === 'live';";

        expect(detectBundleKeyModes(bundleFile(detector))).toEqual({live: 0, test: 0});
    });

    it('does not count the placeholder key of .env.example', () => {
        expect(keyModesFor('const k="pk_test_<your_stripe_publishable_key>";')).toEqual({live: 0, test: 0});
    });
});

describe('Stripe production build check', () => {
    it('stays silent for an undeclared local build that carries no key', () => {
        expect(errorFor({}, false, 'const a = 1;')).toBeNull();
    });

    it('rejects the 2026-10-15 shape: a local build shipping the test key', () => {
        const error = errorFor({}, false, `const k="${TEST_KEY}";`);

        expect(error).toContain('pk_test_');
        expect(error).toContain(PRODUCTION_ENV_FILE_NAME);
    });

    it('stays silent in CI, which builds without the live key on purpose', () => {
        expect(errorFor({CI: 'true'}, false, `const k="${TEST_KEY}";`)).toBeNull();
    });

    it('rejects a declared production build that carries the test key', () => {
        const error = errorFor({}, true, `const k="${TEST_KEY}";`);

        expect(error).toContain('pk_test_');
        expect(error).toContain('Stripe.js would load in test mode');
    });

    it('accepts a declared production build that carries the live key', () => {
        expect(errorFor({}, true, `const k="${LIVE_KEY}";`)).toBeNull();
    });

    it('rejects a declared production build without any publishable key', () => {
        const error = errorFor({}, true, 'const a = 1;');

        expect(error).toContain('pk_live_');
        expect(error).toContain(PRODUCTION_ENV_FILE_NAME);
    });

    it('accepts an explicitly declared test build', () => {
        expect(isDeliberateTestBuild({STRIPE_TEST_BUILD: 'true'})).toBe(true);
        expect(errorFor({STRIPE_TEST_BUILD: 'true'}, false, `const k="${TEST_KEY}";`)).toBeNull();
    });

    it('only treats the exact value true as a test-build declaration', () => {
        expect(isDeliberateTestBuild({STRIPE_TEST_BUILD: 'false'})).toBe(false);
        expect(errorFor({STRIPE_TEST_BUILD: 'false'}, false, `const k="${TEST_KEY}";`)).toContain('pk_test_');
    });

    it('rejects contradicting declarations instead of picking one', () => {
        const error = errorFor({STRIPE_TEST_BUILD: 'true'}, true, `const k="${LIVE_KEY}";`);

        expect(error).toContain('STRIPE_TEST_BUILD=true');
        expect(error).toContain(PRODUCTION_ENV_FILE_NAME);
    });

    it('never puts key material into the failure message', () => {
        const error = errorFor({}, true, `const k="${TEST_KEY}";`);

        expect(error).not.toContain(TEST_KEY);
        expect(error).not.toContain(TEST_KEY.slice('pk_test_'.length));
    });
});

describe('Stripe build check runner', () => {
    let fixtureRoot = '';

    beforeAll(() => {
        fixtureRoot = mkdtempSync(join(tmpdir(), 'stripe-build-gate-'));
        mkdirSync(join(fixtureRoot, 'dist', 'assets'), {recursive: true});
        writeFileSync(join(fixtureRoot, 'dist', 'assets', 'index.js'), `const k="${TEST_KEY}";`);
    });

    afterAll(() => {
        rmSync(fixtureRoot, {recursive: true, force: true});
    });

    it('reads the bundle and reports the key mode it found', () => {
        const result = runAgainst(fixtureRoot, {CI: 'true'});

        expect(result.passed).toBe(true);
        expect(result.stdout).toContain('pk_test_');
        expect(result.stderr).toBe('');
    });

    it('fails the undeclared local build that would ship the test key', () => {
        const result = runAgainst(fixtureRoot, {});

        expect(result.passed).toBe(false);
        expect(result.stderr).toContain('pk_test_');
        expect(`${result.stdout}${result.stderr}`).not.toMatch(KEY_MATERIAL_PATTERN);
    });

    it('fails a declared production build whose bundle carries the test key', () => {
        writeFileSync(join(fixtureRoot, PRODUCTION_ENV_FILE_NAME), 'VITE_STRIPE_PUBLIC_KEY=pk_live_placeholder\n');

        try {
            const result = runAgainst(fixtureRoot, {});

            expect(result.passed).toBe(false);
            expect(result.stderr).toContain('Stripe.js would load in test mode');
            expect(`${result.stdout}${result.stderr}`).not.toMatch(KEY_MATERIAL_PATTERN);
        } finally {
            rmSync(join(fixtureRoot, PRODUCTION_ENV_FILE_NAME));
        }
    });

    it('reports a deliberate test build instead of failing it', () => {
        const result = runAgainst(fixtureRoot, {STRIPE_TEST_BUILD: 'true'});

        expect(result.passed).toBe(true);
        expect(result.stdout).toContain('must not be deployed');
        expect(result.stderr).toBe('');
    });

    it('fails closed when there is no build output to inspect', () => {
        const emptyRoot = mkdtempSync(join(tmpdir(), 'stripe-build-gate-empty-'));

        try {
            const result = runAgainst(emptyRoot, {});

            expect(result.passed).toBe(false);
            expect(result.stderr).toContain('no build output');
        } finally {
            rmSync(emptyRoot, {recursive: true, force: true});
        }
    });

    it('never leaks key material when invoked as the postbuild step', () => {
        const result = spawnSync(process.execPath, [scriptPath], {encoding: 'utf8'});
        const output = `${result.stdout}${result.stderr}`;

        expect(result.signal).toBeNull();
        expect(output).not.toMatch(KEY_MATERIAL_PATTERN);

        // A bundle that exists must pass in this working copy: the live key lives
        // in frontend/.env.production and nothing declares a test build. Without a
        // build (fresh clone, worktree) there is nothing to assert.
        if (existsSync(realBundleDirectory)) {
            expect(result.status, output).toBe(0);
        }
    });
});
