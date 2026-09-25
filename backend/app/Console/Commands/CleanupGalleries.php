<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\Photo;
use App\Services\GalleryPhotoCleanupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupGalleries extends Command
{
    protected $signature = 'app:cleanup-galleries';

    protected $description = 'Löscht abgelaufene Galerien und deren Dateien nach einer konfigurierbaren Grace-Periode.';

    public function handle(GalleryPhotoCleanupService $cleanup): int
    {
        $graceMonths = env('GALLERY_CLEANUP_GRACE_MONTHS', 3);
        $cutoffDate = Carbon::now()->subMonths($graceMonths);

        $expiredGalleries = Gallery::whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoffDate)
            ->get();

        $count = 0;
        $failures = 0;

        foreach ($expiredGalleries as $gallery) {
            $galleryId = (string) $gallery->getKey();
            $galleryName = $gallery->name;
            $galleryExpire = $gallery->expires_at;

            try {
                // The row and the public-photos cleanup intent share one DB
                // boundary. The intent is durable before COMMIT and dispatches
                // DeleteGalleryFolderJob only after the transaction succeeds.
                DB::transaction(function () use ($galleryId, $cleanup): void {
                    // Reload and lock for every retry attempt; the collection
                    // model may represent a transaction that was rolled back.
                    $lockedGallery = Gallery::query()
                        ->whereKey($galleryId)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $photoIds = Photo::query()
                        ->where('gallery_id', $galleryId)
                        ->lockForUpdate()
                        ->pluck('id')
                        ->map(static fn (mixed $photoId): string => (string) $photoId)
                        ->all();

                    $deleted = Gallery::withoutSyncingToSearch(
                        static fn (): bool => $lockedGallery->delete(),
                    );
                    if ($deleted !== true) {
                        throw new \RuntimeException('Gallery deletion was cancelled.');
                    }

                    $cleanup->afterGalleryDelete(
                        $galleryId,
                        'scheduled_gallery_cleanup',
                        $photoIds,
                    );
                }, 3);
            } catch (Throwable $exception) {
                $failures++;
                Log::error('gallery.cleanup.failed', [
                    'gallery_id' => $galleryId,
                    'gallery_name' => $galleryName,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->error("Löschen fehlgeschlagen: {$galleryName}");

                continue;
            }

            $this->info("Gelöscht: {$galleryName}");
            Log::info("Automated cleanup: Deleted gallery {$galleryName} (expired at {$galleryExpire})");
            $count++;
        }

        $this->info("Cleanup abgeschlossen. {$count} Galerien wurden dauerhaft gelöscht.");

        if ($failures > 0) {
            $this->warn("{$failures} Galerie(n) konnten nicht gelöscht werden.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
