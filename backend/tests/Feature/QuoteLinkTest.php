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
        $photographer->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
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
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => ['uuid-1'],
                'custom_price' => 10000
            ]);

        $response->assertStatus(403);
    }

    public function test_generated_link_decodes_via_api()
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($photographer);

        $generate = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => ['uuid-decode-1', 'uuid-decode-2'],
                'custom_price' => 9900
            ]);

        $generate->assertStatus(200);
        $link = $generate->json('link');
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $jwt = $query['quote_token'];

        $decode = $this->getJson("/api/orders/quote-decode?token={$jwt}");
        $decode->assertStatus(200)
               ->assertJson([
                   'photos' => ['uuid-decode-1', 'uuid-decode-2'],
                   'price' => 9900,
               ]);
    }

    public function test_expired_quote_token_is_rejected()
    {
        $jwt = app(\App\Services\OfferTokenService::class)
            ->issue(['photos' => ['x'], 'price' => 1], now()->subDay());

        $this->getJson("/api/orders/quote-decode?token={$jwt}")
             ->assertStatus(410);
    }
}
