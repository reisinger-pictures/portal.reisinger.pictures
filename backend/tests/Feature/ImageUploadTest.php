<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected string $fixtureContent;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');

        $fixture = base_path('tests/Fixtures/sample.jpg');
        $this->fixtureContent = file_get_contents($fixture);
    }

    private function createPhotographerUser(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user;
    }

    private function createDeliveryGallery(array $overrides = []): Gallery
    {
        return Gallery::factory()->create(array_merge([
            'type' => 'delivery',
        ], $overrides));
    }

    public function test_invalid_file_type_png_is_rejected(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        $file = UploadedFile::fake()->image('test.png', 800, 600);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-png-test',
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_file_type_gif_is_rejected(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        $file = UploadedFile::fake()->image('test.gif', 800, 600);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-gif-test',
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_file_too_large_is_rejected(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        $file = UploadedFile::fake()->create('large.jpg', 21000);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-large-file',
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_missing_file_returns_422(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-no-file',
            ]);

        $response->assertStatus(422);
    }

    public function test_non_photographer_cannot_upload(): void
    {
        $user = User::factory()->create();
        $gallery = $this->createDeliveryGallery();
        $user->galleries()->attach($gallery);

        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-non-photog',
                'file' => $file,
            ]);

        // User is blocked by management middleware before reaching the controller
        $response->assertStatus(403);
        $response->assertJson(['error' => 'Zutritt verweigert. Keine ausreichenden Berechtigungen.']);
    }

    public function test_gallery_not_found_returns_404(): void
    {
        $photographer = $this->createPhotographerUser();

        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => '00000000-0000-0000-0000-000000000000',
                'lr_uuid' => 'uuid-no-gallery',
                'file' => $file,
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Galerie nicht gefunden']);
    }

    public function test_upload_with_dimensions_too_small_rejected(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        // min dimensions: 500x500
        $file = UploadedFile::fake()->image('small.jpg', 100, 100);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-small-dims',
                'file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_upload_to_gallery_without_access_returns_403(): void
    {
        $photographerA = $this->createPhotographerUser();
        $photographerB = $this->createPhotographerUser();

        $gallery = $this->createDeliveryGallery(['restricted_photographers' => true]);
        $photographerA->galleries()->attach($gallery);
        // photographerB is NOT attached

        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($photographerB, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-no-access',
                'file' => $file,
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Keine Berechtigung für diese Galerie.']);
    }

    public function test_regular_user_gets_403_via_management_middleware(): void
    {
        $user = User::factory()->create();
        $gallery = $this->createDeliveryGallery();

        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-middleware-block',
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }
}
