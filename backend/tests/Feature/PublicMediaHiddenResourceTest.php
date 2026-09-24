<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public media projections must re-check the effective visibility state at
 * every delivery boundary.  In particular, a Scout entry or a photo row is
 * not a visibility decision: a group can hide all of its descendants.
 */
class PublicMediaHiddenResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_direct_media_rejects_a_hidden_photo_without_bytes_or_records(): void
    {
        $gallery = $this->publicGallery();
        $photo = $this->createPhoto([
            'gallery_id' => $gallery->id,
            'is_hidden' => true,
            'last_accessed_at' => null,
        ]);
        $fixture = $this->storeSource($gallery, $photo);

        $original = $this->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertNotFound();
        $original->assertJson(['error' => 'Foto nicht gefunden']);
        $this->assertNotSame($fixture, $original->getContent());

        // A hidden photo must be rejected before watermark generation as well.
        $watermarked = $this->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertNotFound();
        $watermarked->assertJson(['error' => 'Foto nicht gefunden']);
        $this->assertNotSame($fixture, $watermarked->getContent());
        $this->assertFalse(Storage::disk('photos')->exists($gallery->id.'/_watermarked/'.$photo->filename));

        $thumbnail = $this->get('/api/media/'.$gallery->slug.'/_thumbs/800/'.$photo->id.'.webp')
            ->assertNotFound();
        $thumbnail->assertJson(['error' => 'Foto nicht gefunden']);
        $this->assertFalse(Storage::disk('photos')->exists($gallery->id.'/_thumbs/800/'.$photo->id.'.webp'));

        $this->assertNull($photo->fresh()->last_accessed_at);
        $this->assertSame(0, DownloadLog::query()->count());
    }

    public function test_public_gallery_payload_excludes_hidden_photos_and_keeps_pagination_consistent(): void
    {
        $gallery = $this->publicGallery();
        $visible = $this->createPhoto([
            'gallery_id' => $gallery->id,
            'is_hidden' => false,
        ]);
        $hidden = $this->createPhoto([
            'gallery_id' => $gallery->id,
            'is_hidden' => true,
        ]);

        $response = $this->getJson('/api/galleries/'.$gallery->slug)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('last_page', 1);

        $photoIds = collect($response->json('photos'))->pluck('id')->map(fn ($id): string => (string) $id);
        $this->assertTrue($photoIds->contains((string) $visible->id));
        $this->assertFalse($photoIds->contains((string) $hidden->id));
        $this->assertCount(1, $response->json('photos'));
        $this->assertSame(0, DownloadLog::query()->count());
    }

    public function test_public_search_excludes_hidden_galleries_and_photos(): void
    {
        $visibleGallery = $this->publicGallery(['name' => 'Visible discovery']);
        $visiblePhoto = $this->createPhoto([
            'gallery_id' => $visibleGallery->id,
            'title' => 'Visible discovery photo',
            'is_hidden' => false,
        ]);

        $hiddenGallery = $this->publicGallery([
            'name' => 'Hidden discovery gallery',
            'is_hidden' => true,
        ]);
        $hiddenPhoto = $this->createPhoto([
            'gallery_id' => $hiddenGallery->id,
            'title' => 'Hidden discovery photo',
            'is_hidden' => false,
        ]);

        $response = $this->getJson('/api/search?q=')->assertOk();
        $galleryIds = collect($response->json('galleries'))->pluck('id')->map(fn ($id): string => (string) $id);
        $photoIds = collect($response->json('photos'))->pluck('id')->map(fn ($id): string => (string) $id);

        $this->assertTrue($galleryIds->contains((string) $visibleGallery->id));
        $this->assertFalse($galleryIds->contains((string) $hiddenGallery->id));
        $this->assertTrue($photoIds->contains((string) $visiblePhoto->id));
        $this->assertFalse($photoIds->contains((string) $hiddenPhoto->id));
        $this->assertStringNotContainsString((string) $hiddenGallery->id, $response->getContent());
        $this->assertStringNotContainsString((string) $hiddenPhoto->id, $response->getContent());

        // Exercise the Scout-backed text path as well.  The collection engine
        // makes this regression deterministic without weakening the production
        // Meilisearch contract.
        config(['scout.driver' => 'collection']);
        $textResponse = $this->getJson('/api/search?q=discovery')->assertOk();
        $textGalleryIds = collect($textResponse->json('galleries'))->pluck('id')->map(fn ($id): string => (string) $id);
        $textPhotoIds = collect($textResponse->json('photos'))->pluck('id')->map(fn ($id): string => (string) $id);
        $this->assertTrue($textGalleryIds->contains((string) $visibleGallery->id));
        $this->assertFalse($textGalleryIds->contains((string) $hiddenGallery->id));
        $this->assertTrue($textPhotoIds->contains((string) $visiblePhoto->id));
        $this->assertFalse($textPhotoIds->contains((string) $hiddenPhoto->id));
        $this->assertSame(0, DownloadLog::query()->count());
    }

    public function test_inherited_group_hiding_is_denied_by_all_public_media_projections(): void
    {
        $root = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'is_hidden' => true,
        ]);
        $child = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $root->id,
            'is_hidden' => false,
        ]);
        $gallery = $this->publicGallery([
            'gallery_group_id' => $child->id,
            'name' => 'Inherited hidden token',
        ]);
        $photo = $this->createPhoto([
            'gallery_id' => $gallery->id,
            'title' => 'Inherited hidden token',
            'is_hidden' => false,
            'last_accessed_at' => null,
        ]);
        $this->storeSource($gallery, $photo);
        $this->assertTrue($gallery->fresh()->effective_is_hidden);

        $media = $this->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertNotFound();
        $media->assertJson(['error' => 'Galerie nicht gefunden']);
        $this->assertNotSame(file_get_contents(base_path('tests/Fixtures/sample.jpg')), $media->getContent());

        $this->getJson('/api/galleries/'.$gallery->slug)->assertNotFound();
        $this->getJson('/api/photos/'.$photo->id.'/context')->assertNotFound();

        $search = $this->getJson('/api/search?q=')->assertOk();
        $galleryIds = collect($search->json('galleries'))->pluck('id')->map(fn ($id): string => (string) $id);
        $photoIds = collect($search->json('photos'))->pluck('id')->map(fn ($id): string => (string) $id);
        $this->assertFalse($galleryIds->contains((string) $gallery->id));
        $this->assertFalse($photoIds->contains((string) $photo->id));

        config(['scout.driver' => 'collection']);
        $textSearch = $this->getJson('/api/search?q=Inherited%20hidden%20token')->assertOk();
        $this->assertFalse(collect($textSearch->json('galleries'))->pluck('id')->contains($gallery->id));
        $this->assertFalse(collect($textSearch->json('photos'))->pluck('id')->contains($photo->id));

        $user = User::factory()->create(['brand' => Brand::B2B]);
        $this->actingAs($user, 'api')
            ->postJson('/api/photos/'.$photo->id.'/rate', ['rating' => 5])
            ->assertNotFound();

        $this->assertNull($photo->fresh()->last_accessed_at);
        $this->assertSame(0, DownloadLog::query()->count());
        $this->assertDatabaseCount('ratings', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publicGallery(array $overrides = []): Gallery
    {
        return Gallery::withoutSyncingToSearch(fn (): Gallery => Gallery::factory()->create(array_merge([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'is_public' => true,
            'is_free_download' => true,
            'is_hidden' => false,
        ], $overrides)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPhoto(array $attributes = []): Photo
    {
        return Photo::withoutSyncingToSearch(fn (): Photo => Photo::factory()->create($attributes));
    }

    private function storeSource(Gallery $gallery, Photo $photo): string
    {
        $fixture = (string) file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $fixture);

        return $fixture;
    }
}
