// @vitest-environment node
import assert from 'node:assert/strict';
import {existsSync, readFileSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {describe, it} from 'vitest';

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = resolve(frontendRoot, 'public');
const paths = ['sw.js', 'steuern/sw.js', 'steuern/manifest.webmanifest'];

const read = (relativePath) => readFileSync(resolve(publicRoot, relativePath), 'utf8');
const portalWorker = read('sw.js');
const steuernWorker = read('steuern/sw.js');
const manifest = JSON.parse(read('steuern/manifest.webmanifest'));

describe('PWA assets', () => {
    it('ships both service workers and the EAR manifest', () => {
        for (const path of paths) {
            assert.ok(existsSync(resolve(publicRoot, path)), `${path} must exist`);
        }
    });

    it('claims clients in both service workers', () => {
        assert.match(portalWorker, /clients\.claim\(\)/);
        assert.match(steuernWorker, /clients\.claim\(\)/);
    });

    it('never caches /api in either service worker', () => {
        for (const [name, source] of [['portal', portalWorker], ['steuern', steuernWorker]]) {
            assert.match(source, /pathname\.startsWith\('\/api\/'\)/, `${name} must exclude /api`);
            assert.doesNotMatch(source, /cache\.(put|add|addAll)\([^)]*\/api/i, `${name} must not cache /api`);
            assert.doesNotMatch(source, /\/api[^\n]*cache\.(put|add|addAll)/i, `${name} must not cache /api`);
        }
    });

    it('uses distinct versioned cache prefixes', () => {
        assert.match(portalWorker, /const PREFIX = 'rp-portal-'/);
        assert.match(steuernWorker, /const PREFIX = 'rp-steuern-'/);
        assert.match(portalWorker, /shell-v1/);
        assert.match(portalWorker, /assets-v1/);
        assert.match(steuernWorker, /shell-v1/);
        assert.match(steuernWorker, /assets-v1/);
    });

    it('scopes the bookkeeping manifest to /steuern', () => {
        assert.equal(manifest.name, 'Einnahmen Ausgaben Rechnung');
        assert.equal(manifest.short_name, 'EA Rechnung');
        assert.equal(manifest.start_url, '/steuern');
        assert.equal(manifest.scope, '/steuern');
        assert.equal(manifest.theme_color, '#1E5631');
        for (const icon of manifest.icons) {
            assert.ok(existsSync(resolve(publicRoot, icon.src.slice(1))), `${icon.src} must exist`);
        }
    });
});
