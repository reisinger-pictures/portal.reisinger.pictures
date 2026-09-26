<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Http\Controllers\PhotoDownloadController;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\ImageProcessor;
use App\Services\MediaVisibilityService;
use App\Services\PurchaseService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for authorization changes during derivative preparation.
 */
class MediaLateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Storage::fake('photos');
        Cache::flush();
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_order_zip_rechecks_refund_after_processing_and_emits_no_bytes_or_log(): void
    {
        $buyer = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'none',
        ]);
        [$gallery, $photo] = $this->deliveryPhoto($buyer);
        $order = $this->paidOrder($buyer, $photo);

        $processor = new LateAuthCopyingImageProcessor;
        $transitioned = false;
        $controller = new LateAuthPhotoDownloadController(
            $processor,
            app(MediaVisibilityService::class),
            function () use ($order, &$transitioned): void {
                if ($transitioned) {
                    return;
                }
                $transitioned = true;
                $order->update(['status' => 'refunded']);
            },
        );
        $this->app->instance(PhotoDownloadController::class, $controller);

        $response = $this->actingAs($buyer, 'api')
            ->getJson("/api/orders/{$order->id}/download-zip");

        $response->assertForbidden();
        $this->assertStringNotContainsString('PK', (string) $response->getContent());
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertDatabaseCount('download_logs', 0);
        $this->assertTrue($transitioned);
        $this->assertFalse($processor->hasScaledFilesOnDisk());
        $this->assertFalse($controller->hasProcessedFilesOnDisk());
        $this->assertSame($gallery->id, $photo->fresh()->gallery_id);
    }

    public function test_single_download_rechecks_dispute_after_metadata_and_removes_processed_derivative(): void
    {
        $buyer = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'none',
        ]);
        [$gallery, $photo] = $this->deliveryPhoto($buyer);
        $order = $this->paidOrder($buyer, $photo);
        $this->assertTrue(app(PurchaseService::class)->hasPurchasedPhoto($buyer, $photo->id, 'original'));
        $transitioned = false;

        $processor = new LateAuthCopyingImageProcessor;
        $controller = new LateAuthPhotoDownloadController(
            $processor,
            app(MediaVisibilityService::class),
            function () use ($order, &$transitioned): void {
                if ($transitioned) {
                    return;
                }
                $transitioned = true;
                $order->update(['status' => 'disputed']);
            },
        );
        $this->app->instance(PhotoDownloadController::class, $controller);

        $response = $this->actingAs($buyer, 'api')
            ->getJson("/api/photos/{$photo->id}/download?tier=original");

        $response->assertForbidden();
        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertDatabaseCount('download_logs', 0);
        $this->assertTrue($transitioned);
        $this->assertFalse($processor->hasScaledFilesOnDisk());
        $this->assertFalse($controller->hasProcessedFilesOnDisk());
        $this->assertFileExists(Storage::disk('photos')->path($gallery->id.'/'.$photo->filename));
        $this->assertSame($gallery->id, $photo->fresh()->gallery_id);
    }

    public function test_direct_media_rechecks_private_access_after_watermark_derivative_preparation(): void
    {
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'none',
        ]);
        [$gallery, $photo] = $this->deliveryPhoto($user);
        $processor = new LateAuthWatermarkImageProcessor;
        $processor->onWatermark = function () use ($user, $gallery): void {
            $user->galleries()->detach($gallery->id);
        };
        $this->app->instance(ImageProcessor::class, $processor);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg');

        $response->assertForbidden()->assertJson(['error' => 'Forbidden']);
        $this->assertFalse($response->headers->has('X-Sendfile'));
        $this->assertDatabaseCount('download_logs', 0);
        $this->assertNull($photo->fresh()->last_accessed_at);
    }

    /**
     * @return array{0: Gallery, 1: Photo}
     */
    private function deliveryPhoto(User $buyer): array
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => false,
            'is_hidden' => false,
        ]);
        $buyer->galleries()->attach($gallery);
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'is_hidden' => false,
        ]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            (string) file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );

        return [$gallery, $photo];
    }

    private function paidOrder(User $buyer, Photo $photo): Order
    {
        $order = Order::factory()->paid()->create([
            'user_id' => $buyer->id,
            'brand' => Brand::B2B,
            'is_quote_request' => false,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'LATE-'.strtoupper(bin2hex(random_bytes(4))),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [[
                    'photoId' => $photo->id,
                    'tier' => 'original',
                    'price' => 1000,
                ]],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => null,
        ]);

        return $order->fresh();
    }
}

final class LateAuthCopyingImageProcessor extends ImageProcessor
{
    /** @var array<int, string> */
    private array $scaledPaths = [];

    public function scaleImage($sourcePath, $destPath, $maxWidth)
    {
        $this->scaledPaths[] = $destPath;
        if (! is_dir(dirname($destPath))) {
            @mkdir(dirname($destPath), 0755, true);
        }

        return copy($sourcePath, $destPath);
    }

    public function hasScaledFilesOnDisk(): bool
    {
        foreach ($this->scaledPaths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}

final class LateAuthPhotoDownloadController extends PhotoDownloadController
{
    /** @var array<int, string> */
    private array $processedPaths = [];

    public function __construct(
        ImageProcessor $imageProcessor,
        MediaVisibilityService $mediaVisibility,
        private readonly \Closure $transition,
    ) {
        parent::__construct($imageProcessor, $mediaVisibility);
    }

    protected function injectMetadata($sourcePath, $photo, $userName, ?string $customConditions = null)
    {
        $directory = storage_path('app/private/temp');
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $path = $directory.'/dl_'.uniqid('', true).'.jpg';
        copy($sourcePath, $path);
        $this->processedPaths[] = $path;
        ($this->transition)();

        return $path;
    }

    public function hasProcessedFilesOnDisk(): bool
    {
        foreach ($this->processedPaths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}

final class LateAuthWatermarkImageProcessor extends ImageProcessor
{
    public ?\Closure $onWatermark = null;

    /** @var array<string, bool> */
    private array $safeOutputs = [];

    public function watermarkAssetAvailable($sourcePath, $galleryType = 'delivery', $maxWidth = null): bool
    {
        return is_file($sourcePath);
    }

    public function isSafeWatermarkedOutput($sourcePath, $destPath, $galleryType = 'delivery', $maxWidth = null): bool
    {
        return ($this->safeOutputs[$destPath] ?? false) && is_file($destPath);
    }

    public function applyCenteredWatermark($sourcePath, $destPath, $maxWidth = null, $galleryType = 'delivery')
    {
        if (! is_dir(dirname($destPath))) {
            @mkdir(dirname($destPath), 0755, true);
        }
        $generated = copy($sourcePath, $destPath);
        if ($generated) {
            $this->safeOutputs[$destPath] = true;
            if ($this->onWatermark instanceof \Closure) {
                ($this->onWatermark)();
            }
        }

        return $generated;
    }
}
