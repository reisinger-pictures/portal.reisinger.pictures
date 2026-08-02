<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\PhotoJob;
use App\Enums\Brand;
use App\Enums\ProjectStatus;
use App\Enums\PhotoJobStatus;
use App\Support\BrandRegistry;

class CleanupBoardItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    private function backdate(Model $model, int $days): void
    {
        $model->updated_at = now()->subDays($days);
        $model->save();
    }

    public function test_old_terminal_project_is_deleted(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::BEZAHLT->value]);
        $this->backdate($project, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_old_storniert_project_is_deleted(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::STORNIERT->value]);
        $this->backdate($project, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_old_terminal_photo_job_is_deleted(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::VEROEFFENTLICHT->value]);
        $this->backdate($photoJob, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_old_abgebrochen_photo_job_is_deleted(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::ABGEBROCHEN->value]);
        $this->backdate($photoJob, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_young_terminal_item_is_kept(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::BEZAHLT->value]);
        $this->backdate($project, 10);

        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::VEROEFFENTLICHT->value]);
        $this->backdate($photoJob, 10);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_old_active_status_item_is_kept(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::ANFRAGE->value]);
        $this->backdate($project, 100);

        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::SHOOTING->value]);
        $this->backdate($photoJob, 100);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_command_prints_correct_counts(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => 'bezahlt']);
        $this->backdate($project, 40);
        $storniert = Project::factory()->create(['brand' => Brand::B2B, 'status' => 'storniert']);
        $this->backdate($storniert, 40);
        $published = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => 'veroeffentlicht']);
        $this->backdate($published, 40);
        $active = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => 'shooting']);
        $this->backdate($active, 40);

        $this->artisan('app:cleanup-board-items')
            ->expectsOutputToContain('2 Projekte und 1 Photo-Jobs')
            ->assertSuccessful();
    }

    public function test_command_runs_successfully_with_no_data(): void
    {
        $this->artisan('app:cleanup-board-items')
            ->expectsOutputToContain('0 Projekte und 0 Photo-Jobs')
            ->assertSuccessful();
    }
}
