<?php

namespace App\Console\Commands;

use App\Models\PhotoJob;
use App\Models\Project;
use App\Services\BoardPositionService;
use App\Support\BrandRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class CleanupBoardItems extends Command
{
    protected $signature = 'app:cleanup-board-items';

    protected $description = 'Löscht Board-Einträge in Endstatus (Projekte/Photo-Jobs) nach einer konfigurierbaren Grace-Periode.';

    public function handle(BoardPositionService $boardPositions): int
    {
        $graceDays = max(0, (int) env('BOARD_CLEANUP_GRACE_DAYS', 7));
        $cutoffDate = Carbon::now()->subDays($graceDays);
        $brands = $this->boardBrands();
        $projectCount = 0;
        $photoJobCount = 0;

        // Delete all eligible projects first. This is deliberately a separate
        // phase from photo-job deletion: a legacy project may reference a job
        // in another brand, and that job must see the post-project reference
        // state regardless of the order in which brands are visited.
        foreach ($brands as $brand) {
            $result = $boardPositions->transaction(
                $brand,
                ['projects', 'photo_jobs'],
                function () use ($brand, $cutoffDate, $boardPositions): array {
                    $deleted = [];
                    $columns = [];

                    $projects = Project::query()
                        ->forBrand($brand)
                        ->whereIn('status', ['bezahlt', 'storniert'])
                        ->where('updated_at', '<', $cutoffDate)
                        ->get();

                    foreach ($projects as $project) {
                        $deleted[] = [
                            'id' => $project->id,
                            'name' => $project->client_name,
                            'status' => $project->status,
                        ];
                        $columns[] = [
                            'owner_id' => (string) $project->owner_id,
                            'status' => (string) $project->status,
                        ];
                        $project->delete();
                    }

                    $this->reindexDeletedColumns(
                        $boardPositions,
                        Project::query()->forBrand($brand),
                        $columns,
                    );

                    return compact('deleted', 'columns');
                },
            );

            $projectCount += $this->reportDeletedProjects($result['deleted']);
        }

        // Only after every project phase has committed do we evaluate the
        // handoff reference set and remove unreferenced terminal jobs.
        foreach ($brands as $brand) {
            $result = $boardPositions->transaction(
                $brand,
                ['projects', 'photo_jobs'],
                function () use ($brand, $cutoffDate, $boardPositions): array {
                    $referencedPhotoJobIds = Project::query()
                        ->whereNotNull('linked_photo_job_id')
                        ->pluck('linked_photo_job_id');
                    $deleted = [];
                    $columns = [];

                    $photoJobs = PhotoJob::query()
                        ->forBrand($brand)
                        ->whereIn('status', ['exportiert', 'abgebrochen'])
                        ->where('updated_at', '<', $cutoffDate)
                        ->whereNotIn('id', $referencedPhotoJobIds)
                        ->get();

                    foreach ($photoJobs as $photoJob) {
                        $deleted[] = [
                            'id' => $photoJob->id,
                            'title' => $photoJob->title,
                            'status' => $photoJob->status,
                        ];
                        $columns[] = [
                            'owner_id' => (string) $photoJob->owner_id,
                            'status' => (string) $photoJob->status,
                        ];
                        $photoJob->delete();
                    }

                    $this->reindexDeletedColumns(
                        $boardPositions,
                        PhotoJob::query()->forBrand($brand),
                        $columns,
                    );

                    return compact('deleted', 'columns');
                },
            );

            $photoJobCount += $this->reportDeletedPhotoJobs($result['deleted']);
        }

        $this->info("Cleanup abgeschlossen. {$projectCount} Projekte und {$photoJobCount} Photo-Jobs wurden dauerhaft gelöscht.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: string, name: string, status: string}>  $result
     */
    private function reportDeletedProjects(array $result): int
    {
        foreach ($result as $project) {
            $this->info('Gelöscht: '.$project['name']);
            Log::info(
                "Automated cleanup: Deleted project {$project['name']} "
                ."(id {$project['id']}, status {$project['status']})",
            );
        }

        return count($result);
    }

    /**
     * @param  list<array{id: string, title: string, status: string}>  $result
     */
    private function reportDeletedPhotoJobs(array $result): int
    {
        foreach ($result as $photoJob) {
            $this->info('Gelöscht: '.$photoJob['title']);
            Log::info(
                "Automated cleanup: Deleted photo job {$photoJob['title']} "
                ."(id {$photoJob['id']}, status {$photoJob['status']})",
            );
        }

        return count($result);
    }

    /**
     * @param  list<array{owner_id: string, status: string}>  $columns
     */
    private function reindexDeletedColumns(
        BoardPositionService $boardPositions,
        Builder $query,
        array $columns,
    ): void {
        $seen = [];

        foreach ($columns as $column) {
            $key = $column['owner_id']."\0".$column['status'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $boardPositions->reindexOwnerColumn(
                $query,
                $column['status'],
                $column['owner_id'],
            );
        }
    }

    /**
     * @return list<string|null>
     */
    private function boardBrands(): array
    {
        return Project::query()
            ->distinct()
            ->pluck('brand')
            ->merge(PhotoJob::query()->distinct()->pluck('brand'))
            ->map(static fn (mixed $brand): ?string => BrandRegistry::normalizeId($brand))
            ->unique()
            ->values()
            ->all();
    }
}
