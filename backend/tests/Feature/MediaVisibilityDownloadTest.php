<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaVisibilityDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
        Cache::flush();
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_hidden_photo_revokes_cached_purchase_and_blocks_direct_download_without_log(): void
    {
        $buyer = $this->buyer();
        [$gallery, $photo] = $this->media($buyer, false);
        $this->paidOrder($buyer, $photo);

        $this->assertTrue(app(PurchaseService::class)->hasPurchasedPhoto($buyer, $photo->id, 'original'));
        $photo->update(['is_hidden' => true]);

        $this->assertFalse(app(PurchaseService::class)->hasPurchasedPhoto($buyer, $photo->id, 'original'));
        $response = $this->actingAs($buyer, 'api')
            ->getJson("/api/photos/{$photo->id}/download?tier=original")
            ->assertForbidden();

        $this->assertFalse($response->headers->has('Content-Disposition'));
        $this->assertDatabaseCount('download_logs', 0);
        $this->assertSame(0, $this->photoDerivativeCount($photo));
    }

    public function test_hidden_photo_blocks_free_gallery_zip_without_bytes_or_log(): void
    {
        [, $photo] = $this->media(null, true);
        $photo->update(['is_hidden' => true]);

        $this->getJson("/api/galleries/{$photo->gallery_id}/download-zip?tier=original")
            ->assertForbidden();

        $this->assertDatabaseCount('download_logs', 0);
        $this->assertSame(0, $this->photoDerivativeCount($photo));
    }

    public function test_hidden_gallery_blocks_free_zip_without_bytes_or_log(): void
    {
        [$gallery, $photo] = $this->media(null, true);
        $gallery->update(['is_hidden' => true]);

        $this->getJson("/api/galleries/{$gallery->id}/download-zip?tier=original")
            ->assertForbidden();

        $this->assertDatabaseCount('download_logs', 0);
        $this->assertSame(0, $this->photoDerivativeCount($photo));
    }

    public function test_late_hidden_gallery_zip_removes_log_and_emits_no_bytes(): void
    {
        [$gallery, $photo] = $this->media(null, true);
        $response = $this->getJson("/api/galleries/{$gallery->id}/download-zip?tier=original")
            ->assertOk();
        $this->assertDatabaseCount('download_logs', 1);

        $photo->update(['is_hidden' => true]);
        $this->expectException(StreamedResponseException::class);

        ob_start();
        try {
            $response->sendContent();
        } finally {
            $this->assertSame('', (string) ob_get_clean());
            $this->assertDatabaseCount('download_logs', 0);
            $this->assertSame(0, $this->photoDerivativeCount($photo));
        }
    }

    public function test_inherited_group_hidden_blocks_single_and_zip_without_log(): void
    {
        $group = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'is_hidden' => true,
        ]);
        [$gallery, $photo] = $this->media(null, true, $group);

        $this->getJson("/api/photos/{$photo->id}/download?tier=original")
            ->assertForbidden();
        $this->getJson("/api/galleries/{$gallery->id}/download-zip?tier=original")
            ->assertForbidden();

        $this->assertDatabaseCount('download_logs', 0);
        $this->assertSame(0, $this->photoDerivativeCount($photo));
    }

    private function buyer(): User
    {
        return User::factory()->create(['brand' => Brand::B2B]);
    }

    /**
     * @return array{0: Gallery, 1: Photo}
     */
    private function media(?User $buyer, bool $free, ?GalleryGroup $group = null): array
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $group?->id,
            'type' => 'delivery',
            'is_public' => $free,
            'is_free_download' => $free,
            'is_hidden' => false,
        ]);
        if ($buyer) {
            $buyer->galleries()->attach($gallery);
        }

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
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-MEDIA-'.strtoupper(Str::random(10)),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [[
                    'photoId' => $photo->id,
                    'tier' => 'original',
                    'price' => 3500,
                ]],
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => null,
        ]);

        return $order->fresh();
    }

    private function photoDerivativeCount(Photo $photo): int
    {
        $disk = Storage::disk('photos');
        $prefixes = [
            $photo->gallery_id.'/_thumbs/',
            $photo->gallery_id.'/_watermarked/',
        ];

        $count = 0;
        foreach ($prefixes as $prefix) {
            foreach ($disk->files($prefix) as $file) {
                if (str_contains($file, $photo->id)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
