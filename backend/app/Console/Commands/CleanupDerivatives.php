<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupDerivatives extends Command
{
    protected $signature = 'app:cleanup-derivatives';

    protected $description = 'Löscht WebP Thumbnails von Bildern, die seit über 14 Tagen nicht aufgerufen wurden.';

    public function handle(): int
    {
        $cutoffDate = now()->subDays(14);

        $photos = Photo::where('last_accessed_at', '<', $cutoffDate)
            ->orWhere(function ($query) use ($cutoffDate) {
                $query->whereNull('last_accessed_at')
                    ->where('created_at', '<', $cutoffDate);
            })
            ->get();

        $deletedPhotoCount = 0;
        $failedPaths = [];
        $disk = Storage::disk('photos');

        foreach ($photos as $photo) {
            $deletedForPhoto = false;
            $failedForPhoto = false;

            foreach (Photo::DERIVATIVE_SIZES as $size) {
                $imagePaths = [
                    $photo->gallery_id.'/_thumbs/'.$size.'/'.$photo->id.'.webp',
                    $photo->gallery_id.'/_thumbs/_watermarked/'.$size.'/'.$photo->id.'.webp',
                ];

                // Each derivative can have a provenance marker sibling. Both
                // are part of the derivative and must be removed together.
                $paths = array_merge($imagePaths, array_map(
                    static fn (string $path): string => $path.ImageProcessor::WATERMARK_MARKER_SUFFIX,
                    $imagePaths,
                ));

                foreach ($paths as $path) {
                    if (! $disk->exists($path)) {
                        continue;
                    }

                    // A false result is not enough: some adapters report failure
                    // while the path still exists. Treat both as an operational
                    // failure and never count the photo as fully cleaned.
                    if ($disk->delete($path) && ! $disk->exists($path)) {
                        $deletedForPhoto = true;

                        continue;
                    }

                    $failedForPhoto = true;
                    $failedPaths[] = $path;
                    Log::warning('Automated cleanup: failed to delete derivative', [
                        'photo_id' => $photo->id,
                        'path' => $path,
                    ]);
                }
            }

            if ($deletedForPhoto && ! $failedForPhoto) {
                $deletedPhotoCount++;
            }
        }

        if ($deletedPhotoCount > 0) {
            Log::info("Automated cleanup: Deleted WebP derivatives for {$deletedPhotoCount} stale photos.");
        }

        if ($failedPaths !== []) {
            Log::error('Automated cleanup: derivative deletion failures', [
                'failed_count' => count($failedPaths),
                'paths' => $failedPaths,
            ]);
            $failureCount = count($failedPaths);
            $failureLabel = $failureCount === 1 ? 'Löschvorgang' : 'Löschvorgänge';
            $this->error(
                "WebP-Derivate für {$deletedPhotoCount} inaktive Bilder bereinigt; "
                ."{$failureCount} {$failureLabel} fehlgeschlagen."
            );

            return self::FAILURE;
        }

        $this->info("WebP-Derivate für {$deletedPhotoCount} inaktive Bilder bereinigt.");

        return self::SUCCESS;
    }
}
