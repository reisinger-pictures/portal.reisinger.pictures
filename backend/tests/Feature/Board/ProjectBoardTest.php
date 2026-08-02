<?php

namespace Tests\Feature\Board;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Project;
use App\Enums\Brand;
use App\Enums\ProjectStatus;
use App\Support\BrandRegistry;

class ProjectBoardTest extends TestCase
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

    public function test_super_admin_sees_all_items(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);

        $otherUser = User::factory()->create();
        Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $superAdmin->id]);
        Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id]);

        $response = $this->withHeaders($headers)->getJson('/api/management/projects');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('projects'));
    }

    public function test_admin_sees_only_own_and_assignee_items(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);
        $otherUser = User::factory()->create();

        Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $admin->id]);
        Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id]);
        Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $otherUser->id, 'assignee_id' => $admin->id]);

        $response = $this->withHeaders($headers)->getJson('/api/management/projects');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('projects'));
    }

    public function test_store_sets_owner_and_initial_status_and_position(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);

        $response = $this->withHeaders($headers)->postJson('/api/management/projects', [
            'client_name' => 'Testkunde',
            'email' => 'kunde@example.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('project.owner_id', $admin->id);
        $response->assertJsonPath('project.status', ProjectStatus::ANFRAGE->value);
        $response->assertJsonPath('project.brand', Brand::B2B->value);
        $this->assertDatabaseHas('projects', [
            'owner_id' => $admin->id,
            'status' => 'anfrage',
            'position' => 0,
        ]);
    }

    public function test_move_changes_status_and_logs_workflow(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);
        $project = Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $admin->id]);

        $response = $this->withHeaders($headers)->patchJson("/api/management/projects/{$project->id}/move", [
            'status' => ProjectStatus::ANGEBOT->value,
            'position' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('project.status', 'angebot');
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'angebot']);
        $this->assertDatabaseHas('workflow_logs', [
            'item_type' => 'project',
            'item_id' => $project->id,
            'from_status' => 'anfrage',
            'to_status' => 'angebot',
            'user_id' => $admin->id,
        ]);
    }

    public function test_move_with_position_only_does_not_log_workflow(): void
    {
        $admin = $this->createAdmin();
        $headers = $this->authHeaders($admin);
        $project = Project::factory()->create(['brand' => Brand::B2B, 'owner_id' => $admin->id, 'status' => 'anfrage']);

        $this->withHeaders($headers)->patchJson("/api/management/projects/{$project->id}/move", [
            'status' => 'anfrage',
            'position' => 5,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('workflow_logs', [
            'item_type' => 'project',
            'item_id' => $project->id,
        ]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'position' => 5]);
    }

    public function test_photographer_cannot_access_projects(): void
    {
        $photographer = $this->createPhotographer();
        $headers = $this->authHeaders($photographer);

        $this->withHeaders($headers)->getJson('/api/management/projects')->assertStatus(403);
    }

    public function test_brand_isolation_excludes_other_brand_items(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $headers = $this->authHeaders($superAdmin);

        Project::factory()->create(['brand' => Brand::B2B]);
        Project::factory()->create(['brand' => 'othr']);

        $response = $this->withHeaders($headers)->getJson('/api/management/projects');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('projects'));
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/management/projects')->assertStatus(401);
    }
}