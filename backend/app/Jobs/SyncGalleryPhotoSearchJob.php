<?php

namespace App\Jobs;

use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Idempotently remove a deleted public Gallery/Photo document from Scout.
 *
 * The job carries only the model kind and primary key. It deliberately does
 * not reload the deleted row: Scout deletion only needs the index name and
 * key, and a missing database row must not turn a retry into a permanent
 * failure.
 */
final class SyncGalleryPhotoSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const GALLERY = 'gallery';

    public const PHOTO = 'photo';

    public const REMOVE = 'remove';

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    protected string $modelType;

    protected string $modelId;

    public function __construct(string $modelType, string $modelId)
    {
        if (! in_array($modelType, [self::GALLERY, self::PHOTO], true)) {
            throw new InvalidArgumentException("Unsupported searchable model type: {$modelType}");
        }

        if ($modelId === '') {
            throw new InvalidArgumentException('Search cleanup requires a model id.');
        }

        $this->modelType = $modelType;
        $this->modelId = $modelId;
    }

    public function modelType(): string
    {
        return $this->modelType;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function operation(): string
    {
        return self::REMOVE;
    }

    public function handle(): void
    {
        $model = $this->newModel();
        $model->setAttribute($model->getKeyName(), $this->modelId);
        $model->unsearchableSync();

        Log::info('gallery_photo.search_cleanup.completed', [
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'operation' => self::REMOVE,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('gallery_photo.search_cleanup.failed', [
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'operation' => self::REMOVE,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function newModel(): Gallery|Photo
    {
        return match ($this->modelType) {
            self::GALLERY => new Gallery,
            self::PHOTO => new Photo,
            default => throw new InvalidArgumentException("Unsupported searchable model type: {$this->modelType}"),
        };
    }
}
