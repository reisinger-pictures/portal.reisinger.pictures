<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P0-B12 (MEDIUM) — `refunded` means a FULL refund. A partial refund must not
 * revoke the customer's download access.
 */
class PartialRefundAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
        Mail::fake();
    }

    /**
     * @return array{0: User, 1: Photo, 2: Order}
     */
    private function makePaidOrder(string $paymentIntent = 'pi_partial_refund'): array
    {
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'stripe_payment_intent_id' => $paymentIntent,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
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

        return [$user, $photo, $order];
    }

    private function postRefund(array $charge, string $secret = 'whsec_partial'): void
    {
        Config::set('services.stripe.webhook_secret', $secret);

        $payloadData = [
            'type' => 'charge.refunded',
            'data' => ['object' => $charge],
        ];
        $payload = json_encode($payloadData);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $this->postJson('/api/webhooks/stripe', $payloadData, [
            'Stripe-Signature' => "t={$timestamp},v1={$signature}",
        ])->assertStatus(200);
    }

    public function test_partial_refund_preserves_order_status_and_download_access(): void
    {
        [$user, $photo, $order] = $this->makePaidOrder();

        $this->postRefund([
            'payment_intent' => 'pi_partial_refund',
            'refunded' => false,
            'amount' => 5000,
            'amount_refunded' => 1500,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        $token = auth('api')->login($user);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(200);
    }

    public function test_full_refund_revokes_access(): void
    {
        [$user, $photo, $order] = $this->makePaidOrder('pi_full_refund');

        $this->postRefund([
            'payment_intent' => 'pi_full_refund',
            'refunded' => true,
            'amount' => 5000,
            'amount_refunded' => 5000,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);

        $token = auth('api')->login($user);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(403);
    }

    public function test_refund_amount_fallback_treats_underpayment_as_partial(): void
    {
        [$user, $photo, $order] = $this->makePaidOrder('pi_amount_fallback_partial');

        // No `refunded` flag: amount_refunded < amount → partial → access stays.
        $this->postRefund([
            'payment_intent' => 'pi_amount_fallback_partial',
            'amount' => 5000,
            'amount_refunded' => 2000,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        $token = auth('api')->login($user);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(200);
    }

    public function test_refund_amount_fallback_treats_full_amount_as_full_refund(): void
    {
        [$user, $photo, $order] = $this->makePaidOrder('pi_amount_fallback_full');

        // No `refunded` flag: amount_refunded >= amount → full → access revoked.
        $this->postRefund([
            'payment_intent' => 'pi_amount_fallback_full',
            'amount' => 5000,
            'amount_refunded' => 5000,
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);

        $token = auth('api')->login($user);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->get('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertStatus(403);
    }
}
