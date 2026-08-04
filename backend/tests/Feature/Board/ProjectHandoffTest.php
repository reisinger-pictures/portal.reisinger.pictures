<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Project;
use App\Models\PhotoJob;
use App\Enums\Brand;
use App\Enums\ProjectStatus;
use App\Enums\PhotoJobStatus;
use App\Support\BrandRegistry;

class ProjectHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->roles()->attach($role);
        return $user;
    }

    private function createPhotographer(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'photographer']);
        $user->roles()->attach($role);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = auth('api')->login($user);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_super_admin_handoff_creates_linked_photo_job(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
            'client_name' => 'Testkunde',
            'status' => ProjectStatus::BEAUFTRAGT->value,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff");

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.title', 'Testkunde');
        $response->assertJsonPath('photo_job.status', PhotoJobStatus::initial()->value);
        $response->assertJsonPath('photo_job.brand', Brand::B2B->value);
        $response->assertJsonPath('photo_job.owner_id', $superAdmin->id);

        $photoJobId = $response->json('photo_job.id');
        $this->assertDatabaseHas('photo_jobs', ['id' => $photoJobId, 'title' => 'Testkunde']);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'linked_photo_job_id' => $photoJobId]);
    }

    public function test_handoff_sets_next_position_per_brand(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id, 'position' => 4]);
        $project = Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id]);

        $response = $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff");

        $response->assertStatus(201);
        $response->assertJsonPath('photo_job.position', 5);
    }

    public function test_handoff_does_not_change_project_status(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
            'status' => ProjectStatus::BEAUFTRAGT->value,
        ]);

        $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff")->assertStatus(201);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => ProjectStatus::BEAUFTRAGT->value]);
    }

    public function test_second_handoff_returns_422(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        $photoJob = PhotoJob::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id]);
        $project = Project::factory()->create([
            'brand' => Brand::B2B,
            'owner_id' => $superAdmin->id,
            'linked_photo_job_id' => $photoJob->id,
        ]);

        $response = $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff");
        $response->assertStatus(422);
        $this->assertSame('already_handed_off', $response->json('message'));
    }

    public function test_admin_cannot_handoff(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);
        $project = Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $admin->id]);

        $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff")->assertStatus(403);
    }

    public function test_photographer_cannot_handoff(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);
        $project = Project::factory()->create(['brand' => Brand::B2B]);

        $this->withHeaders($headers)->postJson("/api/management/projects/{$project->id}/handoff")->assertStatus(403);
    }

    public function test_handoff_respects_brand_isolation(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);
        $otherBrandProject = Project::factory()->create(['brand' => 'othr']);

        $this->withHeaders($headers)->postJson("/api/management/projects/{$otherBrandProject->id}/handoff")->assertStatus(404);
        $this->assertDatabaseHas('projects', ['id' => $otherBrandProject->id, 'linked_photo_job_id' => null]);
    }

    public function test_unauthenticated_gets_401_on_handoff(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        $this->postJson("/api/management/projects/{$id}/handoff")->assertStatus(401);
    }
}
