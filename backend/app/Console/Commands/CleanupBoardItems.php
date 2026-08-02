<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PhotoJob;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupBoardItems extends Command
{
    protected $signature = 'app:cleanup-board-items';
    protected $description = 'Löscht Board-Einträge in Endstatus (Projekte/Photo-Jobs) nach einer konfigurierbaren Grace-Periode.';

    public function handle()
    {
        $graceDays = env('BOARD_CLEANUP_GRACE_DAYS', 30);
        $cutoffDate = Carbon::now()->subDays($graceDays);

        $projectCount = 0;
        $projects = Project::whereIn('status', ['bezahlt', 'storniert'])
                           ->where('updated_at', '<', $cutoffDate)
                           ->get();

        foreach ($projects as $project) {
            $clientName = $project->client_name;
            $status = $project->status;
            $projectId = $project->id;
            $project->delete();

            $this->info("Gelöscht: {$clientName}");
            Log::info("Automated cleanup: Deleted project {$clientName} (id {$projectId}, status {$status})");
            $projectCount++;
        }

        $photoJobCount = 0;
        $photoJobs = PhotoJob::whereIn('status', ['veroeffentlicht', 'abgebrochen'])
                             ->where('updated_at', '<', $cutoffDate)
                             ->get();

        foreach ($photoJobs as $photoJob) {
            $title = $photoJob->title;
            $status = $photoJob->status;
            $photoJobId = $photoJob->id;
            $photoJob->delete();

            $this->info("Gelöscht: {$title}");
            Log::info("Automated cleanup: Deleted photo job {$title} (id {$photoJobId}, status {$status})");
            $photoJobCount++;
        }

        $this->info("Cleanup abgeschlossen. {$projectCount} Projekte und {$photoJobCount} Photo-Jobs wurden dauerhaft gelöscht.");
    }
}
