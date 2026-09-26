import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
import process from 'node:process';
import {describe, expect, it} from 'vitest';
import {getTurnstileBuildError} from './check-turnstile-build.mjs';

const requiredEnvironment = {
    TURNSTILE_BUILD_REQUIRED: 'true',
    TURNSTILE_SITE_KEY: 'backend-site-key',
    VITE_TURNSTILE_SITE_KEY: 'backend-site-key'
};

describe('Turnstile production build check', () => {
    it('keeps Turnstile optional for an ordinary build with no keys', () => {
        expect(getTurnstileBuildError({})).toBeNull();
    });

    it('does not reject an optional build with differing backend/public keys', () => {
        expect(getTurnstileBuildError({
            TURNSTILE_BUILD_REQUIRED: 'false',
            TURNSTILE_SITE_KEY: 'backend-site-key',
            VITE_TURNSTILE_SITE_KEY: 'different-site-key'
        })).toBeNull();
    });

    it('rejects a required build without the backend public site key', () => {
        expect(getTurnstileBuildError({
            TURNSTILE_BUILD_REQUIRED: 'true',
            VITE_TURNSTILE_SITE_KEY: 'backend-site-key'
        })).toContain('TURNSTILE_SITE_KEY');
    });

    it('rejects a required build without the frontend public site key', () => {
        expect(getTurnstileBuildError({
            TURNSTILE_BUILD_REQUIRED: 'true',
            TURNSTILE_SITE_KEY: 'backend-site-key'
        })).toContain('VITE_TURNSTILE_SITE_KEY');
    });

    it('rejects a required build when backend and public site keys differ', () => {
        expect(getTurnstileBuildError({
            ...requiredEnvironment,
            VITE_TURNSTILE_SITE_KEY: 'different-site-key'
        })).toContain('backend/public site keys differ');
    });

    it('accepts a required build with matching backend/public site keys', () => {
        expect(getTurnstileBuildError(requiredEnvironment)).toBeNull();
    });

    it('never reads a server secret from the frontend build environment', () => {
        const environment = {
            ...requiredEnvironment,
            get TURNSTILE_SECRET() {
                throw new Error('Frontend build must not read TURNSTILE_SECRET');
            }
        };

        expect(getTurnstileBuildError(environment)).toBeNull();
    });

    it('ignores and never logs a server secret when one is present', () => {
        const secret = 'frontend-build-must-not-log-this';
        const script = resolve(process.cwd(), 'scripts/check-turnstile-build.mjs');
        const result = spawnSync(process.execPath, [script], {
            encoding: 'utf8',
            env: {
                ...process.env,
                ...requiredEnvironment,
                TURNSTILE_SECRET: secret
            }
        });

        expect(result.status, result.stderr).toBe(0);
        expect(result.stdout).not.toContain(secret);
        expect(result.stderr).not.toContain(secret);
    });
});
