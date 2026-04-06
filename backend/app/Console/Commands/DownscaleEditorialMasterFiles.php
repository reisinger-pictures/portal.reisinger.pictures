<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DownscaleEditorialMasterFiles extends Command
{
    protected $signature = 'app:downscale-editorial';
    protected $description = 'Skaliert redaktionelle Master-Dateien, die älter als 7 Tage sind, zur Speicherplatzersparnis auf 2560px herunter.';

    public function handle()
    {
        $photos = Photo::where('created_at', '<', now()->subDays(7))
                       ->where('is_downscaled', false)
                       ->with(['gallery.galleryGroup']) // Wichtig für Accessor-Traversal
                       ->get();

        $count = 0;
        $basePath = rtrim(Storage::disk('photos')->path(''), '/');

        foreach ($photos as $photo) {
            if ($photo->effective_is_editorial_only) {
                $path = $basePath . '/' . $photo->gallery_id . '/' . $photo->filename;
                
                if (file_exists($path)) {
                    // Verwende ImageMagick CLI als performanten Batch-Prozessor
                    // '>' verhindert das Hochskalieren von Bildern, die bereits kleiner als 2560px sind
                    $process = new Process(['magick', $path, '-resize', '2560x2560>', $path]);
                    $process->run();
                    
                    if (!$process->isSuccessful()) {
                        // Fallback für ältere Systeme (convert statt magick)
                        $process = new Process(['convert', $path, '-resize', '2560x2560>', $path]);
                        $process->run();
                    }

                    if ($process->isSuccessful()) {
                        $size = @getimagesize($path);
                        $photo->update([
                            'is_downscaled' => true,
                            'width' => $size ? $size[0] : $photo->width,
                            'height' => $size ? $size[1] : $photo->height,
                        ]);
                        $count++;
                    } else {
                        Log::error("Downscale failed for photo {$photo->id}: " . $process->getErrorOutput());
                    }
                }
            }
        }

        if ($count > 0) Log::info("Automated storage lifecycle: Downscaled {$count} editorial master files.");
        $this->info("{$count} redaktionelle Master-Dateien wurden herunterskaliert.");
    }
}
