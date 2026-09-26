<?php

namespace App\Jobs;

use App\Models\Photo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remove a bounded batch of child Photo documents from Scout.
 *
 * Gallery deletion cascades photo rows in the database, so Photo::deleted
 * events cannot provide the authoritative set of keys. The parent cleanup
 * boundary captures those keys before deleting the gallery and persists this
 * job through CrmCleanupOutboxJob.
 */
final class SyncGalleryPhotosSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_SIZE = 100;

    public const REMOVE = 'remove';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected string $galleryId;

    /** @var array<int, string> */
    protected array $photoIds;

    /**
     * @param  array<int, mixed>  $photoIds
     */
    public function __construct(string $galleryId, array $photoIds)
    {
        $this->galleryId = $galleryId;
        $this->photoIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $photoId): string => (string) $photoId, $photoIds),
            static fn (string $photoId): bool => $photoId !== '',
        )));
    }

    public function galleryId(): string
    {
        return $this->galleryId;
    }

    /**
     * @return array<int, string>
     */
    public function photoIds(): array
    {
        return $this->photoIds;
    }

    public function handle(): void
    {
        $chunks = array_chunk($this->photoIds, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $models = array_map(static function (string $photoId): Photo {
                $photo = new Photo;
                $photo->setAttribute($photo->getKeyName(), $photoId);

                return $photo;
            }, $chunk);

            (new Photo)->newCollection($models)->unsearchableSync();
        }

        Log::info('gallery_photo.child_search_cleanup.completed', [
            'gallery_id' => $this->galleryId,
            'photo_count' => count($this->photoIds),
            'chunk_count' => count($chunks),
            'chunk_size' => self::CHUNK_SIZE,
            'operation' => self::REMOVE,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('gallery_photo.child_search_cleanup.failed', [
            'gallery_id' => $this->galleryId,
            'photo_count' => count($this->photoIds),
            'chunk_count' => count(array_chunk($this->photoIds, self::CHUNK_SIZE)),
            'operation' => self::REMOVE,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
