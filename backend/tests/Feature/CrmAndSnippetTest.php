<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CrmAndSnippetTest extends TestCase {
    use RefreshDatabase;

    public function test_super_admin_can_manage_customers_and_snippets() {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        // Test Customer Creation
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/customers', ['name' => 'Test Kunde', 'email' => 'test@kunde.de']);
        $res->assertStatus(200);
        $this->assertDatabaseHas('customers', ['email' => 'test@kunde.de']);

        // Test Snippet Creation
        $res2 = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/text-snippets', ['title' => 'Test Snippet', 'content_html' => '<p>Hello</p>']);
        $res2->assertStatus(200);
        $this->assertDatabaseHas('text_snippets', ['title' => 'Test Snippet']);
    }

    public function test_normal_admin_cannot_access_crm_and_snippets() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->getJson('/api/management/customers')
             ->assertStatus(403);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->postJson('/api/management/text-snippets', [
                 'title' => 'Hacked', 
                 'content_html' => '<p>Hacked</p>'
             ])
             ->assertStatus(403);
    }
}
