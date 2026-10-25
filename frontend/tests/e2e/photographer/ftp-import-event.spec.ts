import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { GalleryHelper } from '../helpers/GalleryHelper';
import { browserAuthHeadersFromPage } from '../helpers/BrowserAuthHeaders';

/**
 * D-36, owner request 2026-10-25: "a file that arrives through the SFTPGo
 * upload event must appear in the target gallery without any button press".
 *
 * ## What this spec proves — and what it does not
 *
 * It proves the **upload event → import → target gallery** path against the
 * running backend: a JPEG is sitting in the photographer's inbox, the internal
 * event endpoint is called with the payload SFTPGo sends, and the photo is in
 * the gallery afterwards. It does **not** prove the **FTPS transport**: no
 * camera connects, no TLS session is negotiated, and SFTPGo delivers nothing.
 * That half is covered by `tests/scripts/ftp-transport-test/` and is not
 * duplicated here — which is also why this spec is not named after the
 * transport.
 *
 * The event is sent by the spec itself, and that is not a shortcut: in the CI
 * job the SFTPGo service container has its own network namespace, so the only
 * address a provisioned event rule could use (`127.0.0.1:8000`) is that
 * container's own loopback rather than the job container's backend. A rule
 * pointing there could never fire. The spec therefore plays both halves of
 * SFTPGo's role — drop the file, post the event — which is exactly the contract
 * D-36 defines for the portal side and the only honest way to exercise it here.
 *
 * ## Why setup goes through the API and the browser never leaves the gallery
 *
 * The FTP inbox card on the dashboard starts a catch-up import when it mounts
 * and finds files waiting (D-36: opening the page also imports). Visiting the
 * dashboard between the drop and the assertion would let that catch-up run hide
 * a broken event path, and the spec would pass for the wrong reason. So the
 * slug and the target gallery are written through the product's own endpoints
 * (the profile form's `PUT /api/auth/profile` and the inbox card's
 * `POST /api/management/ftp/target`), and the browser only ever renders the
 * gallery. After the drop, the event is the only possible trigger.
 *
 * `QUEUE_CONNECTION=sync` in both E2E stacks is what makes this deterministic:
 * `ImportFtpBatch::dispatch()` runs inside the event request, so the 200 comes
 * back after the import finished and nothing below races a worker. Under a real
 * queue this spec would need a worker to be running.
 *
 * @feature:ftp-import
 */

/**
 * The inbox root both E2E stacks configure.
 *
 * `scripts/e2e-up.sh` and the "Prepare backend environment" step of the E2E job
 * in `.github/workflows/ci.yml` both set `FTP_STORAGE_PATH` to this absolute
 * path, because `App\Support\FtpInboxDirectory::root()` throws on a relative
 * one and the pipeline would not run at all. The job exposes the same value to
 * the test process, so `FTP_STORAGE_PATH` is normally set; the fallback exists
 * so a local run against the documented stack works without exporting it.
 */
const DEFAULT_FTP_INBOX_ROOT = '/tmp/portal-e2e-ftp';

/**
 * The shared secret of the internal endpoint (`config('ftp.events.secret')`).
 *
 * The header name is a constant in `config/ftp.php` (`X-FTP-Event-Secret`), the
 * value is not: both stacks use this dummy, and the E2E job passes it to the
 * backend in its `env:` block. A different deployment value wins through
 * `FTP_EVENT_SECRET` in the test process — the backend and the spec then read
 * the same variable, so they cannot silently disagree.
 */
const DEFAULT_FTP_EVENT_SECRET = 'e2e-local-dummy-event-secret-2026';

/**
 * `FtpEventController::UPLOAD_ACTIONS` accepts `upload` and `first-upload`;
 * `upload` is what `ftp:provision-events` subscribes the rule to. The spec sends
 * the same action the provisioned rule sends for an ordinary file.
 */
const UPLOAD_EVENT_ACTION = 'upload';

/** A valid JPEG. Same fixture every upload spec in this suite uses. */
const SAMPLE_JPEG = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');

type UploadEventResponse = {
    accepted?: boolean;
    dispatched?: boolean;
    reason?: string;
};

type FtpStatusResponse = {
    file_count?: number;
    quarantine_count?: number;
    error?: string;
};

const ftpEventSecret = (): string => {
    const configured = (process.env.FTP_EVENT_SECRET ?? '').trim();

    return configured === '' ? DEFAULT_FTP_EVENT_SECRET : configured;
};

test.describe('FTP upload event imports into the target gallery', () => {
    let helper: E2ESessionHelper;
    let inboxDirectory = '';

    test.afterEach(async () => {
        // The importer moves the file; anything left behind is this run's own
        // fixture (a failed assertion above it), so it goes with the test.
        if (inboxDirectory !== '') rmSync(inboxDirectory, {recursive: true, force: true});
        if (helper) await helper.teardown();
    });

    test('a file dropped into the inbox reaches the gallery without a click', { tag: ['@feature:ftp-import'] }, async ({ page, request }) => {
        // The backend resolves its inbox from this value; a relative one makes
        // `FtpInboxDirectory::root()` throw, so a silent mismatch here would
        // look like "the import does not work".
        const configuredRoot = (process.env.FTP_STORAGE_PATH ?? '').trim();
        const inboxRoot = configuredRoot === '' ? DEFAULT_FTP_INBOX_ROOT : configuredRoot;
        expect(
            path.isAbsolute(inboxRoot),
            `FTP_STORAGE_PATH must be absolute — App\\Support\\FtpInboxDirectory::root() throws on a relative root, `
            + `so the import cannot run at all. Got "${inboxRoot}".`,
        ).toBe(true);

        const unique = Math.random().toString(36).substring(2, 10);
        // Matches FtpSlug::PATTERN (^[a-z0-9][a-z0-9_-]{2,31}$) and is unique per
        // run, so no other spec's inbox or account can be touched.
        const ftpSlug = `e2e-ftp-${unique}`;
        const photoBaseName = `ftp-event-${unique}`;

        // The account's inbox directory, created BEFORE the slug is written.
        // In the deployment its parent is the `ftp/` bind mount with the setgid
        // bit (D-1/D-2). The E2E job has no such mount and its workflow runs no
        // `ftp:provision-folders`, so the spec builds the tree the camera would
        // upload into. The order is not cosmetic: the slug write runs
        // `FtpInboxDirectory::ensure()`, which creates `ftp/<slug>` with a
        // non-recursive `mkdir()` and refuses to store a slug without its
        // directory — without this line the profile write fails closed with 503.
        // It stays idempotent: `ensure()` keeps a directory that already exists.
        inboxDirectory = path.join(inboxRoot, ftpSlug);
        mkdirSync(inboxDirectory, { recursive: true });

        // The fixture helper keeps its own cookie jar (admin session + created
        // users/galleries for teardown); the browser session stays untouched.
        helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);

        // 1. The real photographer creates the gallery through the real modal.
        //    `GalleryHelper` reloads and opens it, so the browser now sits on the
        //    gallery page and stays there for the rest of the test.
        await auth.login(testUser.email, testUser.password);
        const galleryId = await galleryHelper.createAndOpenDeliveryGallery(`FTP Import ${unique}`);
        if (!galleryId) throw new Error('Die Galerie wurde ohne id angelegt; der Ziel-Setter braucht sie.');

        const main = page.getByRole('main');
        // The starting state is what makes the final assertion meaningful: this
        // gallery is empty and has exactly one way to gain a photo from here.
        await expect(main.getByText('Noch keine Bilder vorhanden')).toBeVisible();

        // Setup calls run as the browser session itself (`page.request` shares the
        // browser context's cookies), so no second login can invalidate it.
        const setupHeaders = {
            'Accept': 'application/json',
            ...await browserAuthHeadersFromPage(page),
        };

        // 2. The FTP login, through the endpoint the profile form submits to.
        //    This provisions the camera account, and `FtpInboxDirectory::ensure()`
        //    creates `ftp/<slug>` — the directory the camera would upload into.
        const profileResponse = await page.request.put('/api/auth/profile', {
            data: { name: `E2E FTP ${unique}`, ftp_slug: ftpSlug },
            headers: setupHeaders,
        });
        const profileBody = await profileResponse.text();
        expect(
            profileResponse.ok(),
            `Der FTP-Login konnte nicht gesetzt werden (${profileResponse.status()}): ${profileBody}. `
            + 'Dieser Pfad provisioniert den Kamera-Account und braucht einen erreichbaren SFTPGo (D-51).',
        ).toBe(true);

        // 3. The target gallery, through the endpoint the inbox card's "Setzen"
        //    button calls. This is D-36's precondition: no target, no import.
        const targetResponse = await page.request.post('/api/management/ftp/target', {
            data: { gallery_id: galleryId },
            headers: setupHeaders,
        });
        const targetBody = await targetResponse.text();
        expect(
            targetResponse.ok(),
            `Die Ziel-Galerie konnte nicht gesetzt werden (${targetResponse.status()}): ${targetBody}`,
        ).toBe(true);

        // 4. Drop the file where SFTPGo would put it. The upload path is the
        //    account root unless FTP_UPLOAD_PATH declares a subfolder; the status
        //    check below is what proves the backend agrees about the location.
        const sourcePath = path.join(inboxDirectory, `${photoBaseName}.jpg`);
        writeFileSync(sourcePath, readFileSync(SAMPLE_JPEG));
        expect(existsSync(sourcePath), `Die Testdatei wurde nicht geschrieben: ${sourcePath}`).toBe(true);

        const statusBeforeResponse = await page.request.get('/api/management/ftp/status', { headers: setupHeaders });
        const statusBeforeText = await statusBeforeResponse.text();
        expect(statusBeforeResponse.ok(), `Der FTP-Status antwortet nicht (${statusBeforeResponse.status()}): ${statusBeforeText}`).toBe(true);
        const statusBefore = JSON.parse(statusBeforeText) as FtpStatusResponse;
        expect(
            statusBefore.file_count,
            `Der Backend-Posteingang sieht die Datei nicht: sie liegt in "${inboxDirectory}", der Endpunkt meldet `
            + `file_count=${String(statusBefore.file_count)}. Der Test schreibt in FTP_STORAGE_PATH="${inboxRoot}".`,
        ).toBe(1);

        // 5. The event, exactly as the provisioned SFTPGo rule sends it: the
        //    shared secret in `config('ftp.events.secret_header')`, the account
        //    name (`users.ftp_slug`) and the upload action. The import runs inside
        //    this request (`QUEUE_CONNECTION=sync`), so its budget is the request
        //    budget — hence the explicit timeout above the API default of 30s.
        const eventResponse = await page.request.post('/api/internal/ftp/upload-event', {
            headers: {
                'Accept': 'application/json',
                'X-FTP-Event-Secret': ftpEventSecret(),
            },
            data: { username: ftpSlug, action: UPLOAD_EVENT_ACTION },
            timeout: 60000,
        });
        const eventBodyText = await eventResponse.text();
        expect(
            eventResponse.status(),
            `Das Upload-Event wurde abgelehnt (${eventResponse.status()}): ${eventBodyText}`,
        ).toBe(200);
        const eventBody = JSON.parse(eventBodyText) as UploadEventResponse;
        // Strict: `unknown_account` and `no_target_gallery` are 200 too, and both
        // would leave the file exactly where it is.
        expect(eventBody).toEqual({ accepted: true, dispatched: true });

        // 6. The importer moves the file, it does not copy it — and the product's
        //    own inbox view agrees: nothing is waiting, nothing was quarantined.
        expect(
            existsSync(sourcePath),
            `Nach dem Import muss die Quelldatei aus dem Posteingang verschwunden sein: ${sourcePath}`,
        ).toBe(false);

        const statusAfterResponse = await page.request.get('/api/management/ftp/status', { headers: setupHeaders });
        const statusAfterText = await statusAfterResponse.text();
        expect(statusAfterResponse.ok(), `Der FTP-Status antwortet nicht (${statusAfterResponse.status()}): ${statusAfterText}`).toBe(true);
        const statusAfter = JSON.parse(statusAfterText) as FtpStatusResponse;
        expect(statusAfter.file_count, `Der Posteingang ist nach dem Import nicht leer: ${statusAfterText}`).toBe(0);
        expect(statusAfter.quarantine_count, `Der Import hat die Datei quarantineiert statt importiert: ${statusAfterText}`).toBe(0);

        // 7. The gallery, reloaded — that reload is the only thing the user does
        //    between the upload and the photo. No import button, no dashboard.
        await page.reload();
        const photoCards = main.locator('a.pswp-item');
        await expect(photoCards).toHaveCount(1);
        await expect(main.locator('a.pswp-item img').first()).toBeVisible();
        // The FTP import falls back to the source filename when the image carries
        // no title, so the card always has one.
        await expect(photoCards.first()).toHaveAttribute('data-title', /\S/);
        await expect(main.getByText('Noch keine Bilder vorhanden')).toHaveCount(0);
    });
});
