<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-B1 (CRITICAL) — only settled order statuses may grant media downloads.
 *
 * Regression: a `pending_payment` order (Stripe intent created, not confirmed)
 * must not pass `PurchaseService::hasPurchasedPhoto()` nor the
 * `/orders/{id}/download-zip` gate, while a `paid` order still does.
 */
class PurchasePendingPaymentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    /**
     * @return array{0: User, 1: Gallery, 2: Photo, 3: Order}
     */
    private function makeAccessibleOrder(string $status): array
    {
        $user = User::factory()->create(['flatrate_level' => 'none', 'brand' => 'rp']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );

        $order = Order::create([
            'user_id' => $user->id,
            'status' => $status,
            'is_quote_request' => false,
            'brand' => 'rp',
            'total_amount' => 5000,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'brand' => 'rp',
            'customer_details' => [
                'name' => $user->name,
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 5000],
                ],
            ],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        return [$user, $gallery, $photo, $order];
    }

    public function test_pending_payment_order_does_not_grant_photo_download(): void
    {
        [$user, , $photo] = $this->makeAccessibleOrder('pending_payment');

        $this->assertFalse(app(PurchaseService::class)->hasPurchasedPhoto($user, $photo->id, 'original'));

        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(403);
    }

    public function test_pending_payment_order_does_not_grant_order_zip_download(): void
    {
        [$user, , , $order] = $this->makeAccessibleOrder('pending_payment');

        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/orders/'.$order->id.'/download-zip')
            ->assertStatus(403);
    }

    public function test_paid_order_grants_photo_and_order_zip_download(): void
    {
        [$user, , $photo, $order] = $this->makeAccessibleOrder('paid');

        $this->assertTrue(app(PurchaseService::class)->hasPurchasedPhoto($user, $photo->id, 'original'));

        $token = auth('api')->login($user);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/orders/'.$order->id.'/download-zip')
            ->assertStatus(200);
    }
}
