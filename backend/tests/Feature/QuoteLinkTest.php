<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QuoteLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_can_generate_quote_link()
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => ['uuid-1', 'uuid-2'],
                'custom_price' => 120000
            ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'link']);
                 
        $this->assertStringContainsString('quote_token=', $response->json('link'));
    }

    public function test_client_cannot_generate_quote_link()
    {
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));
        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => ['uuid-1'],
                'custom_price' => 10000
            ]);

        $response->assertStatus(403);
    }

    public function test_valid_token_decodes_successfully()
    {
        $payload = base64_encode(json_encode([
            'photos' => ['uuid-test'],
            'price' => 500,
            'exp' => time() + 86400
        ]));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $validToken = $payload . '.' . $signature;

        $response = $this->getJson("/api/orders/quote-decode?token={$validToken}");
        
        $response->assertStatus(200)
                 ->assertJson([
                     'photos' => ['uuid-test'],
                     'price' => 500
                 ]);
    }
}
