<?php

namespace Tests\Feature\Board;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardRelationshipIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_BRAND = 'othr';

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);
    }

    public function test_project_assignee_and_linked_job_are_brand_scoped(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        $foreignAssignee = User::factory()->create(['brand' => self::OTHER_BRAND]);
        $brandlessAssignee = User::factory()->create(['brand' => null]);

        foreach ([$foreignAssignee->id, $brandlessAssignee->id] as $assigneeId) {
            $this->withHeaders($this->headers($admin))
                ->postJson('/api/management/projects', [
                    'client_name' => 'Nicht erlaubt',
                    'assignee_id' => $assigneeId,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('assignee_id');
        }

        $this->assertDatabaseCount('projects', 0);

        $foreignJob = PhotoJob::factory()->create([
            'brand' => self::OTHER_BRAND,
            'owner_id' => $admin->id,
        ]);
        $brandlessJob = PhotoJob::factory()->create([
            'brand' => null,
            'owner_id' => $admin->id,
        ]);

        foreach ([$foreignJob->id, $brandlessJob->id] as $photoJobId) {
            $this->withHeaders($this->headers($admin))
                ->postJson('/api/management/projects', [
                    'client_name' => 'Nicht erlaubt',
                    'linked_photo_job_id' => $photoJobId,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('linked_photo_job_id');
        }

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_project_link_requires_a_job_visible_to_the_actor(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        $otherOwner = User::factory()->create(['brand' => Brand::B2B->value]);
        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B->value,
            'owner_id' => $otherOwner->id,
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson('/api/management/projects', [
                'client_name' => 'Nicht sichtbarer Auftrag',
                'linked_photo_job_id' => $photoJob->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('linked_photo_job_id');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_photo_job_assignee_and_target_gallery_are_brand_and_access_scoped(): void
    {
        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $foreignAssignee = User::factory()->create(['brand' => self::OTHER_BRAND]);
        $brandlessAssignee = User::factory()->create(['brand' => null]);

        foreach ([$foreignAssignee->id, $brandlessAssignee->id] as $assigneeId) {
            $this->withHeaders($this->headers($photographer))
                ->postJson('/api/management/photo-jobs', [
                    'title' => 'Nicht erlaubt',
                    'assignee_id' => $assigneeId,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('assignee_id');
        }

        $foreignGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);
        $brandlessGallery = Gallery::factory()->create(['brand' => null]);
        $restrictedGallery = Gallery::factory()->create([
            'brand' => Brand::B2B->value,
            'restricted_photographers' => true,
        ]);

        foreach ([$foreignGallery->id, $brandlessGallery->id, $restrictedGallery->id] as $galleryId) {
            $this->withHeaders($this->headers($photographer))
                ->postJson('/api/management/photo-jobs', [
                    'title' => 'Nicht erlaubt',
                    'target_gallery_id' => $galleryId,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('target_gallery_id');
        }

        $this->assertDatabaseCount('photo_jobs', 0);

        $sameBrandAssignee = User::factory()->create(['brand' => Brand::B2B->value]);
        $sameBrandGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $response = $this->withHeaders($this->headers($photographer))
            ->postJson('/api/management/photo-jobs', [
                'title' => 'Erlaubt',
                'assignee_id' => $sameBrandAssignee->id,
                'target_gallery_id' => $sameBrandGallery->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.assignee_id', $sameBrandAssignee->id);
        $response->assertJsonPath('photo_job.target_gallery_id', $sameBrandGallery->id);
    }

    public function test_target_gallery_rejects_a_same_brand_gallery_under_a_foreign_or_null_parent(): void
    {
        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $foreignParent = GalleryGroup::factory()->create(['brand' => self::OTHER_BRAND]);
        $nullParent = GalleryGroup::factory()->create(['brand' => null]);

        foreach ([$foreignParent, $nullParent] as $parent) {
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B->value,
                'gallery_group_id' => $parent->id,
            ]);

            $this->withHeaders($this->headers($photographer))
                ->postJson('/api/management/photo-jobs', [
                    'title' => 'Fremder Galeriebaum',
                    'target_gallery_id' => $gallery->id,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('target_gallery_id');
        }

        $this->assertDatabaseCount('photo_jobs', 0);
    }

    public function test_photo_job_count_null_is_rejected_but_explicit_zero_and_relationship_clearing_work(): void
    {
        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $assignee = User::factory()->create(['brand' => Brand::B2B->value]);
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);

        $this->withHeaders($this->headers($photographer))
            ->postJson('/api/management/photo-jobs', [
                'title' => 'Null zählt nicht',
                'total_count' => null,
                'selected_count' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['total_count', 'selected_count']);

        $created = $this->withHeaders($this->headers($photographer))
            ->postJson('/api/management/photo-jobs', [
                'title' => 'Null wird zu null',
                'total_count' => 0,
                'selected_count' => 0,
                'assignee_id' => $assignee->id,
                'target_gallery_id' => $gallery->id,
                'lightroom_catalog' => '2026-09',
                'notes' => 'wird gelöscht',
            ]);

        $created->assertStatus(201);
        $created->assertJsonPath('photo_job.total_count', 0);
        $created->assertJsonPath('photo_job.selected_count', 0);
        $id = $created->json('photo_job.id');

        $this->withHeaders($this->headers($photographer))
            ->putJson("/api/management/photo-jobs/{$id}", [
                'total_count' => 10,
                'selected_count' => 5,
            ])
            ->assertStatus(200)
            ->assertJsonPath('photo_job.total_count', 10)
            ->assertJsonPath('photo_job.selected_count', 5);

        $this->withHeaders($this->headers($photographer))
            ->putJson("/api/management/photo-jobs/{$id}", [
                'total_count' => null,
                'selected_count' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['total_count', 'selected_count']);

        $cleared = $this->withHeaders($this->headers($photographer))
            ->putJson("/api/management/photo-jobs/{$id}", [
                'total_count' => 0,
                'selected_count' => 0,
                'assignee_id' => null,
                'target_gallery_id' => null,
                'lightroom_catalog' => null,
                'notes' => null,
            ]);

        $cleared->assertStatus(200);
        $this->assertDatabaseHas('photo_jobs', [
            'id' => $id,
            'total_count' => 0,
            'selected_count' => 0,
            'assignee_id' => null,
            'target_gallery_id' => null,
            'lightroom_catalog' => null,
            'notes' => null,
        ]);
    }

    public function test_photo_job_model_also_rejects_null_counters_outside_http_validation(): void
    {
        $owner = $this->userWithRole('photographer', Brand::B2B->value);

        $this->expectException(\InvalidArgumentException::class);
        PhotoJob::create([
            'brand' => Brand::B2B,
            'owner_id' => $owner->id,
            'title' => 'Direkter Null-Write',
            'total_count' => null,
        ]);
    }

    public function test_relationship_updates_are_scoped_without_blocking_explicit_null_clearing(): void
    {
        $admin = $this->userWithRole('admin', Brand::B2B->value);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
        ]);
        $foreignAssignee = User::factory()->create(['brand' => self::OTHER_BRAND]);
        $foreignJob = PhotoJob::factory()->create([
            'brand' => self::OTHER_BRAND,
            'owner_id' => $admin->id,
        ]);

        $this->withHeaders($this->headers($admin))
            ->putJson("/api/management/projects/{$project->id}", [
                'assignee_id' => $foreignAssignee->id,
                'linked_photo_job_id' => $foreignJob->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assignee_id', 'linked_photo_job_id']);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'assignee_id' => null,
            'linked_photo_job_id' => null,
        ]);

        $this->withHeaders($this->headers($admin))
            ->putJson("/api/management/projects/{$project->id}", [
                'assignee_id' => null,
                'linked_photo_job_id' => null,
            ])
            ->assertStatus(200);

        $photographer = $this->userWithRole('photographer', Brand::B2B->value);
        $photoJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $photographer->id,
        ]);
        $foreignGallery = Gallery::factory()->create(['brand' => self::OTHER_BRAND]);

        $this->withHeaders($this->headers($photographer))
            ->putJson("/api/management/photo-jobs/{$photoJob->id}", [
                'target_gallery_id' => $foreignGallery->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_gallery_id');

        $this->assertNull($photoJob->fresh()->target_gallery_id);
    }

    public function test_handoff_rejects_an_existing_foreign_link_without_creating_a_job(): void
    {
        $superAdmin = $this->userWithRole('super_admin', null);
        $foreignJob = PhotoJob::factory()->create([
            'brand' => self::OTHER_BRAND,
            'owner_id' => $superAdmin->id,
        ]);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
            'linked_photo_job_id' => $foreignJob->id,
        ]);

        $this->withHeaders($this->headers($superAdmin))
            ->postJson("/api/management/projects/{$project->id}/handoff")
            ->assertStatus(422);

        $this->assertDatabaseCount('photo_jobs', 1);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'linked_photo_job_id' => $foreignJob->id,
        ]);
    }

    public function test_boards_exclude_foreign_and_null_brand_items_from_cross_brand_reads(): void
    {
        $superAdmin = $this->userWithRole('super_admin', null);
        $activeProject = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
        ]);
        Project::factory()->create([
            'brand' => self::OTHER_BRAND,
            'owner_id' => $superAdmin->id,
        ]);
        Project::factory()->create([
            'brand' => null,
            'owner_id' => $superAdmin->id,
        ]);

        $activeJob = PhotoJob::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
        ]);
        PhotoJob::factory()->create([
            'brand' => self::OTHER_BRAND,
            'owner_id' => $superAdmin->id,
        ]);
        PhotoJob::factory()->create([
            'brand' => null,
            'owner_id' => $superAdmin->id,
        ]);

        $projects = $this->withHeaders($this->headers($superAdmin))
            ->getJson('/api/management/projects')
            ->assertOk()
            ->json('projects');
        $photoJobs = $this->withHeaders($this->headers($superAdmin))
            ->getJson('/api/management/photo-jobs')
            ->assertOk()
            ->json('photo_jobs');

        $this->assertSame([$activeProject->id], array_column($projects, 'id'));
        $this->assertSame([$activeJob->id], array_column($photoJobs, 'id'));
    }

    public function test_null_brand_non_super_admin_cannot_enter_a_board(): void
    {
        $admin = $this->userWithRole('admin', null);

        $this->withHeaders($this->headers($admin))
            ->getJson('/api/management/projects')
            ->assertStatus(403);
    }

    public function test_brand_bound_actor_cannot_use_a_board_for_another_brand(): void
    {
        $admin = $this->userWithRole('admin', self::OTHER_BRAND);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $admin->id,
        ]);

        $this->withHeaders($this->headers($admin))
            ->getJson('/api/management/projects')
            ->assertStatus(403);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
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
