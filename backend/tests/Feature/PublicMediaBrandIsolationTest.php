<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public media surfaces are bound to the request host brand.  A second brand
 * value and a legacy null-brand row are deliberately used here because the
 * production configuration currently ships only the rp brand.
 */
class PublicMediaBrandIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['scout.driver' => 'null']);
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
        $this->useTemporaryStorageDisk('photos');
    }

    public function test_public_media_rejects_foreign_and_null_brand_galleries(): void
    {
        foreach (['srp', null] as $brand) {
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'delivery',
                'is_public' => true,
                'is_free_download' => true,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            Storage::disk('photos')->put(
                $gallery->id.'/'.$photo->filename,
                file_get_contents(base_path('tests/Fixtures/sample.jpg')),
            );

            $this->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
                ->assertStatus(404)
                ->assertJson(['error' => 'Galerie nicht gefunden']);
        }
    }

    public function test_public_gallery_show_rejects_foreign_and_null_brand_galleries(): void
    {
        foreach (['srp', null] as $brand) {
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'delivery',
                'is_public' => true,
            ]);

            $this->getJson('/api/galleries/'.$gallery->slug)->assertNotFound();
        }
    }

    public function test_public_download_and_context_reject_foreign_and_null_brand_galleries(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['srp', null] as $brand) {
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'delivery',
                'is_public' => true,
                'is_free_download' => true,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

            $this->getJson('/api/photos/'.$photo->id.'/download')->assertNotFound();
            $this->getJson('/api/galleries/'.$gallery->id.'/download-zip')->assertNotFound();
            $this->getJson('/api/photos/'.$photo->id.'/context')->assertNotFound();
        }
    }

    public function test_rating_rejects_foreign_and_null_brand_galleries_without_mutation(): void
    {
        foreach (['srp', null] as $brand) {
            $user = User::factory()->create(['brand' => 'rp']);
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'selection',
                'is_public' => false,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $user->galleries()->attach($gallery);
            $token = auth('api')->login($user);

            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/photos/'.$photo->id.'/rate', ['rating' => 5])
                ->assertNotFound();

            $this->assertDatabaseCount('ratings', 0);
        }
    }

    public function test_order_zip_rejects_foreign_and_null_brand_photo_items(): void
    {
        foreach (['srp', null] as $brand) {
            $user = User::factory()->create(['brand' => 'rp']);
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'delivery',
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $order = Order::factory()->paid()->create([
                'user_id' => $user->id,
                'brand' => 'rp',
            ]);
            InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => 'BRAND-'.$order->id,
                'customer_details' => [
                    'items' => [
                        ['photoId' => $photo->id, 'tier' => 'original', 'price' => 1000],
                    ],
                ],
                'total_net' => 1000,
                'total_gross' => 1000,
                'tax_rate' => 0,
            ]);

            $this->actingAs($user, 'api')
                ->getJson('/api/orders/'.$order->id.'/download-zip')
                ->assertNotFound();

            $this->assertDatabaseCount('download_logs', 0);
        }
    }

    public function test_search_returns_only_the_active_brand(): void
    {
        $own = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $foreign = Gallery::factory()->create([
            'brand' => 'srp',
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $legacy = Gallery::factory()->create([
            'brand' => null,
            'type' => 'delivery',
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/search?q=')->assertOk();
        $ids = collect($response->json('galleries'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($foreign->id));
        $this->assertFalse($ids->contains($legacy->id));
    }

    public function test_rating_for_same_brand_gallery_remains_available(): void
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $gallery = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'selection',
            'is_public' => false,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user->galleries()->attach($gallery);
        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/photos/'.$photo->id.'/rate', ['rating' => 5])
            ->assertOk();

        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'rating' => 5,
        ]);
    }
}
