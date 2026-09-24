<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\OfferTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_can_generate_quote_link()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'restricted_photographers' => false,
        ]);
        $photos = Photo::factory()->count(2)->create(['gallery_id' => $gallery->id]);
        $photographer = User::factory()->create(['brand' => 'rp']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $photographer->photographerGalleries()->attach($gallery);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => $photos->pluck('id')->all(),
                'custom_price' => 120000,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'link']);

        $this->assertStringContainsString('quote_token=', $response->json('link'));
    }

    public function test_admin_can_generate_quote_link_for_owned_brand_photo(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $admin = User::factory()->create(['brand' => 'rp']);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => [$photo->id],
                'custom_price' => 120000,
            ])
            ->assertOk()
            ->assertJsonStructure(['success', 'link']);
    }

    public function test_client_cannot_generate_quote_link()
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $token = auth('api')->login($client);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => [$photo->id],
                'custom_price' => 10000,
            ]);

        $response->assertStatus(403);
    }

    public function test_generated_link_decodes_via_api()
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'restricted_photographers' => false,
        ]);
        $photos = Photo::factory()->count(2)->create(['gallery_id' => $gallery->id]);
        $photographer = User::factory()->create(['brand' => 'rp']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $photographer->photographerGalleries()->attach($gallery);
        $token = auth('api')->login($photographer);

        $generate = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => $photos->pluck('id')->all(),
                'custom_price' => 9900,
            ]);

        $generate->assertStatus(200);
        $link = $generate->json('link');
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $jwt = $query['quote_token'];

        $decode = $this->getJson("/api/orders/quote-decode?token={$jwt}");
        $decode->assertStatus(200)
            ->assertJson([
                'photos' => $photos->pluck('id')->all(),
                'price' => 9900,
                'brand' => 'rp',
            ]);
    }

    public function test_expired_quote_token_is_rejected()
    {
        $gallery = Gallery::factory()->create(['is_public' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $jwt = app(OfferTokenService::class)
            ->issueQuote([$photo->id], 1, brand: 'rp', expiresAt: now()->subDay());

        $this->getJson("/api/orders/quote-decode?token={$jwt}")
            ->assertStatus(410);
    }
}
