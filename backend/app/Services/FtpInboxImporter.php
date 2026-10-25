<?php

namespace App\Services;

use App\Enums\FtpImportStatus;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Support\FtpInboxDirectory;
use App\Values\FtpConnectionDetails;
use App\Values\FtpImportResult;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One FTP inbox import run: select a batch, stage it, import it (D-35).
 *
 * This class exists so the HTTP entry point (`FtpController::process()`) and the
 * background chain (`ImportFtpBatch`) run **the same** code. The budgets, the
 * staging loop, the per-file transaction and the destination cleanup are here
 * exactly once; a second copy in the job would be the one that drifts, and the
 * difference would only show up as a photo that exists after a failure.
 *
 * ## Where the failure semantics live
 *
 * Everything below is pinned by the SEC-14 suite (`FtpImportTest`,
 * `FtpProcessConcurrencyTest`) and must not be re-implemented "simpler":
 *
 * - The whole batch is staged into `tmpfile()` streams **before** the first
 *   Photo row is written, so a rejection during staging leaves nothing behind.
 *   Validation of the staged bytes runs inside the import loop, so a rejection
 *   *there* does leave the photos imported before it in place — the docblock
 *   claim that no partial batch can exist was wrong for that second case and is
 *   corrected in `features/infrastructure/19-ftp-upload-pipeline.md` §6.
 * - Reading is hard-bounded at `budget + 1` bytes, so a source that grows after
 *   the size check becomes a 413 instead of an unbounded read.
 * - A source that changed under the run is 422; one that vanished counts as
 *   unchanged (see `sourceHasNotChanged()`), because the destructive direction
 *   is the wrong one.
 * - The run is serialized per photographer by `Cache::lock('ftp-process:{id}')`,
 *   acquired only once there is work and never blocking.
 *
 * ## Test seams
 *
 * `statSource()`, `openSource()` and their two protected halves exist because
 * the failure modes above cannot be produced reliably on every host: a `rm` in a
 * subprocess was measured to leave PHP's stat cache fresh here, and CI runs the
 * suite as root, where a `chmod` fixture is ignored. The controller hands its
 * own overridable seams in as callables (`importBatch()`), so the existing
 * SEC-14 subclasses of `FtpController` keep driving the real code path instead
 * of a re-implementation. The class is deliberately not `final` for the same
 * reason — the stat-cache *order* is pinned by a subclass that records the
 * probe sequence.
 */
class FtpInboxImporter
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
    public const IMPORT_LOCK_TTL_SECONDS = 300;

    public const IMPORT_COPY_CHUNK_BYTES = 64 * 1024;

    /**
     * Documented import limits (D-35, AGENTS.md §14).
     *
     * They live here and not in `config/ftp.php` because a config value can be
     * present but empty or malformed, and that case must fall back to exactly
     * these numbers instead of being coerced to 0 (which the old `(int)` cast in
     * the config file did). The config file therefore passes the raw env values
     * through and this class is the only place that decides what a usable value
     * is — and the only reader of `ftp.import.*`.
     */
    public const DEFAULT_MAX_FILES = 20;

    public const DEFAULT_MAX_FILE_BYTES = 20 * 1024 * 1024;

    public const DEFAULT_MAX_BATCH_BYTES = 100 * 1024 * 1024;

    /**
     * Hard ceiling of the self-chaining background import (D-36).
     *
     * 25 batches × 20 files = 500 files per trigger. The cap is a flood guard,
     * not a capacity statement: a trigger that would need more than that is
     * either a bulk import that belongs to a second event or a defect, and both
     * are better served by stopping with a log line than by an unbounded chain.
     */
    public const DEFAULT_CHAIN_MAX_BATCHES = 25;

    /**
     * How often one chain retries after an *exception* (D-36).
     *
     * Only a thrown failure counts: a batch that merely rejects a file (413/422)
     * imports nothing and must not re-enqueue, or the same file would be
     * selected forever. The counter lives in the job payload, so the bound is
     * per chain and survives a worker restart.
     */
    public const DEFAULT_CHAIN_MAX_FAILURES = 3;

    /**
     * How many failed attempts a single file needs before it is quarantined (D-37).
     *
     * Every rejection that means "this file cannot be processed as it is" —
     * above the per-file budget, not a decodable image, no readable size —
     * counts one attempt. A *changed* source does not count: that is the
     * documented "the camera is still writing, retry" case, and moving a file
     * that is still being written would quarantine a healthy upload. Three
     * attempts therefore tolerate a transient read error before the file leaves
     * the inbox; a deterministic defect reaches the quarantine on the third
     * trigger. Setting the value to 1 quarantines on the first failure.
     */
    public const DEFAULT_QUARANTINE_AFTER_ATTEMPTS = 3;

    /**
     * The subfolder next to the imported files that holds unimportable uploads.
     *
     * It sits **inside** the inbox directory on purpose: it is then in the
     * photographer's SFTP home (so it is discoverable at all), it inherits the
     * `ftp/<slug>` tree and its ownership rules (D-2), and the inbox walk can
     * never mistake it for a candidate — a directory carries no image extension
     * and is additionally skipped by name.
     */
    public const QUARANTINE_DIRECTORY = '_quarantine';

    /**
     * How long an attempt counter survives without a new attempt.
     *
     * The counter exists to distinguish "failed once" from "fails every time";
     * it is not an audit trail. A day is long enough that repeated manual clicks
     * and the automated triggers count together, and short enough that a file
     * re-uploaded later starts clean.
     */
    private const ATTEMPT_TTL_HOURS = 24;

    private const ATTEMPT_LOCK_TTL_SECONDS = 30;

    private const ATTEMPT_LOCK_WAIT_SECONDS = 5;

    private const EVENT_GENERATION_LOCK_TTL_SECONDS = 30;

    private const EVENT_GENERATION_LOCK_WAIT_SECONDS = 5;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'JPG', 'JPEG'];

    private const SOURCE_CHANGED_MESSAGE = 'Die FTP-Datei wurde während des Imports verändert — vermutlich lädt die Kamera noch. Warte, bis der Upload abgeschlossen ist, und starte den Import dann erneut.';

    private const SOURCE_UNPROCESSABLE_MESSAGE = 'Die FTP-Datei ist kein gültiges oder unterstütztes Bild.';

    private const UNREADABLE_INBOX_MESSAGE = 'Der FTP-Posteingang konnte nicht gelesen werden.';

    private const LOCKED_MESSAGE = 'Ein Import läuft bereits. Bitte warte, bis er abgeschlossen ist.';

    private const NO_TARGET_MESSAGE = 'Keine Ziel-Galerie ausgewählt.';

    private const GALLERY_MISSING_MESSAGE = 'Die Ziel-Galerie existiert nicht mehr.';

    private const TARGET_NOT_ALLOWED_MESSAGE = 'Die Ziel-Galerie gehört nicht zu deiner Marke.';

    private const NOT_PHOTOGRAPHER_MESSAGE = 'Nur Fotografen dürfen den FTP-Import anstoßen.';

    public function __construct(
        private readonly PhotoProcessingService $photoService,
    ) {}

    /**
     * Record an upload event before its job attempts the import lock.
     *
     * The generation is intentionally retained: a running import snapshots it
     * before selecting files and compares it after releasing the import lock.
     * Thus an event job that loses the lock cannot race past the active job's
     * final handoff check. One small cache value per account is preferable to a
     * marker that must be cleared with a check/forget race.
     */
    public function markUploadEvent(User $user): void
    {
        $key = self::uploadEventGenerationKey($user->id);
        $lock = Cache::lock($key.':lock', self::EVENT_GENERATION_LOCK_TTL_SECONDS);

        if ($lock->block(self::EVENT_GENERATION_LOCK_WAIT_SECONDS) !== true) {
            throw new \RuntimeException('FTP-Upload-Event konnte nicht dauerhaft markiert werden.');
        }

        try {
            $generation = $this->uploadEventGeneration($user->id) + 1;

            // Keep the generation forever: expiring and recreating it could
            // reproduce an older value while an import is comparing snapshots.
            if (! Cache::forever($key, $generation)) {
                throw new \RuntimeException('FTP-Upload-Event konnte nicht dauerhaft markiert werden.');
            }
        } finally {
            $lock->release();
        }
    }

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

    /**
     * Imports at most `ftp.import.max_files` files for one account.
     *
     * `$openSource` and `$statSource` are the file-system seams. The controller
     * passes its own overridable methods in, so the SEC-14 test subclasses drive
     * this code; the background job passes nothing and gets the defaults below.
     *
     * @param  (callable(string): (resource|false))|null  $openSource
     * @param  (callable(string): array{present: bool, size: int|false})|null  $statSource
     */
    public function importBatch(User $user, ?callable $openSource = null, ?callable $statSource = null): FtpImportResult
    {
        $openSource ??= fn (string $path) => $this->openSource($path);
        $statSource ??= fn (string $path) => $this->statSource($path);

        $preflight = $this->preflight($user);
        if ($preflight !== null) {
            return $preflight;
        }

        // Snapshot before the directory walk: an event that lands between this
        // point and the lock acquisition must force a handoff even if its file
        // was added after selectImportBatch() took its snapshot.
        $observedEventGeneration = $this->uploadEventGeneration($user->id);

        $inboxPath = $this->inboxPath($user);

        if (! is_dir($inboxPath)) {
            return FtpImportResult::empty();
        }

        // Resolved before the walk so a gallery deleted between `setTarget()` and
        // this run is a named rejection instead of a `TypeError` deep in the
        // import loop (the loose end §6 used to carry as "error may occur").
        $gallery = Gallery::find($user->current_ftp_gallery_id);
        if ($gallery === null) {
            return FtpImportResult::rejected(FtpImportStatus::GalleryMissing, self::GALLERY_MISSING_MESSAGE);
        }

        $limits = $this->importLimits();
        $selection = $this->selectImportBatch($inboxPath, $user, $limits);

        if ($selection['unreadable']) {
            return FtpImportResult::rejected(FtpImportStatus::UnreadableInbox, self::UNREADABLE_INBOX_MESSAGE);
        }

        if ($selection['error'] !== null) {
            return $selection['error'];
        }

        $quarantined = $selection['quarantined'];

        if ($selection['batch'] === []) {
            return FtpImportResult::empty($quarantined);
        }

        // Acquired only once there is work to do, so an idle photographer never
        // blocks their own next import. Non-blocking on purpose: a second run
        // must be told that it is too early instead of silently waiting and then
        // racing the first one through the very same files.
        $lock = Cache::lock(self::importLockName($user->id), self::IMPORT_LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            // The selection above can already have quarantined an oversized or
            // size-unreadable file. Carrying the count into the `Locked` result
            // keeps that side effect visible instead of reporting a bare lock.
            // Where it is visible: the background chain logs it with the
            // `quarantined` count (`ImportFtpBatch::handle()`, the Locked arm).
            // The HTTP arm of the same result does **not** surface it — its 409
            // body carries only the lock message — so a manual trigger that lost
            // the race reports the lock and nothing else; the count is
            // observable in the chain's log.
            return FtpImportResult::rejected(FtpImportStatus::Locked, self::LOCKED_MESSAGE, 0, $quarantined);
        }

        try {
            $result = $this->runImport(
                $user,
                $gallery,
                $inboxPath,
                $selection['batch'],
                $limits,
                $quarantined,
                $selection['has_more'],
                $openSource,
                $statSource,
            );
        } finally {
            $lock->release();
        }

        // Read only after releasing the lock. Any upload event that attempted
        // this account while the lock was held marks its generation before its
        // job returns Locked. Even when this run was rejected, the new upload
        // needs a bounded follow-up; the original result remains rejected so its
        // caller can still report the failure. An event arriving after unlock
        // either appears here or acquires the lock itself and imports normally.
        if ($this->uploadEventGeneration($user->id) !== $observedEventGeneration) {
            Log::info('FTP-Import: paralleles Upload-Event an die laufende Kette übergeben.', [
                'user_id' => $user->id,
                'status' => $result->status->value,
                'error' => $result->error,
                'imported' => $result->imported,
                'quarantined' => $result->quarantined,
            ]);

            return $result->withEventHandoff();
        }

        return $result;
    }

    private static function uploadEventGenerationKey(string $userId): string
    {
        return 'ftp-upload-event-generation:'.$userId;
    }

    private function uploadEventGeneration(string $userId): int
    {
        $generation = Cache::get(self::uploadEventGenerationKey($userId), 0);

        if (! is_numeric($generation) || (int) $generation < 0) {
            throw new \RuntimeException('FTP-Upload-Event-Marker ist ungültig.');
        }

        return (int) $generation;
    }

    /**
     * The resolved limits of one run, with the documented defaults.
     *
     * Public because it is the pin for the numbers themselves: a behavioural
     * test can only show that *a* batch of 20 files is imported, not that an
     * empty `FTP_IMPORT_MAX_FILE_BYTES` means 20 MiB rather than 0 or 1 GiB.
     *
     * @return array{max_files: int, max_file_bytes: int, max_batch_bytes: int}
     */
    public function importLimits(): array
    {
        return [
            'max_files' => $this->positiveInt(config('ftp.import.max_files'), self::DEFAULT_MAX_FILES),
            'max_file_bytes' => $this->positiveInt(config('ftp.import.max_file_bytes'), self::DEFAULT_MAX_FILE_BYTES),
            'max_batch_bytes' => $this->positiveInt(config('ftp.import.max_batch_bytes'), self::DEFAULT_MAX_BATCH_BYTES),
        ];
    }

    /**
     * The bounds of the self-chaining background import (D-36).
     *
     * @return array{max_batches: int, max_failures: int}
     */
    public function chainLimits(): array
    {
        return [
            'max_batches' => $this->positiveInt(config('ftp.import.chain_max_batches'), self::DEFAULT_CHAIN_MAX_BATCHES),
            'max_failures' => $this->positiveInt(config('ftp.import.chain_max_failures'), self::DEFAULT_CHAIN_MAX_FAILURES),
        ];
    }

    /** The absolute path of the account's inbox directory (upload path included). */
    public function inboxPath(User $user): string
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

    /** How many files currently sit in the quarantine folder of one inbox. */
    public function quarantineCount(string $inboxPath): int
    {
        $directory = $inboxPath.DIRECTORY_SEPARATOR.self::QUARANTINE_DIRECTORY;

        $handle = @opendir($directory);
        if ($handle === false) {
            return 0;
        }

        $count = 0;

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory.DIRECTORY_SEPARATOR.$entry;
                $this->forgetStatCache($path);
                if ($this->isRegularFile($path)) {
                    $count++;
                }
            }
        } finally {
            closedir($handle);
        }

        return $count;
    }

    /**
     * Fresh existence and size probe for one source file.
     *
     * `clearstatcache()` comes **first**, before `is_file()`. With the previous
     * order a stat cached by an earlier `is_file()` could still report the file
     * as present after another process (SFTPGo, a parallel worker) had removed
     * it, while the `filesize()` that followed the cache clear read the file
     * system freshly and returned false — so a vanished source was misreported
     * as a changed one. On the post-commit check that deleted an already
     * committed photo together with its stored file and thumbnail, although
     * nothing had changed and the source was simply gone.
     *
     * The probe is split out so a test can hand in the exact symptom of that
     * stale cache (`present` true, `size` false) without depending on OS-level
     * stat-cache behaviour, which is not reproducible on every host. The order
     * itself is pinned by `FtpStatCacheOrderTest`, which records the probe
     * sequence of a subclass: with the seam overridden wholesale, deleting the
     * `clearstatcache()` call used to stay green.
     *
     * @return array{present: bool, size: int|false}
     */
    public function statSource(string $path): array
    {
        $this->forgetStatCache($path);

        if (! $this->isRegularFile($path)) {
            return ['present' => false, 'size' => false];
        }

        return ['present' => true, 'size' => $this->fileSize($path)];
    }

    /** @return resource|false */
    public function openSource(string $path)
    {
        return @fopen($path, 'rb');
    }

    /** The one call that makes the next probe read the file system instead of a cache. */
    protected function forgetStatCache(string $path): void
    {
        clearstatcache(true, $path);
    }

    /**
     * Whether the directory entry is a regular file this import may read.
     *
     * MEDIA-5: a symlink is never read — not even one that resolves inside the
     * inbox. The client's home is granted `['/' => ['*']]`
     * (`SftpGoClient::CAMERA_PERMISSIONS`), so it may create a link named
     * `x.jpg` that points anywhere the backend process can reach. `is_file()`
     * and `fopen()` both follow that link, which would import a foreign file
     * with the importer's own privileges and then let the unlink below remove
     * the client's link. Rejecting every link via `is_link()` is deliberately
     * cheaper than a `realpath()` prefix comparison against the inbox: a
     * `realpath()` on a path that vanished between the listing and the read
     * returns `false`, and the obvious `false`-means-reject handling would
     * silently skip a real upload — a worse bug than the one guarded here.
     * A camera never has a reason to upload a link, so nothing legitimate is
     * lost (pinned by `FtpInboxSymlinkTest`).
     *
     * Consequence for the read-only counters (`countInboxImages()`,
     * `quarantineCount()`): a link no longer counts as a file, which matches
     * the import's own view of the inbox.
     */
    protected function isRegularFile(string $path): bool
    {
        return ! is_link($path) && is_file($path);
    }

    protected function fileSize(string $path): int|false
    {
        return @filesize($path);
    }

    /**
     * The cheap part of the pre-flight: who is asking, and about which gallery.
     *
     * Returns null when the run may proceed. The checks are the same ones the
     * controller used to perform inline; keeping them here means the background
     * chain cannot start an import the HTTP path would have refused.
     */
    private function preflight(User $user): ?FtpImportResult
    {
        $authorization = app(AuthorizationService::class);

        if (! $authorization->isPhotographer($user)) {
            return FtpImportResult::rejected(FtpImportStatus::NotPhotographer, self::NOT_PHOTOGRAPHER_MESSAGE);
        }

        if (! $user->current_ftp_gallery_id) {
            return FtpImportResult::rejected(FtpImportStatus::NoTarget, self::NO_TARGET_MESSAGE);
        }

        if (! in_array($user->current_ftp_gallery_id, $user->getAllowedGalleryIds(), true)) {
            return FtpImportResult::rejected(FtpImportStatus::TargetNotAllowed, self::TARGET_NOT_ALLOWED_MESSAGE);
        }

        return null;
    }

    /**
     * Choose the files of one batch from the inbox.
     *
     * A batch is a work limit, not a validation rule (D-35, AGENTS.md §14): an
     * inbox holding more files than the per-run limit is an ordinary shooting,
     * so the first `maxFiles` candidates are imported and every unselected file
     * stays untouched for the next run. Walk order is directory order, which is
     * stable for a directory whose entries are not being rewritten; the point of
     * the walk is that it stops, not that it sorts.
     *
     * Two cases are still rejected rather than batched, because they would never
     * make progress: a single file above the per-file budget (unless D-37 moves
     * it to the quarantine, see `quarantineOnFinalAttempt()`), and a first file
     * above the whole-run budget. Both name their number, their limit and the
     * way out in the response.
     *
     * A file that is present but whose size cannot be read is neither vanished
     * nor rejected: it counts one attempt and is quarantined after the threshold
     * (D-37), because a file that stays skipped forever is the state D-37
     * rejects. Before the threshold it is skipped, so the run continues.
     *
     * `has_more` is true exactly when the walk stopped at a limit instead of at
     * the end of the directory, so the chain knows whether the inbox still holds
     * work without walking it a second time.
     *
     * @param  array{max_files: int, max_file_bytes: int, max_batch_bytes: int}  $limits
     * @return array{batch: list<array{path: string, size: int}>, quarantined: int, error: ?FtpImportResult, unreadable: bool, has_more: bool}
     */
    protected function selectImportBatch(string $inboxPath, User $user, array $limits): array
    {
        $maxFiles = $limits['max_files'];
        $maxFileBytes = $limits['max_file_bytes'];
        $maxBatchBytes = $limits['max_batch_bytes'];

        $directory = @opendir($inboxPath);
        if ($directory === false) {
            // A directory that vanished between the `is_dir()` check and this
            // call is an empty inbox, not an unreadable one. Only a directory
            // that still exists and cannot be opened is the error case.
            $this->forgetStatCache($inboxPath);

            return [
                'batch' => [],
                'quarantined' => 0,
                'error' => null,
                'unreadable' => is_dir($inboxPath),
                'has_more' => false,
            ];
        }

        $batch = [];
        $totalBytes = 0;
        $quarantined = 0;
        $batchFull = false;
        $hasMore = false;

        try {
            while (($entry = readdir($directory)) !== false) {
                if (! $this->isInboxCandidate($entry)) {
                    continue;
                }

                $path = $inboxPath.DIRECTORY_SEPARATOR.$entry;

                // Invalidate before the existence check — see statSource().
                $this->forgetStatCache($path);
                if (! $this->isRegularFile($path)) {
                    continue;
                }

                if ($batchFull) {
                    // The batch is full and one further candidate exists: it and
                    // everything after it wait for the next run. Walking stops
                    // here, which is the whole point of the batch boundary.
                    $hasMore = true;

                    break;
                }

                $size = $this->fileSize($path);
                if (! is_int($size) || $size < 0) {
                    // The file is present (`isRegularFile()` above said so) but
                    // its fresh size cannot be read. This is **not** the vanished
                    // case, where there is nothing to move: here there is a file,
                    // and skipping it forever is exactly the state D-37 forbids.
                    // It counts one attempt and moves to the quarantine once the
                    // threshold is reached, like an oversized or undecodable file.
                    // Before the threshold it is skipped, so the run continues
                    // with the rest instead of aborting on a transient race; a
                    // failed move logs and leaves the counter at the threshold,
                    // so the next run retries it.
                    if ($this->quarantineOnFinalAttempt($user, $path)
                        && $this->moveToQuarantine($user, $inboxPath, $path)) {
                        $quarantined++;
                    }

                    continue;
                }

                if ($size > $maxFileBytes) {
                    // D-37: the move is the exit from the walk. If it fails — a
                    // read-only mount, a permission fault — the run reports the
                    // documented rejection instead of skipping the file, because
                    // a skipped file plus a "success" would claim a drained inbox
                    // that is not drained. The counter stays at or above the
                    // threshold, so the next run tries the move again.
                    if ($this->quarantineOnFinalAttempt($user, $path)
                        && $this->moveToQuarantine($user, $inboxPath, $path)) {
                        $quarantined++;

                        continue;
                    }

                    return [
                        'batch' => [],
                        'quarantined' => $quarantined,
                        'error' => FtpImportResult::rejected(
                            FtpImportStatus::LimitExceeded,
                            $this->limitExceededMessage($maxFileBytes, 'file', $size),
                        ),
                        'unreadable' => false,
                        'has_more' => false,
                    ];
                }

                if ($size > $maxBatchBytes - $totalBytes) {
                    if ($batch === []) {
                        return [
                            'batch' => [],
                            'quarantined' => $quarantined,
                            'error' => FtpImportResult::rejected(
                                FtpImportStatus::LimitExceeded,
                                $this->limitExceededMessage($maxBatchBytes, 'batch', $size),
                            ),
                            'unreadable' => false,
                            'has_more' => false,
                        ];
                    }

                    // This file no longer fits; it and every following entry
                    // wait for the next run.
                    $hasMore = true;

                    break;
                }

                $batch[] = ['path' => $path, 'size' => $size];
                $totalBytes += $size;

                if (count($batch) >= $maxFiles) {
                    // Not a `break`: one more candidate has to be looked for, so
                    // `has_more` answers "is there work left" without a second
                    // walk. A directory holding exactly `maxFiles` files ends
                    // here and reports no remainder.
                    $batchFull = true;
                }
            }
        } finally {
            closedir($directory);
        }

        return [
            'batch' => $batch,
            'quarantined' => $quarantined,
            'error' => null,
            'unreadable' => false,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Imports one selected batch into the target gallery.
     *
     * The caller must hold the import lock. Every source in the batch is
     * size-checked before it is opened, then copied into a bounded temporary
     * stream before image validation. Only after the whole batch is staged is
     * the first Photo row written, so a failure during staging cannot leave a
     * partially imported batch behind. Each staged image is then copied to the
     * photo disk, persisted in a transaction, and only after that unlinked from
     * the inbox — a failure before persistence leaves the source available for
     * retry (features/infrastructure/19-ftp-upload-pipeline.md §6).
     *
     * Staging is atomic, validation is not: a file that turns out not to be a
     * decodable image is only discovered in the loop below, after the files
     * before it have already been written and unlinked. That is why this method
     * reports `imported` even on a rejection, and why D-37 quarantines the
     * offender after a few attempts instead of leaving it at the head of the
     * walk forever.
     *
     * @param  list<array{path: string, size: int}>  $batch
     * @param  array{max_files: int, max_file_bytes: int, max_batch_bytes: int}  $limits
     * @param  callable(string): (resource|false)  $openSource
     * @param  callable(string): array{present: bool, size: int|false}  $statSource
     */
    private function runImport(
        User $user,
        Gallery $gallery,
        string $inboxPath,
        array $batch,
        array $limits,
        int $quarantined,
        bool $hasMore,
        callable $openSource,
        callable $statSource,
    ): FtpImportResult {
        $maxFileBytes = $limits['max_file_bytes'];
        $maxBatchBytes = $limits['max_batch_bytes'];

        $processedCount = 0;
        $stagedFiles = [];
        $stagedBytes = 0;

        try {
            // Stage the full, already-preflighted batch before importing any
            // photo. This keeps a file that grows after stat() from making a
            // partial batch exceed the aggregate budget.
            foreach ($batch as $source) {
                // Invalidate before the existence check — see statSource().
                $this->forgetStatCache($source['path']);
                if (! $this->isRegularFile($source['path'])) {
                    continue;
                }

                $remainingBatchBytes = $maxBatchBytes - $stagedBytes;
                $copyLimit = min($maxFileBytes, $remainingBatchBytes);
                if ($copyLimit <= 0) {
                    // Unreachable through selectImportBatch(), which already
                    // enforces the budget; kept so a future caller cannot open a
                    // stream with a zero-byte allowance.
                    break;
                }

                $staged = $this->copySourceToTemporaryStream($source['path'], $copyLimit, $openSource);

                if ($staged === false) {
                    // The file disappeared after the selection; a competing run
                    // already claimed it.
                    continue;
                }

                if ($staged === null) {
                    // The source grew past its share of the budget while it was
                    // copied. That is a rejection, not a smaller batch: the file
                    // verified in selectImportBatch() is not the file that would
                    // be imported.
                    return FtpImportResult::rejected(
                        FtpImportStatus::LimitExceeded,
                        $this->limitExceededMessage(
                            $copyLimit === $maxFileBytes ? $maxFileBytes : $maxBatchBytes,
                            $copyLimit === $maxFileBytes ? 'file' : 'batch',
                        ),
                        $processedCount,
                        $quarantined,
                    );
                }

                if ($staged['bytes'] !== $source['size']
                    || ! $this->sourceHasNotChanged($source['path'], $staged['bytes'], $statSource)) {
                    fclose($staged['stream']);

                    return FtpImportResult::rejected(
                        FtpImportStatus::SourceChanged,
                        self::SOURCE_CHANGED_MESSAGE,
                        $processedCount,
                        $quarantined,
                    );
                }

                $stagedFiles[] = [
                    'source_path' => $source['path'],
                    'stream' => $staged['stream'],
                    'bytes' => $staged['bytes'],
                ];
                $stagedBytes += $staged['bytes'];
            }

            foreach ($stagedFiles as $staged) {
                $file = $staged['source_path'];

                // A file claimed by a competing process after staging must not
                // be imported a second time under a new UUID. The cache is
                // invalidated first for the same reason as statSource().
                $this->forgetStatCache($file);
                if (! $this->isRegularFile($file)) {
                    continue;
                }

                $temporaryPath = stream_get_meta_data($staged['stream'])['uri'] ?? null;
                if (! is_string($temporaryPath)) {
                    throw new \RuntimeException('FTP-Quelldatei konnte nicht vorbereitet werden.');
                }

                $originalName = pathinfo($file, PATHINFO_FILENAME);

                $imageInfo = PhotoProcessingService::inspectRasterImage($temporaryPath, $maxFileBytes);
                if ($imageInfo === null) {
                    // D-37: a file that cannot be decoded blocks the walk if it
                    // stays at the head of the directory order. After the
                    // configured number of attempts it moves aside, and the run
                    // continues with the remaining files instead of aborting. A
                    // failed move falls back to the documented 422 below.
                    if ($this->quarantineOnFinalAttempt($user, $file)
                        && $this->moveToQuarantine($user, $inboxPath, $file)) {
                        if (is_resource($staged['stream'])) {
                            fclose($staged['stream']);
                        }

                        $quarantined++;

                        continue;
                    }

                    return FtpImportResult::rejected(
                        FtpImportStatus::SourceUnprocessable,
                        self::SOURCE_UNPROCESSABLE_MESSAGE,
                        $processedCount,
                        $quarantined,
                    );
                }

                $extension = match ($imageInfo['mime_type']) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                };

                // Dateinamen IMMER aus UUID generieren!
                $photoId = (string) Str::uuid();
                $filename = $photoId.'.'.$extension;

                $targetDir = (string) $gallery->id;
                $targetRelativePath = $targetDir.'/'.$filename;
                $thumbsDir = $targetDir.'/_thumbs';
                $thumbRelativePath = $thumbsDir.'/'.md5($filename.'1024').'.webp';
                $photoDisk = Storage::disk('photos');

                $persisted = false;
                try {
                    if (! $photoDisk->put($targetRelativePath, $staged['stream'])) {
                        throw new \RuntimeException('Foto konnte nicht in den Photo-Speicher geschrieben werden.');
                    }

                    $targetPath = $photoDisk->path($targetRelativePath);
                    $thumbPath = $photoDisk->path($thumbRelativePath);

                    if (! is_dir(dirname($thumbPath))) {
                        mkdir(dirname($thumbPath), 0755, true);
                    }

                    $meta = $this->photoService->processImage($targetPath, $thumbPath, $gallery);
                    $meta['mime_type'] = $imageInfo['mime_type'];
                    if (empty($meta['title'])) {
                        $meta['title'] = $originalName; // Dateiname als Titel retten
                    }

                    // Do not persist a snapshot if the camera appended to or
                    // replaced the source while image processing was running.
                    // A source that vanished is not a change: the staged copy is
                    // the snapshot this run started with, and importing it keeps
                    // the original read-then-process behaviour.
                    if (! $this->sourceHasNotChanged($file, $staged['bytes'], $statSource)) {
                        return FtpImportResult::rejected(
                            FtpImportStatus::SourceChanged,
                            self::SOURCE_CHANGED_MESSAGE,
                            $processedCount,
                            $quarantined,
                        );
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

                    $persisted = true;
                } finally {
                    if (! $persisted) {
                        $this->cleanupImportArtifacts($photoDisk, [$targetRelativePath, $thumbRelativePath]);
                    }
                }

                // Persistence is already committed here. If the camera grew the
                // source in that narrow window, remove this row and its files
                // and leave the changed inbox file for a clean retry. A vanished
                // source is a completed claim: keep the row and skip the unlink
                // below.
                if (! $this->sourceHasNotChanged($file, $staged['bytes'], $statSource)) {
                    DB::transaction(function () use ($photoId): void {
                        $photo = Photo::query()->whereKey($photoId)->first();
                        if ($photo !== null && ! $photo->delete()) {
                            throw new \RuntimeException('FTP-Fotodatensatz konnte nach einer Quelländerung nicht entfernt werden.');
                        }
                    });
                    // Reached only when the transaction committed, i.e. the row
                    // is gone and its files are orphans. When `delete()` refuses,
                    // the exception above propagates and this cleanup is skipped
                    // on purpose: the row still references those files, so
                    // deleting them would turn a rejected rollback into a broken
                    // photo. The request fails loudly (500) and the inbox source
                    // stays in place for the next run.
                    $this->cleanupImportArtifacts($photoDisk, [$targetRelativePath, $thumbRelativePath]);

                    return FtpImportResult::rejected(
                        FtpImportStatus::SourceChanged,
                        self::SOURCE_CHANGED_MESSAGE,
                        $processedCount,
                        $quarantined,
                    );
                }

                // Existence check before the unlink: same stat-cache rule as
                // statSource(). A source that is already gone counts as claimed
                // and is not an error.
                $this->forgetStatCache($file);
                if (file_exists($file) && ! unlink($file)) {
                    throw new \RuntimeException('FTP-Quelldatei konnte nicht gelöscht werden.');
                }

                $processedCount++;
            }
        } finally {
            foreach ($stagedFiles as $staged) {
                if (is_resource($staged['stream'])) {
                    fclose($staged['stream']);
                }
            }
        }

        return FtpImportResult::imported($processedCount, $quarantined, $hasMore);
    }

    /**
     * Whether the directory entry is an inbox candidate at all.
     *
     * Two filters, both load-bearing: the image extension keeps `notes.txt` out
     * of the walk, and the quarantine folder is skipped by name so it can never
     * be mistaken for a file even if a future change gave it an extension.
     */
    private function isInboxCandidate(string $entry): bool
    {
        if ($entry === '.' || $entry === '..' || $entry === self::QUARANTINE_DIRECTORY) {
            return false;
        }

        return in_array(pathinfo($entry, PATHINFO_EXTENSION), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Count the inbox image files without materialising an array of every name.
     *
     * `status()` uses this for `file_count`; it is public so the controller can
     * keep its overridable seam (the suite runs as root in CI, where a `chmod`
     * fixture is ignored and a simulated unreadable directory is the only way to
     * reach the error branch).
     *
     * @return int|null Null when the directory exists but cannot be opened.
     */
    public function countInboxImages(string $inboxPath): ?int
    {
        $directory = @opendir($inboxPath);
        if ($directory === false) {
            // See selectImportBatch(): a vanished directory is empty, only a
            // directory that still exists but cannot be opened is unreadable.
            $this->forgetStatCache($inboxPath);

            return is_dir($inboxPath) ? null : 0;
        }

        $count = 0;

        try {
            while (($entry = readdir($directory)) !== false) {
                if (! $this->isInboxCandidate($entry)) {
                    continue;
                }

                $path = $inboxPath.DIRECTORY_SEPARATOR.$entry;
                $this->forgetStatCache($path);
                if (! $this->isRegularFile($path)) {
                    continue;
                }

                $count++;
            }
        } finally {
            closedir($directory);
        }

        return $count;
    }

    /**
     * Read a positive integer limit, falling back to the documented default.
     *
     * `(int) env('FTP_IMPORT_MAX_FILES', 20)` turns an empty value into 0, and
     * the old controller rescued 0 to 1 — so an empty env silently shrank every
     * batch to a single file instead of restoring the documented default. An
     * empty, non-numeric or non-positive value is not a usable limit and must
     * fall back rather than be guessed at.
     */
    private function positiveInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (is_float($value)) {
            return $value >= 1 ? (int) $value : $default;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ctype_digit($trimmed)) {
                $parsed = (int) $trimmed;

                return $parsed > 0 ? $parsed : $default;
            }
        }

        return $default;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, ',', '.').' MiB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KiB';
        }

        return $bytes.' Byte';
    }

    /**
     * A 413 that names the number, the limit and the way out.
     *
     * The two remaining oversized cases are a single file above the per-file
     * budget and a first file above the whole-run budget. They are the only
     * rejections left after D-35 turned "too many files" into a batch, so the
     * message has to be actionable instead of the old generic "limit exceeded".
     */
    private function limitExceededMessage(int $limit, string $scope, ?int $observed = null): string
    {
        $limitText = $this->formatBytes($limit);

        if ($scope === 'file') {
            $error = $observed !== null
                ? 'Die FTP-Datei ist mit '.$this->formatBytes($observed).' größer als das Limit von '.$limitText.' pro Datei.'
                : 'Die FTP-Datei ist während des Imports über das Limit von '.$limitText.' pro Datei gewachsen.';
            $error .= ' Verkleinere das Bild oder erhöhe FTP_IMPORT_MAX_FILE_BYTES.';
        } else {
            $error = $observed !== null
                ? 'Die FTP-Datei überschreitet mit '.$this->formatBytes($observed).' das Gesamtbudget von '.$limitText.' pro Import.'
                : 'Die FTP-Datei ist während des Imports über das Gesamtbudget von '.$limitText.' pro Import gewachsen.';
            $error .= ' Teile den Import auf oder erhöhe FTP_IMPORT_MAX_BATCH_BYTES.';
        }

        return $error;
    }

    /**
     * Copy no more than the requested byte budget plus one detection byte.
     *
     * @param  callable(string): (resource|false)  $openSource
     * @return array{stream: resource, bytes: int}|null|false Null means the
     *                                                        source exceeded the budget; false means it disappeared before it
     *                                                        could be opened.
     */
    private function copySourceToTemporaryStream(string $path, int $maxBytes, callable $openSource): array|null|false
    {
        $source = $openSource($path);
        if (! is_resource($source)) {
            $this->forgetStatCache($path);
            if (! file_exists($path)) {
                return false;
            }

            throw new \RuntimeException('FTP-Quelldatei konnte nicht gelesen werden.');
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            fclose($source);

            throw new \RuntimeException('Temporäre FTP-Datei konnte nicht angelegt werden.');
        }

        $bytesCopied = 0;
        try {
            while (true) {
                $remaining = $maxBytes - $bytesCopied;
                $chunk = @fread($source, min(self::IMPORT_COPY_CHUNK_BYTES, $remaining + 1));
                if ($chunk === false) {
                    throw new \RuntimeException('FTP-Quelldatei konnte nicht gelesen werden.');
                }
                if ($chunk === '') {
                    if (feof($source)) {
                        break;
                    }

                    throw new \RuntimeException('FTP-Quelldatei konnte nicht gelesen werden.');
                }

                $chunkBytes = strlen($chunk);
                if ($chunkBytes > $remaining) {
                    fclose($temporary);

                    return null;
                }

                $written = 0;
                while ($written < $chunkBytes) {
                    $bytesWritten = @fwrite($temporary, substr($chunk, $written));
                    if ($bytesWritten === false || $bytesWritten === 0) {
                        throw new \RuntimeException('FTP-Quelldatei konnte nicht vorbereitet werden.');
                    }
                    $written += $bytesWritten;
                }

                $bytesCopied += $chunkBytes;
            }

            if (! rewind($temporary)) {
                throw new \RuntimeException('FTP-Quelldatei konnte nicht vorbereitet werden.');
            }

            return ['stream' => $temporary, 'bytes' => $bytesCopied];
        } catch (\Throwable $exception) {
            fclose($temporary);

            throw $exception;
        } finally {
            fclose($source);
        }
    }

    /**
     * Whether the source still has the size this run staged.
     *
     * A missing source counts as unchanged: there is nothing left to compare or
     * to unlink, and the staged snapshot is what this run read. A source that
     * `is_file()` still reports as present but whose fresh `filesize()` fails is
     * the stale-stat-cache case above and counts as vanished as well — treating
     * it as changed is the destructive direction, because it removes a committed
     * photo. Only an existing source with a readable, different size means the
     * camera is still writing or replaced the file, which must not be persisted
     * as a torn snapshot.
     *
     * @param  callable(string): array{present: bool, size: int|false}  $statSource
     */
    private function sourceHasNotChanged(string $path, int $stagedBytes, callable $statSource): bool
    {
        $stat = $statSource($path);

        if (! $stat['present'] || ! is_int($stat['size'])) {
            return true;
        }

        return $stat['size'] === $stagedBytes;
    }

    /**
     * Count one failed attempt against a file and answer whether it is the last one.
     *
     * The counter is per account and file path and lives in the cache, so the
     * HTTP path and the background chain share it: three manual clicks and three
     * events are six attempts against the same file, which is what the
     * photographer observes. The TTL keeps the counter from being an audit trail.
     */
    protected function quarantineOnFinalAttempt(User $user, string $path): bool
    {
        $key = self::attemptKey($user, $path);
        $lock = $this->quarantineAttemptLock($key);
        if ($lock->block(self::ATTEMPT_LOCK_WAIT_SECONDS) !== true) {
            throw new \RuntimeException('FTP-Quarantäneversuch konnte nicht atomar gezählt werden.');
        }

        try {
            $attempts = (int) Cache::get($key, 0) + 1;
            $this->afterQuarantineAttemptRead($attempts);

            // Keep the original sliding 24-hour window. A failed write throws
            // instead of treating this as a below-threshold attempt; otherwise
            // concurrent storage faults could silently keep a bad file forever.
            if (! Cache::put($key, $attempts, now()->addHours(self::ATTEMPT_TTL_HOURS))) {
                throw new \RuntimeException('FTP-Quarantäneversuch konnte nicht gespeichert werden.');
            }
        } finally {
            $lock->release();
        }

        return $attempts >= $this->quarantineAfterAttempts();
    }

    /** @internal Test seam for deterministic interleaving around the counter write. */
    protected function afterQuarantineAttemptRead(int $attempts): void {}

    /** @internal Test seam; production always uses the configured shared cache lock. */
    protected function quarantineAttemptLock(string $key): Lock
    {
        return Cache::lock($key.':lock', self::ATTEMPT_LOCK_TTL_SECONDS);
    }

    /**
     * How many failed attempts a file needs before it is quarantined (D-37).
     *
     * Public like the other limit readers: the documented default (3) has to be
     * assertable, because a behavioural test can only show that the *configured*
     * number is honoured, not what an unset variable means.
     */
    public function quarantineAfterAttempts(): int
    {
        return $this->positiveInt(config('ftp.import.quarantine_after_attempts'), self::DEFAULT_QUARANTINE_AFTER_ATTEMPTS);
    }

    private static function attemptKey(User $user, string $path): string
    {
        return 'ftp-import-attempts:'.$user->id.':'.sha1($path);
    }

    /**
     * Move an unimportable file out of the walk, next to it (D-37).
     *
     * The file is **moved, never deleted**: it is the photographer's original
     * upload, and nothing in the portal may destroy it. The folder is created
     * with the same mode the inbox itself uses (`FtpInboxDirectory::MODE`,
     * D-2), because it is created by the same non-root process under the same
     * setgid parent.
     *
     * A failure here is reported and returns false, which leaves the file where
     * it is: the run then reports the ordinary rejection and the next attempt
     * tries again. Swallowing the failure would claim a drained inbox that is
     * not drained.
     */
    protected function moveToQuarantine(User $user, string $inboxPath, string $path): bool
    {
        $directory = $inboxPath.DIRECTORY_SEPARATOR.self::QUARANTINE_DIRECTORY;

        if (! is_dir($directory) && ! @mkdir($directory, FtpInboxDirectory::MODE)) {
            Log::warning('FTP-Import: Quarantäne-Ordner konnte nicht angelegt werden.', [
                'directory' => $directory,
            ]);

            return false;
        }

        @chmod($directory, FtpInboxDirectory::MODE);

        // Reserve the target atomically before moving onto it. A bare
        // `file_exists()` check followed by `rename()` is not enough: POSIX
        // `rename()` replaces an existing target, so two runs that both saw a
        // free name could make the second overwrite the first — which
        // contradicts D-37's "never deleted". The exclusive create is the
        // reservation; the rename then replaces the empty placeholder we own.
        $target = $this->reserveQuarantineTarget($directory, basename($path));
        if ($target === null) {
            Log::warning('FTP-Import: kein freier Name für die Quarantäne gefunden.', [
                'source' => $path,
                'directory' => $directory,
            ]);

            return false;
        }

        if (! @rename($path, $target)) {
            // Release the reservation: an empty placeholder left behind would be
            // a file the photographer never uploaded.
            @unlink($target);

            Log::warning('FTP-Import: Datei konnte nicht in die Quarantäne verschoben werden.', [
                'source' => $path,
                'target' => $target,
            ]);

            return false;
        }

        Cache::forget(self::attemptKey($user, $path));

        Log::warning('FTP-Import: Datei nach wiederholtem Fehlschlag in die Quarantäne verschoben.', [
            'source' => $path,
            'target' => $target,
        ]);

        return true;
    }

    /**
     * Atomically reserve a quarantine file name, or null when every candidate is
     * taken.
     *
     * `fopen($path, 'x')` fails when the file already exists and is atomic, so
     * exactly one process can hold a given candidate. The caller then `rename()`s
     * onto it — replacing the empty placeholder it just created, never an earlier
     * quarantined upload.
     */
    private function reserveQuarantineTarget(string $directory, string $name): ?string
    {
        foreach ($this->quarantineNameCandidates($name) as $candidate) {
            $target = $directory.DIRECTORY_SEPARATOR.$candidate;
            $reservation = @fopen($target, 'x');

            if ($reservation === false) {
                continue;
            }

            fclose($reservation);

            return $target;
        }

        return null;
    }

    /**
     * Candidate names for a quarantined file, in preference order.
     *
     * Uploads from one camera repeat file names (`IMG_0001.JPG`), and two
     * quarantined files of one shooting must both survive. The plain name is
     * tried first, then a timestamp prefix that keeps the file sortable and
     * recognisable, and a `uniqid()` fallback for the same-second case. The
     * reservation in `reserveQuarantineTarget()` decides which one wins; this
     * method only produces the order.
     *
     * @return list<string>
     */
    private function quarantineNameCandidates(string $name): array
    {
        return [
            $name,
            date('Ymd-His').'-'.$name,
            date('Ymd-His').'-'.uniqid().'-'.$name,
        ];
    }

    /**
     * Remove destination files when processing or persistence fails, while
     * keeping the original exception as the request failure.
     *
     * @param  list<string>  $paths
     */
    private function cleanupImportArtifacts(Filesystem $photoDisk, array $paths): void
    {
        try {
            if (! $photoDisk->delete($paths)) {
                Log::warning('FTP import left destination files after a failed import.', [
                    'paths' => $paths,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('FTP import could not remove destination files after a failed import.', [
                'paths' => $paths,
                'exception' => $exception::class,
            ]);
        }
    }
}
