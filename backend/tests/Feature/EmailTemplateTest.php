<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EmailTemplateTest extends TestCase {
    use RefreshDatabase;

    public function test_admin_can_manage_email_templates() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        $token = auth('api')->login($admin);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson('/api/management/email-templates', [
                             'name' => 'New Template',
                             'subject' => 'Hello',
                             'body' => '<p>World</p>'
                         ]);
                         
        $response->assertStatus(201);
        $this->assertDatabaseHas('email_templates', ['name' => 'New Template']);
    }
}