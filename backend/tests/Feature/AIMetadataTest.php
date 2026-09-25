<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

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
            'type' => 'openai',
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
                'status' => 'available',
                'type' => 'openai',
                'model' => 'gpt-4o',
            ]);
    }

    public function test_status_returns_disabled_when_not_enabled()
    {
        config(['services.ai.enabled' => false]);

        $response = $this->actingAs($this->photographer, 'api')
            ->getJson('/api/ai/status');

        $response->assertStatus(200)
            ->assertJson([
                'enabled' => false,
                'status' => 'disabled',
            ]);
    }

    public function test_status_returns_unconfigured_when_key_missing()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);

        $response = $this->actingAs($this->photographer, 'api')
            ->getJson('/api/ai/status');

        $response->assertStatus(200)
            ->assertJson([
                'enabled' => false,
                'status' => 'unconfigured',
            ]);
    }

    public function test_status_includes_type()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        $response = $this->actingAs($this->photographer, 'api')
            ->getJson('/api/ai/status');

        $response->assertStatus(200)
            ->assertJson([
                'type' => 'openai',
            ]);
    }

    public function test_generate_metadata_returns_503_when_disabled()
    {
        config(['services.ai.enabled' => false]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(503);
    }

    public function test_generate_metadata_returns_503_when_unconfigured()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(503);
    }

    public function test_unauthorized_vision_request_returns_403_when_ai_is_disabled(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $clientWithoutRights = User::factory()->create(['can_edit_metadata' => false]);
        $clientWithoutRights->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $clientWithoutRights->galleries()->attach($this->gallery->id);

        $response = $this->actingAs($clientWithoutRights, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        Http::assertNothingSent();
    }

    public function test_unauthorized_vision_request_returns_403_when_ai_is_unconfigured(): void
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);
        Http::fake();

        $clientWithoutRights = User::factory()->create(['can_edit_metadata' => false]);
        $clientWithoutRights->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $clientWithoutRights->galleries()->attach($this->gallery->id);

        $response = $this->actingAs($clientWithoutRights, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        Http::assertNothingSent();
    }

    public function test_unauthorized_vision_request_is_rejected_before_validation_when_ai_is_disabled(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $userWithoutMetadataPermission = User::factory()->create();

        $response = $this->actingAs($userWithoutMetadataPermission, 'api')
            ->postJson('/api/ai/generate-metadata', []);

        $response->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        Http::assertNothingSent();
    }

    public function test_coarse_metadata_flag_without_target_access_is_rejected_before_ai_work_when_disabled(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $aiService = \Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('isAvailable');
        $aiService->shouldNotReceive('generateMetadata');
        $this->app->instance(AIService::class, $aiService);

        $coarseFlagOnly = User::factory()->create(['can_edit_metadata' => true]);

        $response = $this->actingAs($coarseFlagOnly, 'api')
            ->postJson('/api/ai/generate-metadata', []);

        $response->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        Http::assertNothingSent();
    }

    public function test_target_scoped_metadata_actor_gets_same_403_for_unknown_and_inaccessible_photos(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $coarseFlagOnly = User::factory()->create(['can_edit_metadata' => true]);

        foreach (['non-existent-id', $this->photo->id] as $photoId) {
            $this->actingAs($coarseFlagOnly, 'api')
                ->postJson('/api/ai/generate-metadata', ['photo_id' => $photoId])
                ->assertStatus(403)
                ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        }

        Http::assertNothingSent();
    }

    public function test_invite_scoped_client_gets_opaque_403_for_invalid_and_revoked_targets_before_ai_work(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $client = User::factory()->create(['can_edit_metadata' => false]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $invitedGallery = Gallery::factory()->create([
            'type' => 'delivery',
            'allow_client_metadata_edit' => true,
        ]);
        $invitedPhoto = Photo::factory()->create(['gallery_id' => $invitedGallery->id]);
        $inaccessibleGallery = Gallery::factory()->create([
            'type' => 'delivery',
            'allow_client_metadata_edit' => true,
        ]);
        $inaccessiblePhoto = Photo::factory()->create([
            'gallery_id' => $inaccessibleGallery->id,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $invitedGallery->id,
            'token' => 'ai-metadata-active-invite',
            'can_edit_metadata' => true,
        ]);
        $token = JWTAuth::claims([
            'transient_galleries' => [(string) $invitedGallery->id],
            'transient_meta_galleries' => [(string) $invitedGallery->id],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_ids' => [(string) $invitedGallery->id],
                    'meta_gallery_ids' => [(string) $invitedGallery->id],
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ])->fromUser($client);

        // Exactly one availability call for the valid active-invite control
        // proves the scoped grant is real. Invalid and revoked targets below
        // must return before this point or touch provider/image work.
        $aiService = \Mockery::mock(AIService::class);
        $aiService->shouldReceive('isAvailable')->once()->andReturnFalse();
        $aiService->shouldNotReceive('generateMetadata');
        $this->app->instance(AIService::class, $aiService);

        $this->withToken($token)
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $invitedPhoto->id,
            ])
            ->assertStatus(503)
            ->assertExactJson(['error' => 'KI-Dienst ist nicht verfügbar.']);

        foreach (['non-existent-id', $inaccessiblePhoto->id] as $photoId) {
            $this->withToken($token)
                ->postJson('/api/ai/generate-metadata', ['photo_id' => $photoId])
                ->assertStatus(403)
                ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        }

        // A direct delete exercises the live invite check used when a deletion
        // bypasses the controller's cache marker.
        $invite->delete();

        $this->withToken($token)
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $invitedPhoto->id,
            ])
            ->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);

        Http::assertNothingSent();
    }

    public function test_actor_without_coarse_metadata_capability_is_rejected_without_target_lookup_or_ai_availability(): void
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $aiService = \Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('isAvailable');
        $aiService->shouldNotReceive('generateMetadata');
        $this->app->instance(AIService::class, $aiService);

        $userWithoutMetadataPermission = User::factory()->create();
        $photoLookupQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$photoLookupQueries): void {
            if (str_contains($query->sql, 'from "photos"')) {
                $photoLookupQueries[] = $query->sql;
            }
        });

        $response = $this->actingAs($userWithoutMetadataPermission, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(403)
            ->assertExactJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        $this->assertSame([], $photoLookupQueries, 'The early authorization check must not query the submitted photo target.');
        Http::assertNothingSent();
    }

    public function test_generate_metadata_text_returns_503_when_disabled()
    {
        config(['services.ai.enabled' => false]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'Test',
            ]);

        $response->assertStatus(503);
    }

    public function test_generate_metadata_text_returns_503_when_unconfigured()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'Test',
            ]);

        $response->assertStatus(503);
    }

    public function test_status_requires_auth()
    {
        $response = $this->getJson('/api/ai/status');
        $response->assertStatus(401);
    }

    public function test_generate_metadata_requires_auth()
    {
        config(['services.ai.enabled' => false]);

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
        $this->useTemporaryStorageDisk('photos');
        $sampleContent = file_get_contents(__DIR__.'/../Fixtures/sample.jpg');
        Storage::disk('photos')->put($this->gallery->id.'/'.$this->photo->filename, $sampleContent);
    }

    public function test_photographer_can_generate_metadata()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "AI Title", "description": "AI Description", "keywords": "kw1, kw2", "location": "Berlin", "detected_city": "Berlin"}',
                    ],
                ]],
            ]),
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
                        'content' => '{"title": "Client AI Title", "description": "Client Desc", "keywords": "test", "location": "", "detected_city": ""}',
                    ],
                ]],
            ]),
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

    public function test_photographer_can_generate_metadata_text()
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Text Title", "description": "Text Desc", "keywords": "text, based", "location": "Paris"}',
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($this->photographer, 'api')
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

    public function test_client_cannot_generate_metadata_text()
    {
        Http::fake();

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_unauthorized_user_cannot_probe_disabled_ai_service()
    {
        config(['services.ai.enabled' => false]);
        Http::fake();

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(403)
            ->assertJson(['error' => 'Keine Berechtigung für KI-Generierung.']);
        Http::assertNothingSent();
    }

    public function test_super_admin_can_generate_metadata_text()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Admin Title", "description": "Admin Description", "keywords": "admin, metadata", "location": "Vienna"}',
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($superAdmin, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'Admin Title',
                'description' => 'Admin Description',
            ]);
    }

    public function test_generate_metadata_text_requires_authentication(): void
    {
        $response = $this->postJson('/api/ai/generate-metadata-text', [
            'text_input' => 'A landscape photo of the Eiffel Tower',
        ]);

        $response->assertStatus(401);
    }

    public function test_generate_metadata_text_requires_gallery_creation_role(): void
    {
        $user = User::factory()->create();
        Http::fake();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_admin_cannot_generate_metadata_text(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        Http::fake();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_generate_metadata_text_requires_text_input(): void
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', []);

        $response->assertStatus(422);
    }

    public function test_generate_metadata_text_does_not_expose_provider_error_body()
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'error' => ['message' => 'SENSITIVE-PROVIDER-BODY'],
            ], 500),
        ]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', [
                'text_input' => 'A landscape photo of the Eiffel Tower',
            ]);

        $response->assertStatus(502)
            ->assertExactJson(['error' => 'AI API Fehler: 500']);
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

    public function test_generate_metadata_forwards_session_id_as_session_header()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "T", "description": "D", "keywords": "k", "location": "", "detected_city": ""}',
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
                'session_id' => 'batch-42',
            ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) {
            return $request->header('x-opencode-session') === ['portal-batch-42'];
        });
    }

    public function test_generate_metadata_omits_session_header_without_session_id()
    {
        $this->setupStorageWithPhotoImage();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "T", "description": "D", "keywords": "k", "location": "", "detected_city": ""}',
                    ],
                ]],
            ]),
        ]);

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(200);

        Http::assertSent(function (Request $request) {
            return $request->header('x-opencode-session') === [];
        });
    }

    public function test_generate_metadata_rejects_oversized_session_id()
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
                'session_id' => str_repeat('x', 129),
            ]);

        $response->assertStatus(422);
    }
}
