<?php

namespace Tests\Feature\Board;

use App\Enums\Brand;
use App\Enums\PhotoJobStatus;
use App\Enums\ProjectStatus;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
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

    public function test_old_terminal_photo_job_referenced_by_project_is_kept(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($photoJob, 40);

        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::RECHNUNG->value, 'linked_photo_job_id' => $photoJob->id]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'linked_photo_job_id' => $photoJob->id]);
    }

    public function test_old_abgebrochen_photo_job_referenced_by_project_is_kept(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::ABGEBROCHEN->value]);
        $this->backdate($photoJob, 40);

        Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::ANFRAGE->value, 'linked_photo_job_id' => $photoJob->id]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_referenced_terminal_job_is_kept_while_unreferenced_terminal_job_is_deleted(): void
    {
        $referenced = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($referenced, 40);

        $orphaned = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($orphaned, 40);

        Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::RECHNUNG->value, 'linked_photo_job_id' => $referenced->id]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('photo_jobs', ['id' => $referenced->id]);
        $this->assertDatabaseMissing('photo_jobs', ['id' => $orphaned->id]);
    }

    public function test_cleanup_reindexes_remaining_rows_after_deleting_holes(): void
    {
        $owner = User::factory()->create();

        $deletedProject = Project::factory()->create([
            'owner_id' => $owner->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 4,
        ]);
        $this->backdate($deletedProject, 40);

        $remainingProject = Project::factory()->create([
            'owner_id' => $owner->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 9,
        ]);

        $deletedPhotoJob = PhotoJob::factory()->create([
            'owner_id' => $owner->id,
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 7,
        ]);
        $this->backdate($deletedPhotoJob, 40);

        $remainingPhotoJob = PhotoJob::factory()->create([
            'owner_id' => $owner->id,
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 12,
        ]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $deletedProject->id]);
        $this->assertDatabaseHas('projects', ['id' => $remainingProject->id, 'position' => 0]);
        $this->assertDatabaseMissing('photo_jobs', ['id' => $deletedPhotoJob->id]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $remainingPhotoJob->id, 'position' => 0]);
    }

    public function test_cleanup_does_not_renumber_another_owner_s_board(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $assignee = User::factory()->create();

        $deleted = Project::factory()->create([
            'owner_id' => $owner->id,
            'assignee_id' => $assignee->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 4,
        ]);
        $this->backdate($deleted, 40);

        $otherRemaining = Project::factory()->create([
            'owner_id' => $otherOwner->id,
            'assignee_id' => $assignee->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 9,
        ]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $deleted->id]);
        $this->assertDatabaseHas('projects', ['id' => $otherRemaining->id, 'position' => 9]);
    }

    public function test_cleanup_reindexes_only_deleted_owner_status_pairs(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $deletedAPaid = Project::factory()->create([
            'owner_id' => $ownerA->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 4,
        ]);
        $this->backdate($deletedAPaid, 40);

        $deletedBCancelled = Project::factory()->create([
            'owner_id' => $ownerB->id,
            'status' => ProjectStatus::STORNIERT->value,
            'position' => 8,
        ]);
        $this->backdate($deletedBCancelled, 40);

        $crossTimestamp = now()->subDays(2)->startOfSecond();
        $ownerACancelled = Project::factory()->create([
            'owner_id' => $ownerA->id,
            'status' => ProjectStatus::STORNIERT->value,
            'position' => 11,
        ]);
        $ownerBPaid = Project::factory()->create([
            'owner_id' => $ownerB->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 13,
        ]);
        $ownerACancelled->updated_at = $crossTimestamp;
        $ownerACancelled->saveQuietly();
        $ownerBPaid->updated_at = $crossTimestamp;
        $ownerBPaid->saveQuietly();
        $ownerACancelledTimestamp = $ownerACancelled->fresh()->updated_at->toDateTimeString();
        $ownerBPaidTimestamp = $ownerBPaid->fresh()->updated_at->toDateTimeString();

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $deletedAPaid->id]);
        $this->assertDatabaseMissing('projects', ['id' => $deletedBCancelled->id]);
        $this->assertDatabaseHas('projects', ['id' => $ownerACancelled->id, 'position' => 11]);
        $this->assertDatabaseHas('projects', ['id' => $ownerBPaid->id, 'position' => 13]);
        $this->assertSame(
            $ownerACancelledTimestamp,
            $ownerACancelled->fresh()->updated_at->toDateTimeString(),
        );
        $this->assertSame(
            $ownerBPaidTimestamp,
            $ownerBPaid->fresh()->updated_at->toDateTimeString(),
        );
    }

    public function test_cleanup_handles_legacy_null_brand_rows_without_widening_the_active_board(): void
    {
        $owner = User::factory()->create();
        $deleted = Project::factory()->create([
            'brand' => null,
            'owner_id' => $owner->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 5,
        ]);
        $this->backdate($deleted, 40);
        $remaining = Project::factory()->create([
            'brand' => null,
            'owner_id' => $owner->id,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 9,
        ]);
        $deletedPhotoJob = PhotoJob::factory()->create([
            'brand' => null,
            'owner_id' => $owner->id,
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 7,
        ]);
        $this->backdate($deletedPhotoJob, 40);
        $remainingPhotoJob = PhotoJob::factory()->create([
            'brand' => null,
            'owner_id' => $owner->id,
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 12,
        ]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $deleted->id]);
        $this->assertDatabaseHas('projects', ['id' => $remaining->id, 'position' => 0]);
        $this->assertDatabaseMissing('photo_jobs', ['id' => $deletedPhotoJob->id]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $remainingPhotoJob->id, 'position' => 0]);
    }

    public function test_cleanup_reindexes_only_the_affected_brand(): void
    {
        $activeProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 8,
        ]);
        $this->backdate($activeProject, 40);
        $remainingProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 11,
        ]);

        $foreignProject = Project::factory()->create([
            'brand' => 'othr',
            'status' => ProjectStatus::ANFRAGE->value,
            'position' => 8,
        ]);
        $foreignRemainingProject = Project::factory()->create([
            'brand' => 'othr',
            'status' => ProjectStatus::BEZAHLT->value,
            'position' => 11,
        ]);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $remainingProject->id, 'position' => 11]);
        $this->assertDatabaseHas('projects', ['id' => $foreignProject->id, 'position' => 8]);
        $this->assertDatabaseHas('projects', ['id' => $foreignRemainingProject->id, 'position' => 11]);
    }

    public function test_cleanup_rechecks_cross_brand_references_after_all_project_phases(): void
    {
        $photoJob = PhotoJob::factory()->create([
            'brand' => 'othr',
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 4,
        ]);
        $this->backdate($photoJob, 40);

        // Insert the foreign-brand project first so the old per-brand
        // project-then-job loop would visit othr before rp and miss the
        // newly-unreferenced job until the next run.
        Project::factory()->create([
            'brand' => 'othr',
            'status' => ProjectStatus::ANFRAGE->value,
        ]);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'status' => ProjectStatus::BEZAHLT->value,
            'linked_photo_job_id' => $photoJob->id,
        ]);
        $this->backdate($project, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_cleanup_can_release_a_job_after_its_referencing_project_is_deleted(): void
    {
        $photoJob = PhotoJob::factory()->create([
            'status' => PhotoJobStatus::EXPORTIERT->value,
            'position' => 4,
        ]);
        $this->backdate($photoJob, 40);

        $project = Project::factory()->create([
            'status' => ProjectStatus::BEZAHLT->value,
            'linked_photo_job_id' => $photoJob->id,
        ]);
        $this->backdate($project, 40);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_young_terminal_item_is_kept(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::BEZAHLT->value]);
        $this->backdate($project, 5);

        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($photoJob, 5);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_exportiert_photo_job_older_than_grace_is_deleted(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($photoJob, 10);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseMissing('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_young_exportiert_photo_job_is_kept(): void
    {
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::EXPORTIERT->value]);
        $this->backdate($photoJob, 5);

        $this->artisan('app:cleanup-board-items')->assertSuccessful();

        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJob->id]);
    }

    public function test_old_active_status_item_is_kept(): void
    {
        $project = Project::factory()->create(['brand' => Brand::B2B, 'status' => ProjectStatus::ANFRAGE->value]);
        $this->backdate($project, 100);

        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => PhotoJobStatus::IMPORTIERT->value]);
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
        $published = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => 'exportiert']);
        $this->backdate($published, 40);
        $active = PhotoJob::factory()->create(['brand' => Brand::B2B, 'status' => 'importiert']);
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
