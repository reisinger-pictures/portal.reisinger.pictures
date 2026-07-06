<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Photo;
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

    public function test_sitemap_galleries_contains_public_galleries()
    {
        $public = Gallery::factory()->create([
            'is_public' => true,
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString(htmlspecialchars($public->full_path), $content);
    }

    public function test_sitemap_galleries_excludes_private_galleries()
    {
        Gallery::factory()->create([
            'is_public' => false,
        ]);

        $response = $this->get('/api/sitemap-galleries.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        // Sitemap only queries where is_public=true, so no private galleries appear
        $this->assertStringNotContainsString('<loc>', $content);
    }

    public function test_sitemap_images_contains_public_gallery_photos()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
        ]);
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
        ]);

        $response = $this->get('/api/sitemap-images.xml');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<image:image>', $content);
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
}
