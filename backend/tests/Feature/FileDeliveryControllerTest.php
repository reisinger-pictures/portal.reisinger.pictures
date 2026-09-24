<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileDeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureContent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');

        $fixture = base_path('tests/Fixtures/sample.jpg');
        $this->fixtureContent = file_get_contents($fixture);
        $this->createValidWatermarkAssets();
    }

    private function createValidWatermarkAssets(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            return;
        }

        Storage::disk('photos')->makeDirectory('_watermarks');
        foreach ([500, 1000, 2000] as $bucket) {
            foreach (['master_', 'master_selection_'] as $prefix) {
                $image = imagecreatetruecolor(32, 32);
                $color = imagecolorallocatealpha($image, 255, 255, 255, 40);
                imagefill($image, 0, 0, $color);
                $path = Storage::disk('photos')->path('_watermarks/'.$prefix.$bucket.'.png');
                imagepng($image, $path);
                imagedestroy($image);
            }
        }
    }

    private function attachRole(User $user, UserRole $role): void
    {
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'brand' => 'rp',
        ], $attributes));
    }

    private function createPrivateDeliveryGallery(array $overrides = []): Gallery
    {
        return Gallery::factory()->create(array_merge([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => false,
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // AUTH / AUTHORIZATION
    // ---------------------------------------------------------------

    public function test_unauthenticated_request_to_private_gallery_returns_401(): void
    {
        $gallery = $this->createPrivateDeliveryGallery();

        $this->get('/api/media/'.$gallery->slug.'/photo-id.jpg')
            ->assertStatus(401)
            ->assertJson(['error' => 'Unauthenticated']);
    }

    public function test_public_gallery_is_accessible_without_authentication(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_authenticated_user_without_gallery_access_gets_403(): void
    {
        $user = $this->user();
        $gallery = $this->createPrivateDeliveryGallery();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Forbidden']);
    }

    public function test_authenticated_user_with_gallery_access_can_download_original(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_gallery_not_found_returns_404(): void
    {
        $this->get('/api/media/nonexistent-slug/photo-id.jpg')
            ->assertStatus(404)
            ->assertJson(['error' => 'Galerie nicht gefunden']);
    }

    public function test_photo_not_found_in_gallery_returns_404(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/00000000-0000-0000-0000-000000000000.jpg')
            ->assertStatus(404)
            ->assertJson(['error' => 'Foto nicht gefunden']);
    }

    public function test_invalid_identifier_format_returns_400(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/some-random-string')
            ->assertStatus(400)
            ->assertJson(['error' => 'Ungültiges URL-Format']);
    }

    public function test_gallery_resolved_by_uuid_instead_of_slug(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->id.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // EXPIRY
    // ---------------------------------------------------------------

    public function test_expired_gallery_blocked_for_regular_user(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery([
            'expires_at' => now()->subDay(),
        ]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Galerie abgelaufen']);
    }

    public function test_expired_gallery_accessible_by_admin(): void
    {
        $admin = $this->user();
        $this->attachRole($admin, UserRole::ADMIN);
        $gallery = $this->createPrivateDeliveryGallery([
            'expires_at' => now()->subDay(),
        ]);
        $admin->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($admin, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_expired_gallery_accessible_by_photographer_with_access(): void
    {
        $photographer = $this->user();
        $this->attachRole($photographer, UserRole::PHOTOGRAPHER);
        $gallery = $this->createPrivateDeliveryGallery([
            'expires_at' => now()->subDay(),
            'restricted_photographers' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $photographer->galleries()->attach($gallery);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($photographer, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // WATERMARK LOGIC
    // ---------------------------------------------------------------

    public function test_user_without_flatrate_gets_403_for_original_but_200_for_watermarked(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Zugriff auf Original-Ressource verweigert. Wasserzeichen erforderlich.']);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_flatrate_web_user_can_access_original(): void
    {
        $user = $this->user(['flatrate_level' => 'web']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_admin_bypasses_watermark_requirement(): void
    {
        $admin = $this->user();
        $this->attachRole($admin, UserRole::ADMIN);
        $gallery = $this->createPrivateDeliveryGallery();
        $admin->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($admin, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_photographer_bypasses_watermark_requirement(): void
    {
        $photographer = $this->user();
        $this->attachRole($photographer, UserRole::PHOTOGRAPHER);
        $gallery = $this->createPrivateDeliveryGallery([
            'restricted_photographers' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        // Photographer needs photographer gallery access - use the unrestricted path
        $this->actingAs($photographer, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_free_download_gallery_allows_original_without_watermark(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => true,
        ]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    public function test_public_gallery_without_free_download_requires_watermark(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        // Public but not free → unauthenticated users get watermark required
        $this->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(403)
            ->assertJson(['error' => 'Zugriff auf Original-Ressource verweigert. Wasserzeichen erforderlich.']);

        // Watermarked prefix should still work
        $this->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // THUMBNAIL DELIVERY
    // ---------------------------------------------------------------

    public function test_thumbnail_delivery_works_for_valid_photo(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/_thumbs/800/'.$photo->id.'.webp')
            ->assertStatus(200);
    }

    public function test_thumbnail_generation_failure_logs_the_photo_context(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $imageProcessor = $this->createMock(ImageProcessor::class);
        $imageProcessor->expects($this->once())
            ->method('generateThumbnail')
            ->willThrowException(new \RuntimeException('simulated thumbnail failure'));
        $this->app->instance(ImageProcessor::class, $imageProcessor);
        Log::spy();

        $this->withoutExceptionHandling()
            ->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/_thumbs/800/'.$photo->id.'.webp')
            ->assertStatus(500)
            ->assertJson(['error' => 'Thumbnail fehlt']);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Thumbnail generation failed'
                && $context['photo_id'] === $photo->id
                && $context['error'] === 'simulated thumbnail failure');
    }

    public function test_thumbnail_for_nonexistent_photo_returns_404(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/_thumbs/800/00000000-0000-0000-0000-000000000000.webp')
            ->assertStatus(404)
            ->assertJson(['error' => 'Foto nicht gefunden']);
    }

    // ---------------------------------------------------------------
    // RESPONSE HEADERS & CACHING
    // ---------------------------------------------------------------

    public function test_response_has_correct_content_type_and_cache_headers(): void
    {
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
    }

    public function test_last_accessed_at_updated_on_first_hit(): void
    {
        Cache::flush();
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'last_accessed_at' => null,
        ]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);

        $this->assertNotNull($photo->fresh()->last_accessed_at);
    }

    public function test_last_accessed_at_not_updated_on_subsequent_hits_within_24h(): void
    {
        Cache::flush();
        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'last_accessed_at' => null,
        ]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        // First hit
        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);

        $firstAccess = $photo->fresh()->last_accessed_at;

        // Second hit (within 24h cache)
        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);

        $this->assertEquals($firstAccess, $photo->fresh()->last_accessed_at);
    }

    // ---------------------------------------------------------------
    // WATERMARKED THUMBNAIL DELIVERY
    // ---------------------------------------------------------------

    public function test_watermarked_original_bytes_are_not_the_source_bytes(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(200);

        $delivered = file_get_contents($response->getFile()->getPathname());
        $this->assertNotSame($this->fixtureContent, $delivered);
        $this->assertNotFalse(@getimagesizefromstring($delivered));
    }

    public function test_missing_required_watermark_bucket_fails_closed_without_serving_source(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);
        Storage::disk('photos')->delete('_watermarks/master_500.png');

        $response = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(500)
            ->assertJson(['error' => 'SECURITY: Watermark-Fail.']);

        $this->assertFalse(Storage::disk('photos')->exists($gallery->id.'/_watermarked/'.$photo->filename));
    }

    public function test_invalid_required_watermark_asset_fails_closed(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);
        Storage::disk('photos')->put('_watermarks/master_500.png', 'not-a-png');

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertStatus(500)
            ->assertJson(['error' => 'SECURITY: Watermark-Fail.']);

        $this->assertFalse(Storage::disk('photos')->exists($gallery->id.'/_watermarked/'.$photo->filename));
    }

    public function test_watermarked_thumbnail_delivery(): void
    {
        $user = $this->user(['flatrate_level' => 'none']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/_thumbs/800/'.$photo->id.'.webp')
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // PROXY DELIVERY HEADER
    // ---------------------------------------------------------------

    public function test_proxy_delivery_header_returned_when_configured(): void
    {
        Config::set('services.proxy_delivery_header', 'X-Sendfile');

        $user = $this->user(['flatrate_level' => 'original']);
        $gallery = $this->createPrivateDeliveryGallery();
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg');

        $response->assertStatus(200);
        $response->assertHeader('X-Sendfile');

        Config::set('services.proxy_delivery_header', null);
    }
}
