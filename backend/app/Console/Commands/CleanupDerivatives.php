<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CleanupDerivatives extends Command
{
    protected $signature = 'app:cleanup-derivatives';
    protected $description = 'Löscht WebP Thumbnails von Bildern, die seit über 14 Tagen nicht aufgerufen wurden.';

    public function handle()
    {
        $cutoffDate = now()->subDays(14);
        
        $photos = Photo::where('last_accessed_at', '<', $cutoffDate)
                       ->orWhere(function($q) use ($cutoffDate) {
                           $q->whereNull('last_accessed_at')
                             ->where('created_at', '<', $cutoffDate);
                       })->get();

        $count = 0;
        $basePath = rtrim(Storage::disk('photos')->path(''), '/');
        $sizes = \App\Models\Photo::DERIVATIVE_SIZES;

        foreach ($photos as $photo) {
            $deleted = false;
            foreach ($sizes as $size) {
                $thumbPath = $basePath . '/' . $photo->gallery_id . '/_thumbs/' . $size . '/' . $photo->id . '.webp';
                $wmThumbPath = $basePath . '/' . $photo->gallery_id . '/_thumbs/_watermarked/' . $size . '/' . $photo->id . '.webp';
                
                if (file_exists($thumbPath)) { @unlink($thumbPath); $deleted = true; }
                if (file_exists($wmThumbPath)) { @unlink($wmThumbPath); $deleted = true; }
            }
            if ($deleted) $count++;
        }

        if ($count > 0) Log::info("Automated cleanup: Deleted WebP derivatives for {$count} stale photos.");
        $this->info("WebP-Derivate für {$count} inaktive Bilder bereinigt.");
    }
}
