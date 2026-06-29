<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AIMetadataTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;
    private User $client;
    private Gallery $gallery;
    private Photo $photo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai' => [
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        $this->photographer = User::factory()->create();
        $this->photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $this->client = User::factory()->create(['can_edit_metadata' => true]);
        $this->client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $this->gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'allow_client_metadata_edit' => true,
        ]);

        $this->photographer->photographerGalleries()->attach($this->gallery->id);
        $this->client->galleries()->attach($this->gallery->id);

        $this->photo = Photo::factory()->create([
            'gallery_id' => $this->gallery->id,
            'user_id' => $this->photographer->id,
            'title' => 'Original Title',
            'description' => 'Original Description',
        ]);
    }

    protected function tearDown(): void
    {
        auth('api')->logout();
        parent::tearDown();
    }

    public function test_status_returns_ai_config()
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->getJson('/api/ai/status');

        $response->assertStatus(200)
            ->assertJson([
                'enabled' => true,
                'model' => 'gpt-4o',
            ]);
    }

    public function test_status_requires_auth()
    {
        $response = $this->getJson('/api/ai/status');
        $response->assertStatus(401);
    }

    public function test_generate_metadata_requires_auth()
    {
        $response = $this->postJson('/api/ai/generate-metadata', [
            'photo_id' => $this->photo->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_generate_metadata_requires_valid_photo_id()
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => 'non-existent-id',
            ]);
        $response->assertStatus(422);
    }

    private function setupStorageWithPhotoImage(): void
    {
        Storage::fake('photos');
        $sampleContent = file_get_contents(__DIR__ . '/../Fixtures/sample.jpg');
        Storage::disk('photos')->put($this->gallery->id . '/' . $this->photo->filename, $sampleContent);
    }

    public function test_photographer_can_generate_metadata()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "AI Title", "description": "AI Description", "keywords": "kw1, kw2", "location": "Berlin", "detected_city": "Berlin"}'
                    ]
                ]]
            ])
        ]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
                'global_context' => 'Test event',
                'specific_context' => 'Main subject',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'AI Title',
                'description' => 'AI Description',
                'keywords' => 'kw1, kw2',
                'location' => 'Berlin',
                'detected_city' => 'Berlin',
            ]);
    }

    public function test_client_can_generate_metadata_with_gallery_access()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Client AI Title", "description": "Client Desc", "keywords": "test", "location": "", "detected_city": ""}'
                    ]
                ]]
            ])
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'Client AI Title',
                'description' => 'Client Desc',
            ]);
    }

    public function test_client_without_metadata_rights_cannot_generate_metadata()
    {
        $clientNoRights = User::factory()->create(['can_edit_metadata' => false]);
        $clientNoRights->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $clientNoRights->galleries()->attach($this->gallery->id);

        $this->setupStorageWithPhotoImage();

        $response = $this->actingAs($clientNoRights, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_user_without_gallery_access_cannot_generate_metadata()
    {
        $otherGallery = Gallery::factory()->create(['type' => 'delivery']);
        $otherPhoto = Photo::factory()->create([
            'gallery_id' => $otherGallery->id,
            'user_id' => $this->photographer->id,
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $otherPhoto->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_generate_metadata_text_works_for_all_users()
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Text Title", "description": "Text Desc", "keywords": "text, based", "location": "Paris"}'
                    ]
                ]]
            ])
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
                'global_context' => 'Travel photography',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'Text Title',
                'description' => 'Text Desc',
                'keywords' => 'text, based',
                'location' => 'Paris',
            ]);
    }

    public function test_generate_metadata_text_requires_text_input()
    {
        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata-text', []);

        $response->assertStatus(422);
    }

    public function test_generate_metadata_returns_502_on_api_error()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([], 500),
        ]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(502);
    }
}
