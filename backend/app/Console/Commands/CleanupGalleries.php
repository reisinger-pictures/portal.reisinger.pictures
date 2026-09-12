<?php

namespace App\Console\Commands;

use App\Jobs\DeleteGalleryFolderJob;
use App\Models\Gallery;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupGalleries extends Command
{
    protected $signature = 'app:cleanup-galleries';

    protected $description = 'Löscht abgelaufene Galerien und deren Dateien nach einer konfigurierbaren Grace-Periode.';

    public function handle()
    {
        $graceMonths = env('GALLERY_CLEANUP_GRACE_MONTHS', 3);
        $cutoffDate = Carbon::now()->subMonths($graceMonths);

        $expiredGalleries = Gallery::whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoffDate)
            ->get();

        $count = 0;

        foreach ($expiredGalleries as $gallery) {
            $galleryId = (string) $gallery->id;
            $galleryName = $gallery->name;
            $galleryExpire = $gallery->expires_at;

            // Delete the database record first, wrapped in a transaction. If
            // this fails the gallery and its files stay intact — we never end
            // up with a gallery record pointing at already-deleted files.
            DB::transaction(function () use ($gallery) {
                $gallery->delete();
            });

            // Files are removed only after the record is gone. The `photos`
            // disk uses `throw => false`, so a failure is surfaced explicitly
            // and handed to the retryable queue job instead of being swallowed.
            $disk = Storage::disk('photos');
            if (! $disk->deleteDirectory($galleryId) || $disk->exists($galleryId)) {
                Log::error('Automated cleanup: failed to delete gallery files', [
                    'gallery_id' => $galleryId,
                    'gallery_name' => $galleryName,
                ]);
                DeleteGalleryFolderJob::dispatch($galleryId);
            }

            $this->info("Gelöscht: {$galleryName}");
            Log::info("Automated cleanup: Deleted gallery {$galleryName} (expired at {$galleryExpire})");
            $count++;
        }

        $this->info("Cleanup abgeschlossen. {$count} Galerien wurden dauerhaft gelöscht.");
    }
}
