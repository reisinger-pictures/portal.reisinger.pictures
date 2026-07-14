<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\QuoteLinkService;
use App\Services\OfferTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QuoteLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuoteLinkService::class);
    }

    public function test_generate_quote_link_returns_url_with_token(): void
    {
        $url = $this->service->generateQuoteLink(['uuid-1', 'uuid-2'], 50000);

        $this->assertStringContainsString('/cart?quote_token=', $url);
    }

    public function test_decode_returns_payload_for_valid_token(): void
    {
        $url = $this->service->generateQuoteLink(['uuid-a', 'uuid-b'], 25000);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['quote_token'];

        $payload = $this->service->decode($token);

        $this->assertIsArray($payload);
        $this->assertSame(['uuid-a', 'uuid-b'], $payload['photos']);
        $this->assertSame(25000, $payload['price']);
    }

    public function test_decode_returns_null_for_invalid_token(): void
    {
        $this->assertNull($this->service->decode('invalid-jwt-token'));
    }

    public function test_decode_returns_null_for_tampered_token(): void
    {
        $url = $this->service->generateQuoteLink(['uuid-1'], 10000);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['quote_token'];

        $tampered = substr($token, 0, -5) . 'XXXXX';

        $this->assertNull($this->service->decode($tampered));
    }

    public function test_decode_returns_null_for_expired_token(): void
    {
        $payload = ['photos' => ['x'], 'price' => 1];
        $expiredToken = app(OfferTokenService::class)->issue($payload, now()->subDay());

        $this->assertNull($this->service->decode($expiredToken));
    }

}
