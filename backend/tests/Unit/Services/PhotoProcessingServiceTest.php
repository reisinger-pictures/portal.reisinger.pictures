<?php

namespace Tests\Unit\Services;

use App\Models\Gallery;
use App\Services\PhotoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_handles_non_existent_file_gracefully()
    {
        $service = new PhotoProcessingService();
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
        ]);

        $meta = $service->processImage('/nonexistent/path.jpg', '/thumb.jpg', $gallery);

        $this->assertSame(0, $meta['width']);
        $this->assertSame(0, $meta['height']);
        $this->assertNull($meta['title']);
    }

    public function test_process_returns_gallery_defaults_when_apply_metadata_is_true()
    {
        $service = new class extends PhotoProcessingService {
            protected function runExifTool(string $targetPath): ?array
            {
                return null;
            }
        };

        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_title' => 'Gallery Default',
            'default_description' => 'Desc',
            'default_keywords' => 'kw1, kw2',
            'default_location' => 'Loc',
            'default_city' => 'City',
            'default_state' => 'State',
            'default_country' => 'Country',
            'default_iso_country' => 'AT',
        ]);

        $meta = $service->processImage('/nonexistent.jpg', '/thumb.jpg', $gallery);

        $this->assertSame('Gallery Default', $meta['title']);
        $this->assertSame('Desc', $meta['description']);
        $this->assertSame('kw1, kw2', $meta['keywords']);
        $this->assertSame('Loc', $meta['location']);
        $this->assertSame('City', $meta['city']);
        $this->assertSame('State', $meta['state']);
        $this->assertSame('Country', $meta['country']);
        $this->assertSame('AT', $meta['iso_country']);
    }

    public function test_process_does_not_set_captured_at_for_selection_galleries()
    {
        $service = new PhotoProcessingService();
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'apply_metadata_to_photos' => true,
        ]);

        $meta = $service->processImage('/nonexistent.jpg', '/thumb.jpg', $gallery);

        $this->assertArrayNotHasKey('captured_at', $meta);
        $this->assertNull($meta['title']);
    }
}
