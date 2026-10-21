<?php

namespace App\Http\Controllers;

use App\Enums\FtpImportStatus;
use App\Http\Resources\GalleryResource;
use App\Jobs\ImportFtpBatch;
use App\Services\AuthorizationService;
use App\Services\FtpCredentialService;
use App\Services\FtpInboxImporter;
use App\Services\PhotoProcessingService;
use App\Values\FtpConnectionDetails;
use Illuminate\Http\Request;

/**
 * The photographer-facing FTP inbox: state, target gallery, import trigger.
 *
 * The import itself no longer lives here. It is `FtpInboxImporter`, because the
 * HTTP trigger and the background chain of D-36 (`ImportFtpBatch`) must run the
 * same budgets, the same staging and the same cleanup — two copies would drift,
 * and the drift would only be visible as a photo that survived a failure.
 *
 * What stays here is the HTTP contract: the status payload, the target setter,
 * and the mapping from an `FtpImportStatus` to a status code and a German
 * message. The protected seams below (`countInboxImages()`, `statSource()`,
 * `openImportSourceStream()`) are handed to the importer as callables, so the
 * SEC-14 suite can still drive the real code path from a subclass of this
 * controller instead of a re-implementation (see `FtpImportTest`).
 */
class FtpController extends Controller
{
    /**
     * Built lazily, from the same `PhotoProcessingService` instance the
     * container injected here.
     *
     * Resolving the importer through the container instead would be a second
     * resolution of `PhotoProcessingService`: in the tests the mock is bound in
     * the container, so both paths happen to agree — but only one of them is
     * guaranteed to, and the import must use the service this controller was
     * built with.
     */
    private ?FtpInboxImporter $importer = null;

    public function __construct(
        private readonly PhotoProcessingService $photoService,
    ) {}

    /**
     * Cache-lock name for one photographer's import run (P1-M28).
     *
     * Kept on the controller because it is part of the observable contract: the
     * SEC-14 suite acquires this exact lock to simulate a competing run, and the
     * key is what makes the guard per photographer. The implementation lives
     * with the import in `FtpInboxImporter`.
     */
    public static function importLockName(string $userId): string
    {
        return FtpInboxImporter::importLockName($userId);
    }

    /**
     * The photographer's view of their own FTP state.
     *
     * The three provisioning fields come from the columns of V041 and are read
     * **without contacting SFTPGo**, per feature doc 7.4/7.5: a live query in a
     * read path would make this endpoint — and with it the UI — depend on a
     * service that may be down, and would need a credential for a read. So the
     * columns are the source of truth and are a cache, not a live view: they can
     * be stale if the account is deleted by hand in SFTPGo, which is why
     * reconciliation is an explicit path (`php artisan ftp:reconcile`,
     * `FtpCredentialService::reconcileAccount()`, P1-M30) instead of a query
     * hidden in here.
     *
     * The consequence is what P1-M26 asks for: an empty inbox is no longer the
     * only thing the photographer can see. `pending` says "not provisioned yet"
     * and `error` carries the reason, instead of both looking like an empty
     * folder. No 500er on this path is possible from a service outage, because
     * there is no service call — the worst case is a stale column.
     *
     * The provisioning columns are deliberately absent from the User model's
     * `$visible` (they are system state, not part of the user payload), so they
     * are read here as attributes and added to this response explicitly. The
     * response is the contract the frontend reads; the user payload is not.
     *
     * `quarantine_count` is additive (D-37): it is the number of files the
     * import moved aside because it could not process them. It is a separate
     * field rather than part of `file_count` because the two mean different
     * things — "waiting to be imported" and "already tried, needs a human" — and
     * a photographer who sees only `file_count` would read a quarantined
     * upload as "nothing arrived".
     */
    public function status()
    {
        $user = auth('api')->user();
        $inboxPath = $this->importer()->inboxPath($user);

        $fileCount = 0;
        if (is_dir($inboxPath)) {
            $fileCount = $this->countInboxImages($inboxPath);

            // A directory that exists but cannot be opened is a host/permission
            // fault, not an empty inbox. Reporting 0 would make a broken mount
            // look like "nothing uploaded", and `count()` on the old `glob()`
            // result turned the same fault into a TypeError — a 500 with no
            // usable message. The endpoint is polled, so it answers with a
            // named error instead.
            if ($fileCount === null) {
                return response()->json([
                    'error' => 'Der FTP-Posteingang konnte nicht gelesen werden.',
                ], 500);
            }
        }

        $user->load('currentFtpGallery');

        return response()->json([
            'ftp_folder' => '/'.($user->ftp_slug ?? $user->id),
            'file_count' => $fileCount,
            'quarantine_count' => $this->importer()->quarantineCount($inboxPath),
            'current_target_gallery' => $user->currentFtpGallery ? new GalleryResource($user->currentFtpGallery) : null,
            'ftp_account_status' => $user->ftp_account_status,
            'ftp_provisioned_at' => $user->ftp_provisioned_at,
            'ftp_account_error' => $user->ftp_account_error,
            // The hourly credential-reset quota, read from the constant the guard
            // itself uses rather than from a literal. The camera setup guide quotes
            // this number to the photographer ("Es sind n Anforderungen pro Stunde
            // möglich"), and that copy is what goes stale when the rule changes: the
            // quota was raised from three to ten on 2026-09-27 while the guide still
            // said three. Exposing the value here makes the guide a reader of the
            // rule instead of a second copy of it, so the two cannot disagree. A
            // constant is the right source for the same reason it is a constant and
            // not config: this is a security rule, and the UI may describe it but
            // must never define it.
            'ftp_reset_limit_per_hour' => FtpCredentialService::RESET_LIMIT_PER_HOUR,
            // What a camera has to be configured with. Read from the same
            // variables the compose publishes, so the UI cannot show a port
            // the container does not listen on, and it carries no secret — the
            // password stays behind the show-once endpoint.
            'connection' => FtpConnectionDetails::forUser($user)->toArray(),
        ]);
    }

    public function setTarget(Request $request)
    {
        $request->validate(['gallery_id' => 'nullable|string|exists:galleries,id']);
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);

        if ($request->gallery_id && ! $svc->isPhotographer($user)) {
            return response()->json(['error' => 'Keine Rechte für diese Aktion.'], 403);
        }

        $allowedIds = $user->getAllowedGalleryIds();
        if ($request->gallery_id && ! in_array($request->gallery_id, $allowedIds)) {
            return response()->json(['error' => 'Diese Galerie gehört nicht zu deiner Marke.'], 403);
        }

        $user->update(['current_ftp_gallery_id' => $request->gallery_id]);

        return response()->json(['success' => true]);
    }

    /**
     * Triggers one FTP import run for the calling photographer.
     *
     * This is the synchronous entry point: the photographer starts it from the
     * inbox card, and it imports exactly one batch (D-35). The same run is what
     * `ImportFtpBatch` performs in the background after an SFTPGo upload event
     * (D-36) — both call `FtpInboxImporter::importBatch()`.
     *
     * The HTTP contract this method owns:
     *
     * - **200** `{success, processed, quarantined}`. `processed` is the number of
     *   photos written; a batch limit is not an error, the remainder waits for
     *   the next run (D-35). `quarantined` counts files that were moved aside by
     *   D-37 after repeated failure — additive, and the reason the response can
     *   report a successful run that also had to give up on a file.
     * - **413** when a single file is above `ftp.import.max_file_bytes` (until
     *   D-37 quarantines it) or a first file is above the whole-run budget. Both
     *   abort the run before any file is opened: the message names the size, the
     *   limit and the variable to raise.
     * - **422** when a source changed while it was read, or a staged file is not
     *   a decodable image. The first is the documented "the camera is still
     *   writing" case and carries no `Retry-After`.
     * - **409** with `Retry-After` when another run of this account holds the
     *   lock. 409 has exactly this one meaning here, so a client can tell
     *   "another run is active" (retry after `Retry-After`) from "your upload is
     *   still writing" (422, no header).
     *
     * What the lock does NOT protect is documented in full in
     * `features/infrastructure/19-ftp-upload-pipeline.md` §3.3.2: it is not a
     * lock across a system boundary, its reach is the configured cache store,
     * and it is not a renewed lease.
     */
    public function process(Request $request)
    {
        $user = auth('api')->user();
        $result = $this->importer()->importBatch(
            $user,
            openSource: fn (string $path) => $this->openImportSourceStream($path),
            statSource: fn (string $path) => $this->statSource($path),
        );

        if ($result->eventHandoff) {
            // The manual request owned the lock when the upload event arrived.
            // Hand it to the bounded background chain only after importBatch()
            // has released the lock; dispatching earlier would recurse into a
            // competing job that can only return Locked.
            ImportFtpBatch::dispatch($user->id);
        }

        return match ($result->status) {
            FtpImportStatus::Imported, FtpImportStatus::Empty => response()->json([
                'success' => true,
                'processed' => $result->imported,
                'quarantined' => $result->quarantined,
            ]),
            FtpImportStatus::NotPhotographer,
            FtpImportStatus::TargetNotAllowed => response()->json(['error' => $result->error], 403),
            FtpImportStatus::NoTarget,
            FtpImportStatus::GalleryMissing => response()->json(['error' => $result->error], 400),
            FtpImportStatus::UnreadableInbox => response()->json(['error' => $result->error], 500),
            FtpImportStatus::LimitExceeded => response()->json(['error' => $result->error], 413),
            FtpImportStatus::SourceChanged,
            FtpImportStatus::SourceUnprocessable => response()->json(['error' => $result->error], 422),
            FtpImportStatus::Locked => response()->json(
                ['error' => $result->error],
                409,
                ['Retry-After' => (string) FtpInboxImporter::IMPORT_LOCK_TTL_SECONDS],
            ),
        };
    }

    /**
     * Count the inbox image files, delegating to the importer.
     *
     * Protected so a test can simulate a directory that exists but cannot be
     * opened: the suite runs as root in CI, where a `chmod` fixture is ignored
     * and would make the test green for the wrong reason.
     *
     * @return int|null Null when the directory exists but cannot be opened.
     */
    protected function countInboxImages(string $inboxPath): ?int
    {
        return $this->importer()->countInboxImages($inboxPath);
    }

    /**
     * The seam through which the import opens a source file.
     *
     * A subclass can make a source grow at the exact moment it is read, which is
     * the only way to reach the bounded-read rejection without a race against a
     * real camera.
     *
     * @return resource|false
     */
    protected function openImportSourceStream(string $path)
    {
        return $this->importer()->openSource($path);
    }

    /**
     * Fresh existence and size probe for one source file.
     *
     * A subclass can hand in the exact symptom of PHP's stale stat cache
     * (`present` true, `size` false) without depending on OS-level cache
     * behaviour, which is not reproducible on every host. The *order* inside the
     * importer (cache invalidation before the probe) is pinned separately by
     * `FtpStatCacheOrderTest`.
     *
     * @return array{present: bool, size: int|false}
     */
    protected function statSource(string $path): array
    {
        return $this->importer()->statSource($path);
    }

    private function importer(): FtpInboxImporter
    {
        return $this->importer ??= new FtpInboxImporter($this->photoService);
    }
}
