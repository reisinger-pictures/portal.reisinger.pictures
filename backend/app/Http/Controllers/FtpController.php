<?php

namespace App\Http\Controllers;

use App\Http\Resources\GalleryResource;
use App\Models\Gallery;
use App\Models\Photo;
use App\Services\AuthorizationService;
use App\Services\PhotoProcessingService;
use App\Values\FtpConnectionDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FtpController extends Controller
{
    /**
     * Lifetime of the per-photographer import lock, in seconds.
     *
     * This is a hard ceiling, not a lease renewal: the guard is lost once it
     * expires. It is therefore set far above a realistic import (ExifTool per
     * file, one file copy and one DB transaction each), because the failure
     * mode of a too-short TTL is a duplicated photo, while the failure mode of a
     * too-long TTL is only that a photographer who hit a PHP fatal waits for
     * the lock to expire instead of retrying immediately.
     */
    private const IMPORT_LOCK_TTL_SECONDS = 300;

    /**
     * Cache-lock name for one photographer's import run.
     *
     * Keyed by the user id, which is a globally unique UUID: the inbox folder is
     * user-level too, so this is the narrowest key that still covers exactly the
     * files the run can touch. A brand component would add nothing while the
     * Brand enum has a single case, and the folder namespace is deliberately not
     * brand-scoped yet (features/infrastructure/19-ftp-upload-pipeline.md §7.11).
     */
    public static function importLockName(string $userId): string
    {
        return 'ftp-process:'.$userId;
    }

    public function __construct(
        private readonly PhotoProcessingService $photoService,
    ) {}

    /**
     * The photographer's view of their own FTP state.
     *
     * The three provisioning fields come from the columns of V041 and are read
     * **without contacting SFTPGo**, per feature doc 7.4/7.5: a live query in a
     * read path would make this endpoint — and with it the UI — depend on a
     * service that may be down, and would need a credential for a read. So the
     * columns are the source of truth and are a cache, not a live view: they can
     * be stale if the account is deleted by hand in SFTPGo, which is why
     * reconciliation is an explicit path (`reconcileAccount()`, P1-M30) instead
     * of a query hidden in here.
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
     */
    public function status()
    {
        $user = auth('api')->user();
        $inboxPath = $this->getInboxPath($user);

        $fileCount = 0;
        if (is_dir($inboxPath)) {
            $files = glob($inboxPath.'/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
            $fileCount = count($files);
        }

        $user->load('currentFtpGallery');

        return response()->json([
            'ftp_folder' => '/'.($user->ftp_slug ?? $user->id),
            'file_count' => $fileCount,
            'current_target_gallery' => $user->currentFtpGallery ? new GalleryResource($user->currentFtpGallery) : null,
            'ftp_account_status' => $user->ftp_account_status,
            'ftp_provisioned_at' => $user->ftp_provisioned_at,
            'ftp_account_error' => $user->ftp_account_error,
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
     * Triggers the FTP import for the calling photographer.
     *
     * Concurrency guard (P1-M28). The pipeline is read → copy → Photo row →
     * unlink over a directory the FTP server writes into at the same time. Two
     * runs can each take their own `glob()` snapshot of the same file, and the
     * loser of the unlink race would still have produced a second Photo row and
     * a second stored file under a different UUID. The "single-user access"
     * assumption in
     * features/infrastructure/19-ftp-upload-pipeline.md §6 stopped holding once
     * several photographers import in parallel, so it is replaced by a
     * per-photographer cache lock.
     *
     * What it protects: the whole read → copy → metadata → Photo row → unlink
     * sequence of one photographer, for as long as this deployment's cache store
     * is shared. A competing call is answered with 409 and imports nothing.
     *
     * What it does NOT protect — deliberately not claimed:
     * - It is not a lock across a system boundary. The FTP/SFTP server writes
     *   into the same directory and takes no protocol-level lock, so a file a
     *   camera is still writing can be read half-finished. Keeping uploads and
     *   imports apart is the photographer's workflow, not this guard's job.
     * - It is not global. Its reach is exactly the reach of the configured cache
     *   store. A deployment that gives every node its own file cache degrades it
     *   to a per-node best effort.
     * - It is not a renewed lease, see IMPORT_LOCK_TTL_SECONDS. A run that
     *   outlives the TTL loses the guard; a PHP fatal releases it only when the
     *   TTL expires.
     * - It does not merge or queue requests. The rejected call does no work at
     *   all; the photographer sees "an import is already running" and retries.
     */
    public function process(Request $request)
    {
        $user = auth('api')->user();
        $svc = app(AuthorizationService::class);
        if (! $svc->isPhotographer($user)) {
            return response()->json(['error' => 'Nur Fotografen dürfen den FTP-Import anstoßen.'], 403);
        }
        if (! $user->current_ftp_gallery_id) {
            return response()->json(['error' => 'Keine Ziel-Galerie ausgewählt.'], 400);
        }

        $allowedIds = $user->getAllowedGalleryIds();
        if (! in_array($user->current_ftp_gallery_id, $allowedIds)) {
            return response()->json(['error' => 'Die Ziel-Galerie gehört nicht zu deiner Marke.'], 403);
        }

        $gallery = Gallery::find($user->current_ftp_gallery_id);
        $inboxPath = $this->getInboxPath($user);

        if (! is_dir($inboxPath)) {
            return response()->json(['success' => true, 'processed' => 0]);
        }

        $imageFiles = glob($inboxPath.'/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
        if (empty($imageFiles)) {
            return response()->json(['success' => true, 'processed' => 0]);
        }

        // Acquired only once there is work to do, so an idle photographer never
        // blocks their own next import. Non-blocking on purpose: a second run
        // must be told that it is too early instead of silently waiting and then
        // racing the first one through the very same files.
        $lock = Cache::lock(self::importLockName($user->id), self::IMPORT_LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            return response()->json([
                'error' => 'Ein Import läuft bereits. Bitte warte, bis er abgeschlossen ist.',
            ], 409, ['Retry-After' => (string) self::IMPORT_LOCK_TTL_SECONDS]);
        }

        try {
            return $this->runImport($user, $gallery, $imageFiles);
        } finally {
            $lock->release();
        }
    }

    /**
     * Imports the given inbox files into the target gallery.
     *
     * The caller must hold the import lock of `process()`. The sequence per file
     * is read → copy to the photo disk → Photo row in a transaction → unlink the
     * inbox copy, so a failure leaves the source file in place for the next run
     * (features/infrastructure/19-ftp-upload-pipeline.md §6).
     *
     * @param  list<string>  $imageFiles
     */
    private function runImport($user, Gallery $gallery, array $imageFiles): JsonResponse
    {
        $processedCount = 0;

        foreach ($imageFiles as $file) {
            // `glob()` is a snapshot taken before this loop. A file that vanished
            // in between was already claimed by a competing run, so it is
            // skipped instead of imported a second time under a new UUID.
            if (! file_exists($file)) {
                continue;
            }

            $originalName = pathinfo($file, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'jpeg') {
                $extension = 'jpg';
            }

            // Dateinamen IMMER aus UUID generieren!
            $photoId = (string) Str::uuid();
            $filename = $photoId.'.'.$extension;

            $targetDir = (string) $gallery->id;
            $targetRelativePath = $targetDir.'/'.$filename;
            $thumbsDir = $targetDir.'/_thumbs';

            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException('FTP-Quelldatei konnte nicht gelesen werden.');
            }

            if (! Storage::disk('photos')->put($targetRelativePath, $contents)) {
                throw new \RuntimeException('Foto konnte nicht in den Photo-Speicher geschrieben werden.');
            }

            $targetPath = Storage::disk('photos')->path($targetRelativePath);
            $thumbPath = Storage::disk('photos')->path($thumbsDir.'/'.md5($filename.'1024').'.webp');

            if (! is_dir(dirname($thumbPath))) {
                mkdir(dirname($thumbPath), 0755, true);
            }

            $meta = $this->photoService->processImage($targetPath, $thumbPath, $gallery);
            if (empty($meta['title'])) {
                $meta['title'] = $originalName; // Dateiname als Titel retten
            }

            DB::transaction(function () use ($gallery, $meta, $photoId, $user) {
                // `id` is not fillable (it is the stored file name), and
                // `captured_at` is EXIF-derived rather than client input — both
                // are written deliberately by the explicit creation API.
                Photo::createWithId(
                    array_merge([
                        'gallery_id' => $gallery->id,
                        'lr_uuid' => 'ftp-'.uniqid(),
                        'user_id' => $user->id,
                    ], $meta),
                    $photoId,
                );
            });

            if (file_exists($file) && ! unlink($file)) {
                throw new \RuntimeException('FTP-Quelldatei konnte nicht gelöscht werden.');
            }

            $processedCount++;
        }

        return response()->json(['success' => true, 'processed' => $processedCount]);
    }

    private function getInboxPath($user)
    {
        $folder = $user->ftp_slug ?? $user->id;
        $root = Storage::disk('ftp_inbox')->path((string) $folder);

        // Der Zielordner aus den Verbindungsdaten ist der Unterordner, den die
        // Kamera beschreibt. '/' bedeutet das Konto-Wurzelverzeichnis selbst.
        // Ohne diesen Zusatz importierte das Portal nichts mehr, sobald ein
        // Zielordner konfiguriert ist: die Kamera laedt dann in einen Ordner,
        // den der Import nie liest — und das saehe wie ein leerer Posteingang
        // aus, nicht wie ein Fehler.
        $uploadPath = FtpConnectionDetails::resolvedUploadPath();

        if ($uploadPath === FtpConnectionDetails::UPLOAD_PATH) {
            return $root;
        }

        return $root.$uploadPath;
    }
}
