<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\InvoiceSnapshot;
use App\Models\ModelProfile;
use App\Models\Order;
use App\Models\Photo;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\ActorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Regression coverage for CR-BE-001: a null users.id is not an owner.
 */
class GuestOrderIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function guestToken(string $guestId, bool $includeGuestClaim = true, ?Gallery $gallery = null): string
    {
        $gallery ??= Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'guest-test-'.Str::random(12),
        ]);

        $claims = [
            'sub' => 'guest_'.$guestId,
            'guest_name' => 'Guest',
            'guest_invite_id' => $invite->id,
            'transient_galleries' => [$gallery->id],
            'transient_invites' => [
                $invite->id => [
                    'gallery_ids' => [$gallery->id],
                    'meta_gallery_ids' => [],
                ],
            ],
        ];
        if ($includeGuestClaim) {
            $claims['guest_id'] = $guestId;
        }

        $factory = app(Factory::class);
        $payload = $factory->customClaims($claims)->make();

        return app(JWTAuth::class)->encode($payload)->get();
    }

    private function guestActor(string $guestId): User
    {
        $actor = new User;
        $actor->id = null;
        $actor->guest_id = $guestId;
        $actor->name = 'Guest';

        return $actor;
    }

    private function orderForActor(?string $userId, ?string $guestId, string $photoId): Order
    {
        $order = Order::create([
            'user_id' => $userId,
            'guest_id' => $guestId,
            'status' => 'paid',
            'brand' => 'rp',
            'total_amount' => 3500,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'RE-'.strtoupper(Str::random(12)),
            'brand' => 'rp',
            'customer_details' => [
                'name' => $guestId ?: 'Registered customer',
                'items' => [
                    ['photoId' => $photoId, 'tier' => 'original', 'price' => 3500],
                ],
            ],
            'total_net' => 3500,
            'total_gross' => 3500,
            'tax_rate' => null,
        ]);

        return $order->fresh();
    }

    public function test_two_transient_guests_cannot_read_each_others_orders_or_downloads(): void
    {
        Storage::fake('photos');
        $gallery = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );
        $photoId = $photo->id;
        $guestAId = (string) Str::uuid();
        $guestBId = (string) Str::uuid();
        $orderA = $this->orderForActor(null, $guestAId, $photoId);
        $orderB = $this->orderForActor(null, $guestBId, $photoId);

        $tokenA = $this->guestToken($guestAId, gallery: $gallery);
        $tokenB = $this->guestToken($guestBId, gallery: $gallery);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $orderA->id]);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/orders/'.$orderB->id)
            ->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/orders/'.$orderB->id.'/invoice')
            ->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->get('/api/orders/'.$orderA->id.'/invoice')
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/orders/'.$orderB->id.'/download-zip')
            ->assertNotFound();
        $this->assertDatabaseCount('download_logs', 0);

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->get('/api/orders/'.$orderA->id.'/download-zip')
            ->assertOk();
        $this->assertDatabaseHas('download_logs', [
            'order_id' => $orderA->id,
            'user_id' => null,
            'guest_id' => $guestAId,
        ]);

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $orderB->id]);
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/orders/'.$orderA->id)
            ->assertNotFound();
    }

    public function test_guest_subject_is_used_when_the_guest_id_claim_is_missing(): void
    {
        $photoId = (string) Str::uuid();
        $guestId = (string) Str::uuid();
        $order = $this->orderForActor(null, $guestId, $photoId);

        $this->withHeader('Authorization', 'Bearer '.$this->guestToken($guestId, false))
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);
    }

    public function test_guest_purchase_cache_is_scoped_to_the_signed_guest_identity(): void
    {
        Cache::flush();
        $gallery = Gallery::factory()->create([
            'brand' => 'rp',
            'type' => 'delivery',
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $photoId = $photo->id;
        $guestAId = (string) Str::uuid();
        $guestBId = (string) Str::uuid();
        $this->orderForActor(null, $guestAId, $photoId);

        $service = app(PurchaseService::class);
        $guestA = $this->guestActor($guestAId);
        $guestB = $this->guestActor($guestBId);

        $this->assertTrue($service->hasPurchasedPhoto($guestA, $photoId, 'original'));
        $this->assertFalse($service->hasPurchasedPhoto($guestB, $photoId, 'original'));

        $keyA = ActorIdentity::purchaseCacheKeyForActor($guestA, $photoId, 'original');
        $keyB = ActorIdentity::purchaseCacheKeyForActor($guestB, $photoId, 'original');
        $this->assertNotNull($keyA);
        $this->assertNotNull($keyB);
        $this->assertNotSame($keyA, $keyB);
        $this->assertTrue(Cache::get($keyA));
        $this->assertNull(Cache::get($keyB));
    }

    public function test_legacy_both_null_orders_are_not_customer_accessible(): void
    {
        $photoId = (string) Str::uuid();
        $legacyOrder = $this->orderForActor(null, null, $photoId);
        $guestId = (string) Str::uuid();
        $registered = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->guestToken($guestId))
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(0);
        $this->withHeader('Authorization', 'Bearer '.$this->guestToken($guestId))
            ->getJson('/api/orders/'.$legacyOrder->id)
            ->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->guestToken($guestId))
            ->getJson('/api/orders/'.$legacyOrder->id.'/invoice')
            ->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->guestToken($guestId))
            ->getJson('/api/orders/'.$legacyOrder->id.'/download-zip')
            ->assertNotFound();

        Auth::forgetGuards();
        $this->actingAs($registered, 'api')
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(0);
        $this->assertFalse(app(PurchaseService::class)->hasPurchasedPhoto(
            $this->guestActor($guestId),
            $photoId,
            'original',
        ));
        $this->assertFalse($registered->hasPurchasedPhoto($photoId, 'original'));
    }

    public function test_guest_without_an_identifiable_claim_cannot_match_ownerless_orders(): void
    {
        $photoId = (string) Str::uuid();
        $this->orderForActor(null, null, $photoId);
        $unidentified = new User;
        $unidentified->id = null;

        $this->assertFalse(app(PurchaseService::class)->hasPurchasedPhoto(
            $unidentified,
            $photoId,
            'original',
        ));
        $this->assertSame(0, Order::query()->ownedBy($unidentified)->count());
    }

    public function test_guest_cannot_open_the_registered_model_profile_endpoint(): void
    {
        $customer = Customer::factory()->create([
            'user_id' => null,
            'is_model' => true,
        ]);
        ModelProfile::factory()->create(['customer_id' => $customer->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->guestToken((string) Str::uuid()))
            ->getJson('/api/me/models')
            ->assertForbidden()
            ->assertJsonPath('error', 'Meine Profile sind nur für registrierte Konten verfügbar.');
    }

    public function test_order_owner_invariant_and_v037_indexes_are_present(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'guest_id'));
        $indexes = collect(Schema::getIndexes('orders'))->keyBy('name');
        $this->assertTrue($indexes->has('orders_guest_id_idx'));
        $this->assertTrue($indexes->has('orders_guest_owner_idx'));
        $this->assertTrue($indexes->has('orders_guest_fingerprint_lookup_idx'));
        $this->assertTrue($indexes->has('orders_guest_checkout_key_unique'));

        $this->expectException(\InvalidArgumentException::class);
        Order::create([
            'user_id' => User::factory()->create()->id,
            'guest_id' => (string) Str::uuid(),
            'status' => 'paid',
            'total_amount' => 100,
        ]);
    }

    public function test_guest_checkout_fails_closed_without_creating_a_shadow_user_or_order(): void
    {
        $guestId = (string) Str::uuid();
        $token = $this->guestToken($guestId);
        $photo = Photo::factory()->create(['user_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/orders/checkout', [
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'web'],
                ],
                'billing_name' => 'Gast',
                'billing_street' => 'Teststraße 1',
                'billing_zip' => '1010',
                'billing_city' => 'Wien',
                'withdrawal_waived' => true,
            ]);

        // The request is rejected by the controller before the service can
        // touch a transient User instance (which must never become a DB user).
        $response->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}
