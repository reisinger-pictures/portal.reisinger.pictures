<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\OfferTokenService;
use App\Services\QuoteLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteLinkService $service;

    private User $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuoteLinkService::class);
        $this->issuer = User::factory()->create(['brand' => 'rp']);
        $this->issuer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
    }

    /**
     * @return array<int, string>
     */
    private function realPhotoIds(int $count = 2): array
    {
        $gallery = Gallery::factory()->create([
            'is_public' => true,
            'restricted_photographers' => false,
        ]);
        $photos = Photo::factory()->count($count)->create(['gallery_id' => $gallery->id]);
        $this->issuer->photographerGalleries()->attach($gallery);

        return $photos->pluck('id')->all();
    }

    public function test_generate_quote_link_returns_url_with_token(): void
    {
        $url = $this->service->generateQuoteLink($this->realPhotoIds(), 50000, issuer: $this->issuer);

        $this->assertStringContainsString('/cart?quote_token=', $url);
    }

    public function test_decode_returns_payload_for_valid_token(): void
    {
        $photoIds = $this->realPhotoIds();
        $url = $this->service->generateQuoteLink($photoIds, 25000, issuer: $this->issuer);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['quote_token'];

        $payload = $this->service->decode($token);

        $this->assertIsArray($payload);
        $this->assertSame($photoIds, $payload['photos']);
        $this->assertSame(25000, $payload['price']);
        $this->assertSame('rp', $payload['brand']);
    }

    public function test_decode_returns_null_for_invalid_token(): void
    {
        $this->assertNull($this->service->decode('invalid-jwt-token'));
    }

    public function test_decode_returns_null_for_tampered_token(): void
    {
        $url = $this->service->generateQuoteLink($this->realPhotoIds(1), 10000, issuer: $this->issuer);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['quote_token'];

        $tampered = substr($token, 0, -5).'XXXXX';

        $this->assertNull($this->service->decode($tampered));
    }

    public function test_decode_returns_null_for_expired_token(): void
    {
        $photoIds = $this->realPhotoIds(1);
        $expiredToken = app(OfferTokenService::class)->issueQuote(
            $photoIds,
            1,
            brand: 'rp',
            expiresAt: now()->subDay(),
        );

        $this->assertNull($this->service->decode($expiredToken));
    }

    public function test_decode_rejects_a_token_when_a_photo_no_longer_exists(): void
    {
        $photoIds = $this->realPhotoIds(1);
        $url = $this->service->generateQuoteLink($photoIds, 10000, issuer: $this->issuer);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        Photo::whereKey($photoIds[0])->delete();

        $this->assertNull($this->service->decode($query['quote_token']));
    }
}
