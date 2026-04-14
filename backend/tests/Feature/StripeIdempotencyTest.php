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
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'company_street'], ['value' => 'Teststreet 1']);
        \App\Models\Setting::updateOrCreate(['key' => 'base_price'], ['value' => '35.00']);

        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => false]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

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
                'items' => [['photoId' => $photo->id, 'tier' => 'original', 'usage' => 'commercial', 'duration' => 'unlimited']],
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
