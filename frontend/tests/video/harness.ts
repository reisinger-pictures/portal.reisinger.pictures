// Shared infrastructure for the demo-video category.
//
// Each spec in this directory owns its own STORY. This module only provides
// the parts every promo needs: a login that is not recorded, a recording whose
// boot phase is trimmed away at publish time, a strict "the real image is
// loaded" assertion, and a publish-on-success contract. A new promo with a
// different flow is a new spec file — no change to this harness or to another
// promo is required.
import { spawn, spawnSync } from 'node:child_process';
import { constants as fsConstants, promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { expect, type Browser, type BrowserContext, type Locator, type Page, type TestInfo } from '@playwright/test';
import { AuthHelper } from '../e2e/helpers/AuthHelper';

export const BASE_URL = 'http://localhost:4321';
export const VIEWPORT = { width: 1280, height: 720 };
/** Roughly 4x the former pacing — every action is meant to be readable. */
export const BEAT = 1200;
export const FIXTURES_DIR = path.resolve(process.cwd(), 'tests/video/fixtures');

/** Keep a settled frame after the loader waits before cutting the boot phase. */
const READY_GUARD_MS = 300;
const VIDEO_FPS = 25;

export const beat = (page: Page, ms = BEAT) => page.waitForTimeout(ms);

/** Remove a file/directory if present; `force: true` is reserved for Playwright. */
async function removeIfExists(target: string): Promise<void> {
    try {
        await fs.rm(target, { recursive: true });
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
    }
}

/** One output file per spec: promo-video.spec.ts -> promo-video.webm. */
export function videoTarget(testInfo: TestInfo): string {
    return path.resolve(process.cwd(), 'public/demo-videos', `${path.basename(testInfo.file, '.spec.ts')}.webm`);
}

/**
 * The image must really be rendered — not merely attached to the DOM. A broken
 * thumbnail has naturalWidth === 0 and is exactly what must fail this demo.
 */
export async function expectRealImage(page: Page, locator: Locator, what: string): Promise<void> {
    await expect(locator, `${what} ist nicht sichtbar`).toBeVisible({ timeout: 20000 });
    // SWR can replace the <img> node while the detail view settles. Retry the
    // whole scroll instead of holding a handle to a detached element.
    await expect(async () => {
        await locator.scrollIntoViewIfNeeded();
    }).toPass({ timeout: 15000 });

    const src = await locator.getAttribute('src') ?? '';
    let diagnostic = `src=${src}`;
    try {
        const response = await page.request.get(src, { timeout: 10000 });
        diagnostic += ` http=${response.status()} body=${(await response.text()).slice(0, 200)}`;
    } catch (error) {
        diagnostic += ` request-error=${String(error)}`;
    }

    await expect
        .poll(() => locator.evaluate(el => (el as HTMLImageElement).complete), {
            timeout: 20000,
            message: `${what}: Bild-Laden nicht abgeschlossen (${diagnostic})`,
        })
        .toBe(true);
    await expect
        .poll(() => locator.evaluate(el => (el as HTMLImageElement).naturalWidth), {
            timeout: 20000,
            message: `${what}: Bild ist kaputt (naturalWidth = 0) — ${diagnostic}`,
        })
        .toBeGreaterThan(0);
}

async function isExecutable(file: string): Promise<boolean> {
    try {
        await fs.access(file, fsConstants.X_OK);
        return true;
    } catch {
        return false;
    }
}

function onPath(command: string): boolean {
    const probe = spawnSync(command, ['-version'], { stdio: 'ignore' });
    return !probe.error && probe.status === 0;
}

/** Fallback for hosts without a system ffmpeg: Playwright's bundled binary. */
async function bundledFfmpeg(): Promise<string | undefined> {
    const binary = process.platform === 'win32'
        ? 'ffmpeg-win64.exe'
        : process.platform === 'darwin' ? 'ffmpeg-mac' : 'ffmpeg-linux';
    const root = process.env.PLAYWRIGHT_BROWSERS_PATH?.trim()
        || path.join(os.homedir(), '.cache', 'ms-playwright');
    let entries;
    try {
        entries = await fs.readdir(root, { withFileTypes: true });
    } catch {
        return undefined;
    }
    const candidates = entries
        .filter(entry => entry.isDirectory() && entry.name.startsWith('ffmpeg-'))
        .map(entry => path.join(root, entry.name, binary))
        .sort()
        .reverse();
    for (const candidate of candidates) {
        if (await isExecutable(candidate)) return candidate;
    }
    return undefined;
}

async function resolveFfmpeg(): Promise<string> {
    const override = process.env.FFMPEG_PATH?.trim();
    if (override) {
        if (!await isExecutable(override)) throw new Error(`FFMPEG_PATH ist nicht ausführbar: ${override}`);
        return override;
    }
    if (onPath('ffmpeg')) return 'ffmpeg';
    const bundled = await bundledFfmpeg();
    if (bundled) return bundled;
    throw new Error('ffmpeg wurde nicht gefunden (FFMPEG_PATH setzen oder Playwright-Browser installieren)');
}

async function runFfmpeg(binary: string, args: string[]): Promise<void> {
    await new Promise<void>((resolve, reject) => {
        const child = spawn(binary, args, { stdio: ['ignore', 'ignore', 'pipe'] });
        let stderr = '';
        child.stderr?.on('data', (chunk: Buffer) => {
            stderr += chunk.toString();
        });
        child.on('error', reject);
        child.on('close', code => {
            if (code === 0) resolve();
            else reject(new Error(`ffmpeg (${binary}) endete mit Code ${code}: ${stderr.slice(-1000)}`));
        });
    });
}

/** Cut `seconds` from the start; re-encode so the cut is frame-accurate. */
async function trimBoot(rawPath: string, target: string, seconds: number): Promise<void> {
    const ffmpeg = await resolveFfmpeg();
    const trimmed = `${target}.tmp-${process.pid}.webm`;
    const args = [
        '-hide_banner', '-loglevel', 'error', '-y',
        '-i', rawPath,
        '-ss', seconds.toFixed(3),
        '-vf', `scale=${VIEWPORT.width}:${VIEWPORT.height}`,
        '-r', String(VIDEO_FPS),
        '-c:v', 'libvpx-vp9', '-b:v', '0', '-crf', '30',
        '-deadline', 'realtime', '-cpu-used', '8', '-an', trimmed,
    ];
    try {
        await runFfmpeg(ffmpeg, args);
        await fs.rename(trimmed, target);
    } finally {
        await removeIfExists(trimmed);
    }
}

export interface DemoRecording {
    /** The page recorded from this point on — the session is already logged in. */
    page: Page;
    /** Call exactly once at the end: `true` publishes, `false` discards. */
    finish: (published: boolean) => Promise<void>;
}

/**
 * Login in an unrecorded context, then measure the boot of the authenticated
 * app. The elapsed span is trimmed at publish time, so EVERY promo starts on
 * the loaded app — no white boot screen and no per-spec flag.
 */
export async function record(browser: Browser, testInfo: TestInfo, email: string, password: string): Promise<DemoRecording> {
    const rawDir = path.join(testInfo.outputDir, 'raw');
    const target = videoTarget(testInfo);
    await fs.mkdir(rawDir, { recursive: true });

    let context: BrowserContext | undefined;
    const discard = async () => {
        await context?.close().catch(() => undefined);
        await removeIfExists(rawDir);
        await removeIfExists(target);
    };

    try {
        const loginContext = await browser.newContext({ baseURL: BASE_URL, locale: 'de-DE', viewport: VIEWPORT });
        const loginPage = await loginContext.newPage();
        await new AuthHelper(loginPage).login(email, password);
        const cookies = await loginContext.cookies();
        await loginContext.close();

        context = await browser.newContext({
            baseURL: BASE_URL,
            locale: 'de-DE',
            viewport: VIEWPORT,
            storageState: { cookies, origins: [] },
            recordVideo: { dir: rawDir, size: VIEWPORT },
        });
        const startedAt = Date.now();
        const page = await context.newPage();
        const video = page.video();
        if (!video) throw new Error('Videoaufzeichnung konnte nicht gestartet werden');

        await page.goto('/'); // initial load of the already authenticated app
        await expect(page.getByTestId('app-loader-fallback')).toHaveCount(0, { timeout: 15000 });
        await expect(page.getByRole('main').first()).toBeVisible({ timeout: 15000 });
        await page.waitForTimeout(READY_GUARD_MS);
        const bootSeconds = (Date.now() - startedAt) / 1000;

        const finishContext = context;
        let finished = false;
        return {
            page,
            finish: async (published: boolean) => {
                if (finished) return;
                finished = true;
                await finishContext.close().catch(() => undefined);
                if (!published) {
                    await discard();
                    return;
                }
                try {
                    const rawPath = await video.path(); // available only after context.close()
                    await fs.mkdir(path.dirname(target), { recursive: true });
                    await trimBoot(rawPath, target, bootSeconds);
                    await removeIfExists(rawDir);
                } catch (error) {
                    await discard();
                    throw error;
                }
            },
        };
    } catch (error) {
        await discard();
        throw error;
    }
}
