#!/usr/bin/env node
/**
 * Guard the Stripe publishable key that `vite build` bakes into the bundle.
 *
 * `VITE_STRIPE_PUBLIC_KEY` is inlined at build time, and the bundle is what gets
 * synced to the host (`AGENTS.md` §13). A bundle whose Stripe.js runs in test
 * mode cannot confirm a live PaymentIntent — measured 2026-10-15, when the live
 * bundle and the local `dist/` carried the same `pk_test_…` while the backend
 * container held `sk_live_…` (`AGENTS.md` §14/D-24).
 *
 * Which shape is checked, and why:
 *
 *   declared production build
 *     `frontend/.env.production` exists. The file *is* the deploy declaration:
 *     the live key belongs in it, and it outranks `.env.local`, which Vite
 *     loads in every mode (including a production build).
 *     → the bundle MUST carry a `pk_live_` key and MUST NOT carry `pk_test_`.
 *
 *   CI build
 *     `CI` is set and nothing is declared. CI builds without the live key on
 *     purpose; its E2E job hands the test key to a dev server, never to a
 *     bundle. → silent.
 *
 *   undeclared local build
 *     neither of the above. → the bundle MUST NOT carry `pk_test_`. That is the
 *     2026-10-15 shape: a dev-file test key silently baked into a bundle that
 *     is then deployed. A build that carries no key at all (fresh clone,
 *     worktree) stays silent — there is nothing to ship.
 *
 *   deliberate test build
 *     `STRIPE_TEST_BUILD=true` in the build environment — a process variable,
 *     not a `.env` file, because Vite never exposes it to the bundle and this
 *     script reads `process.env`. → allowed, and reported loudly. Combined with
 *     `.env.production` the two declarations contradict each other and fail.
 *
 * The check reads the bundle and the *presence* of `.env.production`; it never
 * reads a key value out of an env file and never prints key material. Only the
 * mode prefix (`pk_live_`/`pk_test_`) can reach a message.
 */

import {existsSync, readdirSync, readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import process from 'node:process';
import {fileURLToPath} from 'node:url';

/**
 * A publishable key that is *really* inlined: the prefix plus at least twelve
 * characters of the key body. Matching the body avoids treating placeholders
 * such as `pk_test_<your_stripe_publishable_key>` as real keys. Same shape as
 * the manual check documented in `AGENTS.md` §14/D-24.
 */
const LIVE_KEY_PATTERN = /pk_live_[A-Za-z0-9]{12}/;
const TEST_KEY_PATTERN = /pk_test_[A-Za-z0-9]{12}/;

export const PRODUCTION_ENV_FILE_NAME = '.env.production';
export const BUNDLE_DIRECTORY_NAME = 'dist';

const requiredValue = (environment, key) => environment[key]?.trim() ?? '';
const isSet = (environment, key) => requiredValue(environment, key) !== '';

/**
 * @param {Record<string, string | undefined>} environment
 * @returns {boolean}
 */
export function isDeliberateTestBuild(environment) {
    return requiredValue(environment, 'STRIPE_TEST_BUILD').toLowerCase() === 'true';
}

/**
 * Count the bundle files carrying a real live/test publishable key. Pure: the
 * caller supplies the contents, so no test needs a build output.
 *
 * @param {Array<{path: string, contents: string}>} bundleFiles
 * @returns {{live: number, test: number}}
 */
export function detectBundleKeyModes(bundleFiles) {
    return bundleFiles.reduce(
        (counts, file) => ({
            live: counts.live + (LIVE_KEY_PATTERN.test(file.contents) ? 1 : 0),
            test: counts.test + (TEST_KEY_PATTERN.test(file.contents) ? 1 : 0)
        }),
        {live: 0, test: 0}
    );
}

/**
 * @param {{
 *     environment: Record<string, string | undefined>,
 *     productionEnvFileExists: boolean,
 *     keyModes: {live: number, test: number}
 * }} input
 * @returns {string | null}
 */
export function getStripeBuildError({environment, productionEnvFileExists, keyModes}) {
    const declaredProductionBuild = productionEnvFileExists;
    const declaredTestBuild = isDeliberateTestBuild(environment);

    if (declaredProductionBuild && declaredTestBuild) {
        return `frontend/${PRODUCTION_ENV_FILE_NAME} declares a production build while STRIPE_TEST_BUILD=true declares a test build; remove one of the two declarations.`;
    }

    if (declaredTestBuild) {
        return null;
    }

    if (keyModes.test > 0 && (declaredProductionBuild || !isSet(environment, 'CI'))) {
        return `the bundle carries a pk_test_ Stripe publishable key, so Stripe.js would load in test mode and could not confirm a live PaymentIntent. Put the live key into frontend/${PRODUCTION_ENV_FILE_NAME} as VITE_STRIPE_PUBLIC_KEY — it outranks .env.local, which Vite loads in every mode. If this build is deliberately test-mode and will not be deployed, set STRIPE_TEST_BUILD=true.`;
    }

    if (declaredProductionBuild && keyModes.live === 0) {
        return `frontend/${PRODUCTION_ENV_FILE_NAME} declares a production build, but the bundle carries no pk_live_ Stripe publishable key. Set VITE_STRIPE_PUBLIC_KEY to the live key in frontend/${PRODUCTION_ENV_FILE_NAME}.`;
    }

    return null;
}

/**
 * Read every file below the bundle directory. The manual check in `AGENTS.md`
 * §14/D-24 greps the same tree (`grep -rho … frontend/dist/assets`), so the gate
 * sees exactly what a grep would.
 *
 * @param {string} directory
 * @returns {Array<{path: string, contents: string}>}
 */
function collectBundleFiles(directory) {
    const files = [];

    for (const entry of readdirSync(directory, {withFileTypes: true})) {
        const entryPath = join(directory, entry.name);

        if (entry.isDirectory()) {
            files.push(...collectBundleFiles(entryPath));
        } else if (entry.isFile()) {
            files.push({path: entryPath, contents: readFileSync(entryPath, 'utf8')});
        }
    }

    return files;
}

/**
 * @param {{live: number, test: number}} keyModes
 * @returns {string}
 */
function describeBundleKeyMode({live, test}) {
    if (live > 0) {
        return 'the bundle carries the pk_live_ key';
    }

    if (test > 0) {
        return 'the bundle carries the pk_test_ key';
    }

    return 'the bundle carries no Stripe publishable key';
}

/**
 * @param {{
 *     environment?: Record<string, string | undefined>,
 *     rootDirectory?: string
 * }} [input]
 * @returns {boolean}
 */
export function checkStripeBuild({
    environment = process.env,
    rootDirectory = resolve(dirname(fileURLToPath(import.meta.url)), '..')
} = {}) {
    const bundleDirectory = join(rootDirectory, BUNDLE_DIRECTORY_NAME);

    if (!existsSync(bundleDirectory)) {
        process.stderr.write(`\n❌ Stripe build check failed: no build output at ${BUNDLE_DIRECTORY_NAME}/. The check runs as \`postbuild\` of \`pnpm build\`.\n\n`);
        return false;
    }

    const keyModes = detectBundleKeyModes(collectBundleFiles(bundleDirectory));
    const error = getStripeBuildError({
        environment,
        productionEnvFileExists: existsSync(join(rootDirectory, PRODUCTION_ENV_FILE_NAME)),
        keyModes
    });

    if (error) {
        process.stderr.write(`\n❌ Stripe build check failed: ${error}\n   ${BUNDLE_DIRECTORY_NAME}/ holds a bundle that must not be deployed; fix the key and rebuild before syncing.\n\n`);
        return false;
    }

    if (isDeliberateTestBuild(environment)) {
        process.stdout.write(`⚠️ Stripe build check skipped (STRIPE_TEST_BUILD=true): ${describeBundleKeyMode(keyModes)}, and this build must not be deployed.\n`);
        return true;
    }

    process.stdout.write(`✅ Stripe build check passed (${describeBundleKeyMode(keyModes)}).\n`);
    return true;
}

const scriptPath = process.argv[1] ? resolve(process.argv[1]) : '';
if (scriptPath === fileURLToPath(import.meta.url) && !checkStripeBuild()) {
    process.exitCode = 1;
}
