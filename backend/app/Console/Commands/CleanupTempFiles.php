<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupTempFiles extends Command
{
    protected $signature = 'app:cleanup-temp';
    protected $description = 'Löscht temporäre Dateien (Caches & Artefakte), die älter als 24 Stunden sind.';

    public function handle()
    {
        $tempDir = storage_path('app/private/temp');
        $count = 0;

        if (is_dir($tempDir)) {
            $files = File::allFiles($tempDir);
            $now = now();

            foreach ($files as $file) {
                // Lösche Dateien, die älter als 24 Stunden sind
                if ($now->diffInHours(Carbon::createFromTimestamp($file->getMTime())) > 24) {
                    File::delete($file);
                    $count++;
                }
            }
        }

        $this->info("Temp-Ordner bereinigt: {$count} alte Dateien gelöscht.");
        Log::info("Temp-Ordner bereinigt: {$count} alte Dateien gelöscht.");
        return 0;
    }
}
