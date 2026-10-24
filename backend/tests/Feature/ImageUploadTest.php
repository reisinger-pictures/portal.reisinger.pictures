<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
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

    private function createAdminPhotographerUser(string $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
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

    public function test_client_mime_spoof_does_not_change_persisted_image_type(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);
        $baseFile = new SymfonyUploadedFile(
            base_path('tests/Fixtures/sample.jpg'),
            'spoofed.jpg',
            'text/html',
            UPLOAD_ERR_OK,
            true,
        );
        $file = UploadedFile::createFromBase($baseFile, true);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-client-mime-spoof',
                'file' => $file,
            ]);

        $response->assertOk();
        $photo = Photo::findOrFail($response->json('photo_id'));
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertSame('jpg', pathinfo($photo->filename, PATHINFO_EXTENSION));
        Storage::disk('photos')->assertExists($gallery->id.'/'.$photo->filename);
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

    public function test_admin_photographer_cannot_upload_to_a_foreign_brand_gallery(): void
    {
        $user = $this->createAdminPhotographerUser('srp');
        $gallery = $this->createDeliveryGallery(['brand' => Brand::B2B->value]);
        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-foreign-brand',
                'file' => $file,
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Galerie nicht gefunden']);
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_admin_without_photographer_role_gets_photographer_error_for_same_brand_gallery(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B->value]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $gallery = $this->createDeliveryGallery(['brand' => Brand::B2B->value]);
        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-admin-without-photographer',
                'file' => $file,
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Nur Fotografen dürfen Bilder hochladen.']);
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_admin_without_photographer_role_gets_photographer_error_before_foreign_brand_lookup(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B->value]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $gallery = $this->createDeliveryGallery(['brand' => 'srp']);
        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-admin-without-photographer-foreign-brand',
                'file' => $file,
            ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Nur Fotografen dürfen Bilder hochladen.']);
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_admin_photographer_can_upload_to_its_own_unknown_brand_gallery(): void
    {
        $user = $this->createAdminPhotographerUser('srp');
        $gallery = $this->createDeliveryGallery(['brand' => 'srp']);
        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-own-unknown-brand',
                'file' => $file,
            ]);

        $response->assertOk()->assertJsonStructure(['photo_id']);
        $this->assertNotEmpty($response->json('photo_id'));
        $this->assertDatabaseHas('photos', [
            'id' => $response->json('photo_id'),
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_same_brand_admin_photographer_can_upload(): void
    {
        $user = $this->createAdminPhotographerUser(Brand::B2B->value);
        $gallery = $this->createDeliveryGallery(['brand' => Brand::B2B->value]);
        $file = UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-same-brand-admin-photographer',
                'file' => $file,
            ]);

        $response->assertOk()->assertJsonStructure(['photo_id']);
        $this->assertNotEmpty($response->json('photo_id'));
        $this->assertDatabaseHas('photos', [
            'id' => $response->json('photo_id'),
            'gallery_id' => $gallery->id,
        ]);
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

    public function test_replacement_matches_the_original_filename_instead_of_a_null_title(): void
    {
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);

        $matchingPhoto = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'replacement-source',
            'lr_uuid' => 'existing-lr-uuid',
        ]);
        $nullTitlePhoto = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => null,
            'lr_uuid' => 'null-title-uuid',
        ]);

        $file = UploadedFile::fake()->createWithContent('replacement-source.jpg', $this->fixtureContent);

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'web-replacement',
                'replace' => true,
                'file' => $file,
            ]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'photo_id' => $matchingPhoto->id,
        ]);

        $matchingPhoto->refresh();
        $nullTitlePhoto->refresh();

        $this->assertSame('replacement-source', $matchingPhoto->title);
        $this->assertSame('web-replacement', $matchingPhoto->lr_uuid);
        $this->assertNull($nullTitlePhoto->title);
        $this->assertSame('null-title-uuid', $nullTitlePhoto->lr_uuid);
        $this->assertDatabaseCount('photos', 2);
    }

    public function test_replacing_photo_versions_urls_and_refreshes_cached_derivatives(): void
    {
        config(['scout.driver' => 'null']);
        $photographer = $this->createPhotographerUser();
        $gallery = $this->createDeliveryGallery();
        $photographer->galleries()->attach($gallery);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'replace-existing-photo',
        ]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $this->fixtureContent);

        $this->actingAs($photographer, 'api');
        $oldUrls = [$photo->url, $photo->thumb_url, $photo->srcset];
        $oldThumbnailResponse = $this->get("/api/media/{$gallery->slug}/_thumbs/800/{$photo->id}.webp");
        $oldThumbnailResponse->assertStatus(200);
        ob_start();
        $oldThumbnailResponse->sendContent();
        $oldThumbnail = ob_get_clean();

        $watermarkedOriginal = $gallery->id.'/_watermarked/'.$photo->filename;
        $watermarkedThumb = $gallery->id.'/_thumbs/_watermarked/800/'.$photo->id.'.webp';
        Storage::disk('photos')->put($watermarkedOriginal, $this->fixtureContent);
        Storage::disk('photos')->put($watermarkedOriginal.'.watermark.json', '{}');
        Storage::disk('photos')->put($watermarkedThumb, $oldThumbnail);
        Storage::disk('photos')->put($watermarkedThumb.'.watermark.json', '{}');

        $replacement = UploadedFile::fake()->createWithContent(
            'replacement.jpg',
            $this->jpegWithColor(20, 180, 80),
        );
        $this->postJson('/api/management/upload', [
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'replace-existing-photo',
            'file' => $replacement,
        ])->assertStatus(200)->assertJson(['photo_id' => $photo->id]);

        $photo->refresh();
        $this->assertNotSame($oldUrls[0], $photo->url);
        $this->assertNotSame($oldUrls[1], $photo->thumb_url);
        $this->assertNotSame($oldUrls[2], $photo->srcset);
        Storage::disk('photos')->assertMissing($gallery->id.'/_thumbs/800/'.$photo->id.'.webp');
        Storage::disk('photos')->assertMissing($watermarkedOriginal);
        Storage::disk('photos')->assertMissing($watermarkedOriginal.'.watermark.json');
        Storage::disk('photos')->assertMissing($watermarkedThumb);
        Storage::disk('photos')->assertMissing($watermarkedThumb.'.watermark.json');

        $newThumbnailResponse = $this->get("/api/media/{$gallery->slug}/_thumbs/800/{$photo->id}.webp");
        $newThumbnailResponse->assertStatus(200);
        ob_start();
        $newThumbnailResponse->sendContent();
        $newThumbnail = ob_get_clean();
        $this->assertNotSame($oldThumbnail, $newThumbnail);
    }

    private function jpegWithColor(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(600, 600);
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();
        imagedestroy($image);

        return (string) $contents;
    }
}
