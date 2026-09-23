#!/usr/bin/env node

import {resolve} from 'node:path';
import process from 'node:process';
import {fileURLToPath} from 'node:url';

const requiredValue = (environment, key) => environment[key]?.trim() ?? '';
const isTurnstileRequired = environment => requiredValue(environment, 'TURNSTILE_BUILD_REQUIRED').toLowerCase() === 'true';

/**
 * Validate the backend/public Turnstile site-key pair without making
 * Turnstile a mandatory feature for ordinary local builds. This check reads
 * only public site keys; server secrets must never enter the frontend build.
 *
 * @param {Record<string, string | undefined>} environment
 * @returns {string | null}
 */
export function getTurnstileBuildError(environment) {
    if (!isTurnstileRequired(environment)) {
        return null;
    }

    const backendSiteKey = requiredValue(environment, 'TURNSTILE_SITE_KEY');
    const publicSiteKey = requiredValue(environment, 'VITE_TURNSTILE_SITE_KEY');

    if (!backendSiteKey) {
        return 'TURNSTILE_BUILD_REQUIRED=true requires TURNSTILE_SITE_KEY in the build environment.';
    }

    if (!publicSiteKey) {
        return 'TURNSTILE_BUILD_REQUIRED=true requires VITE_TURNSTILE_SITE_KEY in the build environment.';
    }

    if (backendSiteKey !== publicSiteKey) {
        return 'TURNSTILE_BUILD_REQUIRED=true requires VITE_TURNSTILE_SITE_KEY to match TURNSTILE_SITE_KEY; the backend/public site keys differ.';
    }

    return null;
}

/**
 * @param {Record<string, string | undefined>} environment
 * @returns {boolean}
 */
export function checkTurnstileBuild(environment) {
    const error = getTurnstileBuildError(environment);

    if (error) {
        process.stderr.write(`\n❌ Turnstile build check failed: ${error}\n\n`);
        return false;
    }

    if (isTurnstileRequired(environment)) {
        process.stdout.write('✅ Turnstile build check passed (backend/public site keys match).\n');
    }

    return true;
}

const scriptPath = process.argv[1] ? resolve(process.argv[1]) : '';
if (scriptPath === fileURLToPath(import.meta.url) && !checkTurnstileBuild(process.env)) {
    process.exitCode = 1;
}
