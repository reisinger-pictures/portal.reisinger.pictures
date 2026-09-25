<?php

namespace App\Services;

use App\Jobs\CrmCleanupOutboxJob;
use App\Jobs\DeleteGalleryFolderJob;
use App\Jobs\DeletePhotoFilesJob;
use App\Jobs\SyncGalleryPhotoSearchJob;
use App\Jobs\SyncGalleryPhotosSearchJob;

/**
 * Durable cleanup boundary for the public photos disk.
 *
 * Gallery and photo records are deleted in a database transaction first. The
 * cleanup intent is then represented by the same UUID-keyed jobs table and
 * CrmCleanupOutboxJob contract used by CRM cleanup, with a distinct operation
 * for the photos-disk jobs. Scout removal is registered as separate
 * post-commit operations because Gallery/Photo use Searchable directly. A
 * gallery also passes the child photo keys captured before its DB cascade;
 * those keys are processed in bounded batches. This
 * keeps encrypted model files on their existing local-disk path instead of
 * conflating the two storage domains.
 */
final class GalleryPhotoCleanupService
{
    public function __construct(
        private readonly DurableDispatchService $dispatch,
    ) {}

    /**
     * @param  array<int, mixed>  $photoIds
     */
    public function afterGalleryDelete(string $galleryId, string $reason, array $photoIds = []): void
    {
        if ($galleryId === '') {
            return;
        }

        $this->dispatch->afterCommitDurably(
            new DeleteGalleryFolderJob($galleryId),
            CrmCleanupOutboxJob::forGalleryFolder($galleryId, $reason),
            'gallery.folder_cleanup',
            [
                'gallery_id' => $galleryId,
                'reason' => $reason,
            ],
        );

        $this->dispatch->afterCommitDurably(
            new SyncGalleryPhotoSearchJob(SyncGalleryPhotoSearchJob::GALLERY, $galleryId),
            CrmCleanupOutboxJob::forGallerySearch($galleryId, $reason),
            'gallery.search_cleanup',
            [
                'gallery_id' => $galleryId,
                'reason' => $reason,
            ],
        );

        $photoIds = $this->normalizePhotoIds($photoIds);
        if ($photoIds !== []) {
            $this->dispatch->afterCommitDurably(
                new SyncGalleryPhotosSearchJob($galleryId, $photoIds),
                CrmCleanupOutboxJob::forGalleryPhotosSearch($galleryId, $photoIds, $reason),
                'gallery.child_search_cleanup',
                [
                    'gallery_id' => $galleryId,
                    'photo_count' => count($photoIds),
                    'reason' => $reason,
                ],
            );
        }
    }

    public function afterPhotoDelete(
        string $galleryId,
        string $filename,
        string $photoId,
        string $reason,
    ): void {
        if ($galleryId === '' || $filename === '' || $photoId === '') {
            return;
        }

        $this->dispatch->afterCommitDurably(
            new DeletePhotoFilesJob($galleryId, $filename, $photoId),
            CrmCleanupOutboxJob::forPhotoFiles($galleryId, $filename, $photoId, $reason),
            'photo.files_cleanup',
            [
                'gallery_id' => $galleryId,
                'photo_id' => $photoId,
                'filename' => $filename,
                'reason' => $reason,
            ],
        );

        $this->dispatch->afterCommitDurably(
            new SyncGalleryPhotoSearchJob(SyncGalleryPhotoSearchJob::PHOTO, $photoId),
            CrmCleanupOutboxJob::forPhotoSearch($photoId, $reason),
            'photo.search_cleanup',
            [
                'photo_id' => $photoId,
                'reason' => $reason,
            ],
        );
    }

    /**
     * @param  array<int, mixed>  $photoIds
     * @return array<int, string>
     */
    private function normalizePhotoIds(array $photoIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $photoId): string => (string) $photoId, $photoIds),
            static fn (string $photoId): bool => $photoId !== '',
        )));
    }
}
