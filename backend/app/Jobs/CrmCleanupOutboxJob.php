<?php

namespace App\Jobs;

use App\Services\ModelFileStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Durable fallback for a cleanup dispatch that could not reach the queue.
 *
 * The row is written directly to the existing database jobs table by
 * DurableDispatchService. It runs the same underlying operation as the
 * original queue job, while keeping the fallback attempt and terminal failure
 * independently auditable. Gallery and photo files use distinct operation
 * values because they live on the public photos disk; Scout removals have
 * separate gallery/photo operations as well, including a bounded child-photo
 * batch for database-cascaded gallery deletion. Model-file cleanup keeps its
 * existing encrypted-local-disk operation.
 */
final class CrmCleanupOutboxJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public const FILE_CLEANUP = 'file_cleanup';

    public const CUSTOMER_SEARCH = 'customer_search';

    public const GALLERY_FOLDER = 'gallery_folder';

    public const PHOTO_FILES = 'photo_files';

    public const GALLERY_SEARCH = 'gallery_search';

    public const GALLERY_PHOTOS_SEARCH = 'gallery_photos_search';

    public const PHOTO_SEARCH = 'photo_search';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected string $operation;

    /** @var array<int, string> */
    protected array $paths;

    protected string $reason;

    protected ?string $customerId;

    protected string $searchOperation;

    protected string $galleryId = '';

    protected string $filename = '';

    protected string $photoId = '';

    /** @var array<int, string> */
    protected array $searchIds = [];

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $searchIds
     */
    private function __construct(
        string $operation,
        array $paths,
        string $reason,
        ?string $customerId,
        string $searchOperation,
        string $galleryId = '',
        string $filename = '',
        string $photoId = '',
        array $searchIds = [],
    ) {
        $this->operation = $operation;
        $this->paths = $paths;
        $this->reason = $reason;
        $this->customerId = $customerId;
        $this->searchOperation = $searchOperation;
        $this->galleryId = $galleryId;
        $this->filename = $filename;
        $this->photoId = $photoId;
        $this->searchIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $searchId): string => (string) $searchId, $searchIds),
            static fn (string $searchId): bool => $searchId !== '',
        )));
    }

    /**
     * @param  array<int, string>  $paths
     */
    public static function forFileCleanup(array $paths, string $reason, ?string $customerId = null): self
    {
        return new self(
            self::FILE_CLEANUP,
            array_values(array_unique(array_filter(
                $paths,
                static fn (mixed $path): bool => is_string($path) && $path !== '',
            ))),
            $reason,
            $customerId,
            '',
        );
    }

    public static function forGalleryFolder(string $galleryId, string $reason): self
    {
        return new self(
            self::GALLERY_FOLDER,
            [],
            $reason,
            null,
            '',
            $galleryId,
        );
    }

    public static function forPhotoFiles(
        string $galleryId,
        string $filename,
        string $photoId,
        string $reason,
    ): self {
        return new self(
            self::PHOTO_FILES,
            [],
            $reason,
            null,
            '',
            $galleryId,
            $filename,
            $photoId,
        );
    }

    public static function forGallerySearch(string $galleryId, string $reason): self
    {
        return new self(
            self::GALLERY_SEARCH,
            [],
            $reason,
            null,
            SyncGalleryPhotoSearchJob::REMOVE,
            $galleryId,
        );
    }

    /**
     * @param  array<int, mixed>  $photoIds
     */
    public static function forGalleryPhotosSearch(string $galleryId, array $photoIds, string $reason): self
    {
        return new self(
            self::GALLERY_PHOTOS_SEARCH,
            [],
            $reason,
            null,
            SyncGalleryPhotosSearchJob::REMOVE,
            $galleryId,
            '',
            '',
            $photoIds,
        );
    }

    public static function forPhotoSearch(string $photoId, string $reason): self
    {
        return new self(
            self::PHOTO_SEARCH,
            [],
            $reason,
            null,
            SyncGalleryPhotoSearchJob::REMOVE,
            '',
            '',
            $photoId,
        );
    }

    public static function forCustomerSearch(string $customerId, string $operation): self
    {
        return new self(
            self::CUSTOMER_SEARCH,
            [],
            '',
            $customerId,
            $operation,
        );
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function searchOperation(): string
    {
        return $this->searchOperation;
    }

    public function galleryId(): string
    {
        return $this->galleryId;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function photoId(): string
    {
        return $this->photoId;
    }

    /**
     * @return array<int, string>
     */
    public function searchIds(): array
    {
        return $this->searchIds;
    }

    public function handle(?ModelFileStore $fileStore = null): void
    {
        $job = $this->underlyingJob();

        if ($job instanceof DeleteModelFilesJob) {
            $job->handle($fileStore ?? app(ModelFileStore::class));
        } else {
            $job->handle();
        }

        Log::info('crm.cleanup_outbox.completed', $this->auditContext());
    }

    public function failed(Throwable $exception): void
    {
        Log::error('crm.cleanup_outbox.failed', array_merge($this->auditContext(), [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]));

        try {
            $this->underlyingJob()->failed($exception);
        } catch (Throwable $auditException) {
            Log::error('crm.cleanup_outbox.underlying_audit_failed', [
                'operation' => $this->operation,
                'customer_id' => $this->customerId,
                'exception' => $auditException::class,
                'message' => $auditException->getMessage(),
            ]);
        }
    }

    private function underlyingJob(): DeleteModelFilesJob|DeleteGalleryFolderJob|DeletePhotoFilesJob|SyncGalleryPhotosSearchJob|SyncGalleryPhotoSearchJob|SyncCustomerSearchJob
    {
        return match ($this->operation) {
            self::FILE_CLEANUP => new DeleteModelFilesJob($this->paths, $this->reason, $this->customerId),
            self::GALLERY_FOLDER => new DeleteGalleryFolderJob($this->galleryId),
            self::PHOTO_FILES => new DeletePhotoFilesJob(
                $this->galleryId,
                $this->filename,
                $this->photoId,
            ),
            self::GALLERY_SEARCH => new SyncGalleryPhotoSearchJob(
                SyncGalleryPhotoSearchJob::GALLERY,
                $this->galleryId,
            ),
            self::GALLERY_PHOTOS_SEARCH => new SyncGalleryPhotosSearchJob(
                $this->galleryId,
                $this->searchIds,
            ),
            self::PHOTO_SEARCH => new SyncGalleryPhotoSearchJob(
                SyncGalleryPhotoSearchJob::PHOTO,
                $this->photoId,
            ),
            self::CUSTOMER_SEARCH => new SyncCustomerSearchJob(
                (string) $this->customerId,
                $this->searchOperation,
            ),
            default => throw new \LogicException("Unsupported cleanup outbox operation: {$this->operation}"),
        };
    }

    /**
     * @return array<string, int|string|null>
     */
    private function auditContext(): array
    {
        $context = [
            'operation' => $this->operation,
            'customer_id' => $this->customerId,
            'reason' => $this->reason,
            'path_count' => count($this->paths),
            'search_operation' => $this->searchOperation === '' ? null : $this->searchOperation,
        ];

        if ($this->galleryId !== '') {
            $context['gallery_id'] = $this->galleryId;
        }

        if ($this->photoId !== '') {
            $context['photo_id'] = $this->photoId;
        }

        if ($this->searchIds !== []) {
            $context['search_id_count'] = count($this->searchIds);
        }

        return $context;
    }
}
