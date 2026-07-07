<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;

class OrgControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        return auth('api')->login($user);
    }

    private function clientToken(): string
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        return auth('api')->login($user);
    }

    public function test_admin_can_list_orgs(): void
    {
        Org::factory()->count(3)->create();
        $token = $this->adminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/orgs');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_regular_user_cannot_list_orgs(): void
    {
        Org::factory()->count(2)->create();
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/orgs')
            ->assertStatus(403);
    }

    public function test_admin_can_create_org(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs', [
                'name' => 'Test Mandant',
                'domain' => 'test.example.com',
                'invoice_frequency' => 'monthly',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orgs', ['name' => 'Test Mandant', 'domain' => 'test.example.com']);
    }

    public function test_create_org_validates_required_fields(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs', [])
            ->assertStatus(422);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs', ['name' => 'No Frequency'])
            ->assertStatus(422);
    }

    public function test_create_org_validates_invoice_frequency(): void
    {
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs', [
                'name' => 'Bad Freq',
                'invoice_frequency' => 'yearly',
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_create_org(): void
    {
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orgs', [
                'name' => 'Hack Attempt',
                'invoice_frequency' => 'immediate',
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_update_org(): void
    {
        $org = Org::factory()->create(['name' => 'Old Name', 'invoice_frequency' => 'immediate']);
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'New Name',
                'invoice_frequency' => 'monthly',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orgs', ['id' => $org->id, 'name' => 'New Name', 'invoice_frequency' => 'monthly']);
    }

    public function test_non_admin_cannot_update_org(): void
    {
        $org = Org::factory()->create();
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson("/api/management/orgs/{$org->id}", [
                'name' => 'Hacked',
                'invoice_frequency' => 'monthly',
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_delete_org(): void
    {
        $org = Org::factory()->create();
        $token = $this->adminToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/orgs/{$org->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertModelMissing($org);
    }

    public function test_non_admin_cannot_delete_org(): void
    {
        $org = Org::factory()->create();
        $token = $this->clientToken();

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->deleteJson("/api/management/orgs/{$org->id}")
            ->assertStatus(403);
    }
}
