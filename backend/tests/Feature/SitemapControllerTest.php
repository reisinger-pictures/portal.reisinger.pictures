<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\Photo;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SitemapControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sitemap_galleries_returns_xml()
    {
        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    public function test_sitemap_images_returns_xml()
    {
        $response = $this->get('/api/sitemap-images.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    public function test_sitemap_galleries_contains_public_galleries_of_current_brand()
    {
        $b2b = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
        ]);
        $srp = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::SRP,
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString(htmlspecialchars($b2b->full_path), $content);
        $this->assertStringNotContainsString(htmlspecialchars($srp->full_path), $content);
    }

    public function test_sitemap_galleries_respects_brand_isolation()
    {
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(Brand::SRP);

        Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
        ]);
        $srp = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::SRP,
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        BrandRegistry::set(Brand::B2B);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString(htmlspecialchars($srp->full_path), $content);
        $this->assertStringNotContainsString('rp/', $content);
    }

    public function test_sitemap_galleries_excludes_private_galleries()
    {
        Gallery::factory()->create([
            'is_public' => false,
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('<loc>', $content);
    }

    public function test_sitemap_galleries_excludes_expired_galleries()
    {
        Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('<loc>', $content);
    }

    public function test_sitemap_images_contains_public_gallery_photos()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
        ]);
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get('/api/sitemap-images.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<image:image>', $content);
    }

    public function test_sitemap_images_respects_brand_isolation()
    {
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(Brand::SRP);

        $b2bGallery = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
        ]);
        Photo::factory()->create([
            'gallery_id' => $b2bGallery->id,
        ]);

        $srpGallery = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::SRP,
        ]);
        $srpPhoto = Photo::factory()->create([
            'gallery_id' => $srpGallery->id,
        ]);

        $response = $this->get('/api/sitemap-images.xml');

        BrandRegistry::set(Brand::B2B);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('/api/media/' . $srpGallery->id . '/' . $srpPhoto->filename, $content);
        $this->assertStringNotContainsString('/api/media/' . $b2bGallery->id, $content);
    }

    public function test_sitemap_images_excludes_private_gallery_photos()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => false,
        ]);
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get('/api/sitemap-images.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('<image:image>', $content);
    }

    public function test_sitemap_images_excludes_expired_gallery_photos()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'brand' => Brand::B2B,
            'expires_at' => now()->subDay(),
        ]);
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get('/api/sitemap-images.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('<image:image>', $content);
    }
}
