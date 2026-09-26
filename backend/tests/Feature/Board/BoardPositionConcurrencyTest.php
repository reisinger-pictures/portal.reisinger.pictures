<?php

namespace Tests\Feature\Board;

use App\Enums\Brand;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardPositionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'othr';

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_project_mutations_reindex_legacy_holes_and_keep_positions_dense(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
            'status' => 'anfrage',
            'position' => 0,
        ]);
        $second = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
            'status' => 'anfrage',
            'position' => 5,
        ]);

        // Store used to calculate max(position) + 1, which preserved the hole
        // and could collide with a concurrent store.  The serialized append
        // path must normalize the column before inserting.
        $created = $this->withHeaders($this->headers($admin))
            ->postJson('/api/management/projects', [
                'client_name' => 'Neuer Eintrag',
                'status' => 'anfrage',
            ]);
        $created->assertStatus(201);

        $this->assertDensePositions(Project::query()->where('brand', Brand::B2B->value)->where('status', 'anfrage'), 3);

        // Two independent move requests model the ordering that previously
        // interleaved between the read/count and reindex phases.
        $this->withHeaders($this->headers($admin))
            ->patchJson("/api/management/projects/{$created->json('project.id')}/move", [
                'status' => 'anfrage',
                'position' => 1,
            ])
            ->assertStatus(200);
        $this->withHeaders($this->headers($admin))
            ->patchJson("/api/management/projects/{$second->id}/move", [
                'status' => 'anfrage',
                'position' => 0,
            ])
            ->assertStatus(200);

        $this->assertDensePositions(Project::query()->where('brand', Brand::B2B->value)->where('status', 'anfrage'), 3);

        $removed = Project::query()
            ->where('brand', Brand::B2B->value)
            ->where('status', 'anfrage')
            ->orderBy('id')
            ->firstOrFail();
        $this->withHeaders($this->headers($admin))
            ->deleteJson("/api/management/projects/{$removed->id}")
            ->assertStatus(200);
        $this->assertDensePositions(Project::query()->where('brand', Brand::B2B->value)->where('status', 'anfrage'), 2);
    }

    public function test_photo_job_mutations_reindex_legacy_holes_and_keep_positions_dense(): void
    {
        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'status' => 'importiert',
            'position' => 0,
        ]);
        PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'status' => 'importiert',
            'position' => 9,
        ]);

        $created = $this->withHeaders($this->headers($photographer))
            ->postJson('/api/management/photo-jobs', [
                'title' => 'Neuer Auftrag',
                'status' => 'importiert',
            ]);
        $created->assertStatus(201);

        $this->assertDensePositions(PhotoJob::query()->where('brand', Brand::B2B->value)->where('status', 'importiert'), 3);

        $this->withHeaders($this->headers($photographer))
            ->patchJson("/api/management/photo-jobs/{$created->json('photo_job.id')}/move", [
                'status' => 'importiert',
                'position' => 0,
            ])
            ->assertStatus(200);

        $this->assertDensePositions(PhotoJob::query()->where('brand', Brand::B2B->value)->where('status', 'importiert'), 3);
    }

    public function test_reindex_does_not_change_positions_in_another_brand(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        $active = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
            'status' => 'anfrage',
            'position' => 0,
        ]);
        $foreign = Project::factory()->create([
            'brand' => self::OTHER_BRAND,
            'status' => 'anfrage',
            'position' => 7,
        ]);

        $this->withHeaders($this->headers($admin))
            ->patchJson("/api/management/projects/{$active->id}/move", [
                'status' => 'anfrage',
                'position' => 99,
            ])
            ->assertStatus(200);

        $this->assertSame(7, (int) $foreign->fresh()->position);
        $this->assertDensePositions(Project::query()->where('brand', Brand::B2B->value)->where('status', 'anfrage'), 1);

        $foreignPhotoJob = PhotoJob::factory()->create([
            'brand' => self::OTHER_BRAND,
            'status' => 'importiert',
            'position' => 8,
        ]);
        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $activePhotoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'status' => 'importiert',
            'position' => 0,
        ]);

        $this->withHeaders($this->headers($photographer))
            ->patchJson("/api/management/photo-jobs/{$activePhotoJob->id}/move", [
                'status' => 'importiert',
                'position' => 99,
            ])
            ->assertStatus(200);

        $this->assertSame(8, (int) $foreignPhotoJob->fresh()->position);
    }

    public function test_reindex_does_not_rewrite_positions_owned_by_another_user(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        $otherOwner = $this->userWithRole('admin', Brand::B2B->value);

        $otherProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $otherOwner->id,
            'status' => 'anfrage',
            'position' => 7,
        ]);
        $ownProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
            'status' => 'anfrage',
            'position' => 9,
        ]);

        $this->withHeaders($this->headers($admin))
            ->patchJson("/api/management/projects/{$ownProject->id}/move", [
                'status' => 'anfrage',
                'position' => 0,
            ])
            ->assertStatus(200);

        $this->assertSame(7, (int) $otherProject->fresh()->position);
        $this->assertSame(0, (int) $ownProject->fresh()->position);

        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $otherPhotographer = $this->userWithRole('photographer', Brand::B2B->value);
        $otherJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $otherPhotographer->id,
            'status' => 'importiert',
            'position' => 8,
        ]);
        $ownPhotoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
            'status' => 'importiert',
            'position' => 10,
        ]);

        $this->withHeaders($this->headers($photographer))
            ->patchJson("/api/management/photo-jobs/{$ownPhotoJob->id}/move", [
                'status' => 'importiert',
                'position' => 0,
            ])
            ->assertStatus(200);

        $this->assertSame(8, (int) $otherJob->fresh()->position);
        $this->assertSame(0, (int) $ownPhotoJob->fresh()->position);
    }

    public function test_super_admin_position_mutations_do_not_renumber_other_owners(): void
    {
        $superAdmin = $this->userWithRole('super_admin', null);
        $otherOwner = $this->userWithRole('admin', Brand::B2B->value);

        $otherProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $otherOwner->id,
            'status' => 'anfrage',
            'position' => 7,
        ]);
        $project = $this->withHeaders($this->headers($superAdmin))
            ->postJson('/api/management/projects', [
                'client_name' => 'Super-Admin-Projekt',
                'status' => 'anfrage',
            ]);
        $project->assertStatus(201);
        $project->assertJsonPath('project.position', 0);
        $this->assertSame(7, (int) $otherProject->fresh()->position);

        $otherJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $otherOwner->id,
            'status' => 'importiert',
            'position' => 8,
        ]);
        $photoJob = $this->withHeaders($this->headers($superAdmin))
            ->postJson('/api/management/photo-jobs', [
                'title' => 'Super-Admin-Auftrag',
                'status' => 'importiert',
            ]);
        $photoJob->assertStatus(201);
        $photoJob->assertJsonPath('photo_job.position', 0);
        $this->assertSame(8, (int) $otherJob->fresh()->position);
    }

    public function test_handoff_positions_are_serialized_and_dense_for_multiple_projects(): void
    {
        $superAdmin = $this->userWithRole('super_admin', null);
        PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
            'position' => 4,
        ]);
        $firstProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
        ]);
        $secondProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
        ]);

        $first = $this->withHeaders($this->headers($superAdmin))
            ->postJson("/api/management/projects/{$firstProject->id}/handoff");
        $first->assertStatus(201);
        $second = $this->withHeaders($this->headers($superAdmin))
            ->postJson("/api/management/projects/{$secondProject->id}/handoff");
        $second->assertStatus(201);

        $this->assertSame(1, $first->json('photo_job.position'));
        $this->assertSame(2, $second->json('photo_job.position'));
        $this->assertSame(
            [0, 1, 2],
            PhotoJob::query()->where('brand', Brand::B2B->value)->orderBy('position')->pluck('position')->map(static fn ($position): int => (int) $position)->all(),
        );
    }

    private function assertDensePositions(Builder $query, int $expectedCount): void
    {
        $positions = $query->orderBy('position')->pluck('position')->map(static fn ($position): int => (int) $position)->all();
        $this->assertCount($expectedCount, $positions);
        $this->assertSame(range(0, $expectedCount - 1), $positions);
    }

    private function userWithRole(string $role, ?string $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function headers(User $user): array
    {
        $token = auth('api')->login($user);

        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }
}
