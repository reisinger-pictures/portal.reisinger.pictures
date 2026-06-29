<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Services\ImageProcessor;
use App\Services\PhotoProcessingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ImageServicesTest extends TestCase
{
    use RefreshDatabase;

    private ImageProcessor $imageProcessor;
    private PhotoProcessingService $photoService;

    /**
     * BK-09: WatermarkService (Passthrough) + PhotoProcessingService.
     *
     * Mockability-Hinweis: `PhotoProcessingService::runExifTool()` (der exiftool-Binary-Aufruf)
     * ist im Service als protected extrahiert und wird hier per anonymer Subklasse überschrieben
     * → ExifTool wird NIEMALS wirklich aufgerufen, die Tests sind stabil in der Gesamt-Suite
     * (keine Mockery-overload-Abhängigkeit mehr). getimagesize wird über ein reales GD-Minibild
     * bzw. nicht-existierenden Pfad (@getimagesize→false) abgedeckt.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->imageProcessor = app(ImageProcessor::class);
        $this->photoService = new PhotoProcessingService();
    }

    // =========================================================================
    // WatermarkService — reiner Passthrough an ImageProcessor via app(...)
    // =========================================================================

    public function test_applyCenteredWatermark_uses_defaults_when_no_optional_args_passed(): void
    {
        $source = '/photos/1/originals/abc.jpg';
        $dest = '/photos/1/watermarked/abc.jpg';

        $this->mock(ImageProcessor::class, function ($mock) use ($source, $dest) {
            $mock->shouldReceive('applyCenteredWatermark')
                ->once()
                ->with($source, $dest, null, 'delivery')
                ->andReturn(true);
        });

        $this->assertTrue(app(ImageProcessor::class)->applyCenteredWatermark($source, $dest, null, 'delivery'));
    }

    public function test_applyCenteredWatermark_forwards_maxWidth_argument(): void
    {
        $source = '/src.jpg';
        $dest = '/dest.jpg';

        $this->mock(ImageProcessor::class, function ($mock) use ($source, $dest) {
            $mock->shouldReceive('applyCenteredWatermark')
                ->once()
                ->with($source, $dest, 800, 'delivery')
                ->andReturn(true);
        });

        $this->assertTrue(app(ImageProcessor::class)->applyCenteredWatermark($source, $dest, 800, 'delivery'));
    }

    public function test_applyCenteredWatermark_forwards_selection_gallery_type(): void
    {
        $source = '/src.webp';
        $dest = '/dest.webp';

        $this->mock(ImageProcessor::class, function ($mock) use ($source, $dest) {
            $mock->shouldReceive('applyCenteredWatermark')
                ->once()
                ->with($source, $dest, 1024, 'selection')
                ->andReturn(false);
        });

        $this->assertFalse(app(ImageProcessor::class)->applyCenteredWatermark($source, $dest, 1024, 'selection'));
    }

    public function test_applyCenteredWatermark_returns_processor_result_verbatim(): void
    {
        $this->mock(ImageProcessor::class, function ($mock) {
            $mock->shouldReceive('applyCenteredWatermark')->once()->andReturn('STUB_RESULT');
        });

        $this->assertSame(
            'STUB_RESULT',
            app(ImageProcessor::class)->applyCenteredWatermark('/s', '/d', 500, 'delivery')
        );
    }

    public function test_applyCenteredWatermark_resolves_from_container(): void
    {
        $source = '/s.jpg';
        $dest = '/d.jpg';

        $this->mock(ImageProcessor::class, function ($mock) use ($source, $dest) {
            $mock->shouldReceive('applyCenteredWatermark')
                ->once()
                ->with($source, $dest, null, 'delivery')
                ->andReturn(true);
        });

        $this->assertTrue(app(ImageProcessor::class)->applyCenteredWatermark($source, $dest, null, 'delivery'));
    }

    // =========================================================================
    // PhotoProcessingService — Frühreturn-Verzweigungen (ohne ExifTool)
    // =========================================================================

    public function test_processImage_selection_gallery_returns_early_with_null_text_fields(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'apply_metadata_to_photos' => true,
            'default_title' => 'T',
            'default_description' => 'D',
            'default_keywords' => 'k',
        ]);

        $meta = $this->photoService->processImage('/nonexistent.jpg', '/thumb.jpg', $gallery);

        // applyDefaults = type!=='selection' && apply_metadata → false → alle Text-Felder null.
        $this->assertSame(0, $meta['width']);
        $this->assertSame(0, $meta['height']);
        $this->assertNull($meta['title']);
        $this->assertNull($meta['description']);
        $this->assertNull($meta['keywords']);
        $this->assertNull($meta['location']);
        $this->assertNull($meta['city']);
        $this->assertNull($meta['state']);
        $this->assertNull($meta['country']);
        $this->assertNull($meta['iso_country']);
        $this->assertArrayNotHasKey('captured_at', $meta);
    }

    public function test_processImage_delivery_with_apply_metadata_false_returns_early(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
            'default_title' => 'Ignored',
        ]);

        $meta = $this->photoService->processImage('/nonexistent.jpg', '/thumb.jpg', $gallery);

        $this->assertNull($meta['title']);
        $this->assertNull($meta['description']);
        $this->assertArrayNotHasKey('captured_at', $meta);
    }

    public function test_processImage_getimagesize_reads_real_dimensions_from_minibild(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD extension not available');
        }

        $path = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
        $img = imagecreatetruecolor(120, 60);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        imagejpeg($img, $path);
        imagedestroy($img);

        try {
            // Frühreturn-Pfad (apply_metadata=false), ABER getimagesize läuft davor.
            $gallery = Gallery::factory()->create([
                'type' => 'delivery',
                'apply_metadata_to_photos' => false,
            ]);

            $meta = $this->photoService->processImage($path, '/thumb.jpg', $gallery);

            $this->assertSame(120, $meta['width']);
            $this->assertSame(60, $meta['height']);
        } finally {
            @unlink($path);
        }
    }

    // =========================================================================
    // PhotoProcessingService — Defaults / Fallback ohne Mapping-Zweig
    //  (applyDefaults=true, aber ExifTool liefert nichts Verwertbares)
    // =========================================================================

    public function test_processImage_empty_exif_array_keeps_gallery_defaults(): void
    {
        // json_decode('[]') → [] → isset([0]) false → kein Mapping
        $meta = $this->processWithExif([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_title' => 'Default Title',
            'default_description' => 'Default Desc',
            'default_keywords' => 'default, kw',
            'default_location' => 'Loc',
            'default_city' => 'City',
            'default_state' => 'State',
            'default_country' => 'Country',
            'default_iso_country' => 'AT',
        ], []);

        $this->assertSame('Default Title', $meta['title']);
        $this->assertSame('Default Desc', $meta['description']);
        $this->assertSame('default, kw', $meta['keywords']);
        $this->assertSame('Loc', $meta['location']);
        $this->assertSame('City', $meta['city']);
        $this->assertSame('State', $meta['state']);
        $this->assertSame('Country', $meta['country']);
        $this->assertSame('AT', $meta['iso_country']);
        $this->assertArrayNotHasKey('captured_at', $meta);
    }

    public function test_processImage_invalid_exif_json_keeps_gallery_defaults(): void
    {
        // json_decode('not-json') → null → is_array false → kein Mapping
        $meta = $this->processWithExif([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_title' => 'Fallback',
        ], null);

        $this->assertSame('Fallback', $meta['title']);
    }

    // =========================================================================
    // PhotoProcessingService — ExifTool-Mapping (über runExifTool-Seam)
    // =========================================================================

    public function test_processImage_maps_title_with_precedence_title_over_objectname_over_xptitle(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'Title' => 'T-Title', 'ObjectName' => 'T-Object', 'XPTitle' => 'T-XP',
        ]]);

        $this->assertSame('T-Title', $meta['title']);
    }

    public function test_processImage_maps_title_fallback_to_objectname_when_title_missing(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'ObjectName' => 'Only-Object', 'XPTitle' => 'Only-XP',
        ]]);

        $this->assertSame('Only-Object', $meta['title']);
    }

    public function test_processImage_maps_title_fallback_to_xptitle(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'XPTitle' => 'XP-Only',
        ]]);

        $this->assertSame('XP-Only', $meta['title']);
    }

    public function test_processImage_implodes_array_keywords_with_comma(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'Keywords' => ['alpha', 'beta', 'gamma'],
        ]]);

        $this->assertSame('alpha, beta, gamma', $meta['keywords']);
    }

    public function test_processImage_keeps_scalar_keywords_verbatim(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'Keywords' => 'single-keyword',
        ]]);

        $this->assertSame('single-keyword', $meta['keywords']);
    }

    public function test_processImage_maps_location_city_state_country_iso_country(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'Sub-location' => 'SubLoc',
            'City' => 'Wien',
            'Province-State' => 'Wien',
            'Country-PrimaryLocationName' => 'Austria',
            'Country-PrimaryLocationCode' => 'AUT',
        ]]);

        $this->assertSame('SubLoc', $meta['location']);
        $this->assertSame('Wien', $meta['city']);
        $this->assertSame('Wien', $meta['state']);
        $this->assertSame('Austria', $meta['country']);
        $this->assertSame('AUT', $meta['iso_country']);
    }

    public function test_processImage_description_uses_caption_abstract_fallback(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'Caption-Abstract' => 'Caption-Text',
        ]]);

        $this->assertSame('Caption-Text', $meta['description']);
    }

    public function test_processImage_parses_datetimeoriginal_into_captured_at(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'DateTimeOriginal' => '2024:06:22 12:34:56', 'CreateDate' => '2024:06:22 12:34:56',
        ]]);

        $expected = Carbon::createFromFormat('Y:m:d H:i:s', '2024:06:22 12:34:56')->toDateTimeString();
        $this->assertSame($expected, $meta['captured_at']);
    }

    public function test_processImage_falls_back_to_createdate_when_datetimeoriginal_missing(): void
    {
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'CreateDate' => '2023:01:15 08:00:00',
        ]]);

        $this->assertSame('2023-01-15 08:00:00', $meta['captured_at']);
    }

    public function test_processImage_invalid_date_string_does_not_set_captured_at(): void
    {
        // try/catch im Service schluckt die Exception → kein captured_at.
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'DateTimeOriginal' => 'not-a-date',
        ]]);

        $this->assertArrayNotHasKey('captured_at', $meta);
    }

    public function test_processImage_exif_fields_fall_back_to_gallery_defaults_when_missing(): void
    {
        // isset([0]) true, aber leeres Feld-Set → jedes Feld fällt auf Gallery-Default zurück.
        $meta = $this->processWithExif([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_title' => 'G-Title',
            'default_description' => 'G-Desc',
            'default_keywords' => 'g, kw',
            'default_location' => 'G-Loc',
            'default_city' => 'G-City',
            'default_state' => 'G-State',
            'default_country' => 'G-Country',
            'default_iso_country' => 'DE',
        ], [[]]);

        $this->assertSame('G-Title', $meta['title']);
        $this->assertSame('G-Desc', $meta['description']);
        $this->assertSame('g, kw', $meta['keywords']);
        $this->assertSame('G-Loc', $meta['location']);
        $this->assertSame('G-City', $meta['city']);
        $this->assertSame('G-State', $meta['state']);
        $this->assertSame('G-Country', $meta['country']);
        $this->assertSame('DE', $meta['iso_country']);
    }

    public function test_processImage_truncates_long_date_string_to_19_chars_before_parse(): void
    {
        // Service macht substr($dateStr, 0, 19) — ExifTool liefert teils "…Z"/"…+02:00"
        $meta = $this->processWithExif($this->galleryWithDefaults(), [[
            'DateTimeOriginal' => '2024:06:22 12:34:56.789+02:00',
        ]]);

        $this->assertSame('2024-06-22 12:34:56', $meta['captured_at']);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function galleryWithDefaults(): array
    {
        return [
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_title' => null,
            'default_description' => null,
            'default_keywords' => null,
            'default_location' => null,
            'default_city' => null,
            'default_state' => null,
            'default_country' => null,
            'default_iso_country' => null,
        ];
    }

    /**
     * Führt processImage mit einer anonymen Subklasse aus, deren runExifTool-Seam
     * $exifData (die dekodierten ExifTool-Daten) zurückgibt, ohne das Binary aufzurufen.
     */
    private function processWithExif(array $galleryAttrs, $exifData): array
    {
        $service = new class($exifData) extends PhotoProcessingService {
            private $exifData;

            public function __construct($exifData)
            {
                $this->exifData = $exifData;
            }

            protected function runExifTool(string $targetPath)
            {
                return $this->exifData;
            }
        };

        $gallery = Gallery::factory()->create($galleryAttrs);
        return $service->processImage('/nonexistent.jpg', '/thumb.jpg', $gallery);
    }
}
