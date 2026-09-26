<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\LicenseUseCase;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class StripeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_sends_idempotency_key_to_stripe()
    {
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '75.00']);
        Setting::updateOrCreate(['key' => 'price_print', 'brand' => 'rp'], ['value' => '145.00']);
        Setting::updateOrCreate(['key' => 'price_original', 'brand' => 'rp'], ['value' => '450.00']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'mult_unlimited', 'brand' => 'rp'], ['value' => '1.5']);
        Setting::updateOrCreate(['key' => 'mult_international', 'brand' => 'rp'], ['value' => '1.5']);

        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create(['name' => 'Test License', 'base_price' => 45000, 'flatrate_tier' => 'original', 'brand' => 'rp']);

        // Wir injizieren einen Mock für den Stripe HTTP Client, um echte Netzwerkanfragen
        // zu blockieren und stattdessen den Idempotency-Key Header abzufangen.
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) {
                $hasIdempotencyKey = false;
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                        $hasIdempotencyKey = true;
                    }
                }
                $this->assertTrue($hasIdempotencyKey, 'Idempotency-Key header is missing!');

                return [json_encode([
                    'id' => 'pi_test',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'sec_test',
                    'amount' => (int) ($params['amount'] ?? 0),
                    'currency' => $params['currency'] ?? 'eur',
                    'created' => time(),
                    'customer' => $params['customer'] ?? null,
                    'metadata' => $params['metadata'] ?? [],
                ]), 200, []];
            });

        ApiRequestor::setHttpClient($clientMock);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/orders/checkout', [
                'items' => [['photoId' => $photo->id, 'tier' => 'original', 'useCaseId' => $useCase->id]],
                'billing_name' => 'Tester',
                'billing_street' => 'Street',
                'billing_zip' => '1234',
                'billing_city' => 'City',
                'withdrawal_waived' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['client_secret', 'order_id']);

        ApiRequestor::setHttpClient(null);
    }

    public function test_two_checkout_sessions_with_different_fingerprints_use_distinct_stripe_keys(): void
    {
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);
        Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '75.00']);
        Setting::updateOrCreate(['key' => 'price_print', 'brand' => 'rp'], ['value' => '145.00']);
        Setting::updateOrCreate(['key' => 'price_original', 'brand' => 'rp'], ['value' => '450.00']);
        Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        Setting::updateOrCreate(['key' => 'mult_unlimited', 'brand' => 'rp'], ['value' => '1.5']);
        Setting::updateOrCreate(['key' => 'mult_international', 'brand' => 'rp'], ['value' => '1.5']);

        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = LicenseUseCase::create(['name' => 'Test License', 'base_price' => 45000, 'flatrate_tier' => 'original', 'brand' => 'rp']);

        $keysUsed = [];
        $callCount = 0;

        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $absUrl, $headers, $params, $hasFile) use (&$keysUsed, &$callCount) {
                $callCount++;
                $foundKey = null;
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                        $foundKey = $header;
                        break;
                    }
                }
                $this->assertNotNull($foundKey, "Call #$callCount missing Idempotency-Key header");
                $keysUsed[] = $foundKey;

                return [json_encode([
                    'id' => 'pi_test_'.$callCount,
                    'status' => 'requires_payment_method',
                    'client_secret' => 'sec_test_'.$callCount,
                    'amount' => (int) ($params['amount'] ?? 0),
                    'currency' => $params['currency'] ?? 'eur',
                    'created' => time(),
                    'customer' => $params['customer'] ?? null,
                    'metadata' => $params['metadata'] ?? [],
                ]), 200, []];
            });

        ApiRequestor::setHttpClient($clientMock);

        $requestBody = [
            'items' => [['photoId' => $photo->id, 'tier' => 'original', 'useCaseId' => $useCase->id]],
            'billing_name' => 'Tester',
            'billing_street' => 'Street',
            'billing_zip' => '1234',
            'billing_city' => 'City',
            'withdrawal_waived' => true,
        ];

        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'Idempotency-Key' => 'independent-checkout-0001',
        ])
            ->postJson('/api/orders/checkout', $requestBody);
        $firstResponse->assertStatus(200);
        $firstResponse->assertJsonStructure(['client_secret', 'order_id']);

        // A different server fingerprint represents a separate checkout
        // session; a new key with the same fingerprint is covered by the
        // lost-session fallback test.
        $requestBody['billing_street'] = 'Other Street 9';

        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'Idempotency-Key' => 'independent-checkout-0002',
        ])
            ->postJson('/api/orders/checkout', $requestBody);
        $secondResponse->assertStatus(200);
        $secondResponse->assertJsonStructure(['client_secret', 'order_id']);

        $this->assertCount(2, $keysUsed);
        $this->assertNotEquals($keysUsed[0], $keysUsed[1], 'Two independent checkout requests must use different Stripe idempotency keys');

        ApiRequestor::setHttpClient(null);
    }
}
