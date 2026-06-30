<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;

class StripeIdempotencyTest extends TestCase {
    use RefreshDatabase;

    public function test_checkout_sends_idempotency_key_to_stripe() {
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street', 'brand' => 'rp'], ['value' => 'Teststreet 1']);
        \App\Models\Setting::updateOrCreate(['key' => 'price_web', 'brand' => 'rp'], ['value' => '75.00']);
        \App\Models\Setting::updateOrCreate(['key' => 'price_print', 'brand' => 'rp'], ['value' => '145.00']);
        \App\Models\Setting::updateOrCreate(['key' => 'price_original', 'brand' => 'rp'], ['value' => '450.00']);
        \App\Models\Setting::updateOrCreate(['key' => 'mult_commercial', 'brand' => 'rp'], ['value' => '2.0']);
        \App\Models\Setting::updateOrCreate(['key' => 'mult_unlimited', 'brand' => 'rp'], ['value' => '1.5']);
        \App\Models\Setting::updateOrCreate(['key' => 'mult_international', 'brand' => 'rp'], ['value' => '1.5']);

        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $useCase = \App\Models\LicenseUseCase::create(['name' => 'Test License', 'base_price' => 45000, 'flatrate_tier' => 'original', 'brand' => 'rp']);

        // Wir injizieren einen Mock für den Stripe HTTP Client, um echte Netzwerkanfragen 
        // zu blockieren und stattdessen den Idempotency-Key Header abzufangen.
        $clientMock = $this->createMock(\Stripe\HttpClient\ClientInterface::class);
        $clientMock->expects($this->once())
                   ->method('request')
                   ->willReturnCallback(function($method, $absUrl, $headers, $params, $hasFile) {
                       $hasIdempotencyKey = false;
                       foreach ($headers as $header) {
                           if (str_starts_with($header, 'Idempotency-Key: pi_')) {
                               $hasIdempotencyKey = true;
                           }
                       }
                       $this->assertTrue($hasIdempotencyKey, 'Idempotency-Key header is missing!');
                       
                       return [json_encode(['id' => 'pi_test', 'client_secret' => 'sec_test']), 200, []];
                   });

        \Stripe\ApiRequestor::setHttpClient($clientMock);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/orders/checkout", [
                'items' => [['photoId' => $photo->id, 'tier' => 'original', 'useCaseId' => $useCase->id]],
                'billing_name' => 'Tester',
                'billing_street' => 'Street',
                'billing_zip' => '1234',
                'billing_city' => 'City',
                'withdrawal_waived' => true
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['client_secret', 'order_id']);
        
        \Stripe\ApiRequestor::setHttpClient(null);
    }
}
