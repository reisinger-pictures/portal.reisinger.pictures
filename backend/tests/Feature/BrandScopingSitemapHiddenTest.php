<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: hidden galleries (own flag or inherited from a parent group)
 * and hidden photos must not be advertised in the sitemap.
 */
class BrandScopingSitemapHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_galleries_sitemap_excludes_hidden_gallery(): void
    {
        $visible = Gallery::factory()->create([
            'is_public' => true,
            'is_hidden' => false,
            'brand' => Brand::B2B->value,
        ]);
        $hidden = Gallery::factory()->create([
            'is_public' => true,
            'is_hidden' => true,
            'brand' => Brand::B2B->value,
        ]);

        $content = $this->get('/api/sitemap-galleries.xml')->assertOk()->getContent();

        $this->assertStringContainsString(htmlspecialchars($visible->full_path), $content);
        $this->assertStringNotContainsString(htmlspecialchars($hidden->full_path), $content);
    }

    public function test_galleries_sitemap_excludes_gallery_under_hidden_group(): void
    {
        $group = GalleryGroup::factory()->create([
            'is_hidden' => true,
            'brand' => Brand::B2B->value,
        ]);
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'is_hidden' => false,
            'gallery_group_id' => $group->id,
            'brand' => Brand::B2B->value,
        ]);

        $content = $this->get('/api/sitemap-galleries.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString(htmlspecialchars($gallery->full_path), $content);
    }

    public function test_images_sitemap_excludes_hidden_photo(): void
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B->value,
        ]);
        $visible = Photo::factory()->create(['gallery_id' => $gallery->id, 'is_hidden' => false]);
        $hidden = Photo::factory()->create(['gallery_id' => $gallery->id, 'is_hidden' => true]);

        $content = $this->get('/api/sitemap-images.xml')->assertOk()->getContent();

        $this->assertStringContainsString($visible->filename, $content);
        $this->assertStringNotContainsString($hidden->filename, $content);
    }

    public function test_images_sitemap_excludes_photos_of_hidden_gallery(): void
    {
        $hiddenGallery = Gallery::factory()->create([
            'is_public' => true,
            'is_hidden' => true,
            'brand' => Brand::B2B->value,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $hiddenGallery->id, 'is_hidden' => false]);

        $content = $this->get('/api/sitemap-images.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString($photo->filename, $content);
    }
}
