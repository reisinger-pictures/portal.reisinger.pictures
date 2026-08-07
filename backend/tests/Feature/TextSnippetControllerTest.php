<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\TextSnippet;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextSnippetControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        $this->superAdmin = User::factory()->create(['brand' => Brand::B2B]);
        $this->superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        $this->admin = User::factory()->create(['brand' => Brand::B2B]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
    }

    public function test_super_admin_can_list_snippets()
    {
        TextSnippet::factory()->count(3)->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/text-snippets');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    public function test_super_admin_can_search_snippets()
    {
        TextSnippet::factory()->create(['title' => 'AGB Text', 'brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/text-snippets?q=AGB');

        $response->assertStatus(200);
    }

    public function test_super_admin_can_create_snippet()
    {
        $payload = [
            'title' => 'Impressum',
            'shortcut' => 'impressum',
            'content_html' => '<p>Test GmbH, Musterstr. 1</p>',
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/text-snippets', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('snippet.title', 'Impressum');
        $response->assertJsonPath('snippet.shortcut', 'impressum');
        $this->assertDatabaseHas('text_snippets', ['shortcut' => 'impressum']);
    }

    public function test_snippet_create_validates_required_fields()
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/text-snippets', []);

        $response->assertStatus(422);
    }

    public function test_snippet_create_validates_unique_shortcut_per_brand()
    {
        TextSnippet::factory()->create(['shortcut' => 'test', 'brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/text-snippets', [
                'title' => 'Duplicate',
                'shortcut' => 'test',
                'content_html' => '<p>Test</p>',
            ]);

        $response->assertStatus(422);
    }

    public function test_non_super_admin_cannot_access_snippets()
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/management/text-snippets');

        $response->assertStatus(403);
    }

    public function test_snippets_are_brand_scoped()
    {
        TextSnippet::factory()->count(2)->create(['brand' => Brand::B2B]);
        TextSnippet::factory()->count(1)->create(['brand' => 'test-brand']);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/text-snippets');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_super_admin_can_update_snippet()
    {
        $snippet = TextSnippet::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson('/api/management/text-snippets/' . $snippet->id, [
                'title' => 'Updated Title',
                'content_html' => '<p>Updated</p>',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('snippet.title', 'Updated Title');
        $this->assertDatabaseHas('text_snippets', ['id' => $snippet->id, 'title' => 'Updated Title']);
    }

    public function test_super_admin_can_delete_snippet()
    {
        $snippet = TextSnippet::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->deleteJson('/api/management/text-snippets/' . $snippet->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('text_snippets', ['id' => $snippet->id]);
    }

    public function test_snippet_shortcut_is_globally_unique()
    {
        TextSnippet::factory()->create(['shortcut' => 'global-unique', 'brand' => Brand::B2B]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        TextSnippet::factory()->create(['shortcut' => 'global-unique', 'brand' => 'test-brand']);
    }
}
