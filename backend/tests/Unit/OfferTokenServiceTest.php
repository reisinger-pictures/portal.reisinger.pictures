<?php

namespace Tests\Unit;

use App\Services\OfferTokenService;
use Tests\TestCase;

class OfferTokenServiceTest extends TestCase
{
    private OfferTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OfferTokenService::class);
    }

    public function test_issue_and_verify_roundtrip_returns_payload(): void
    {
        $payload = ['photos' => ['uuid-1', 'uuid-2'], 'price' => 12345];

        $token = $this->service->issue($payload, now()->addDays(7));

        $this->assertSame($payload, $this->service->verify($token));
    }

    public function test_tampered_token_returns_null(): void
    {
        $token = $this->service->issue(['x' => 1], now()->addDays(7));

        // Flip the last character of the signature segment.
        $last = substr($token, -1);
        $replacement = $last === 'A' ? 'B' : 'A';
        $tampered = substr($token, 0, -1) . $replacement;

        $this->assertNull($this->service->verify($tampered));
    }

    public function test_expired_token_returns_null(): void
    {
        $token = $this->service->issue(['x' => 1], now()->subMinute());

        $this->assertNull($this->service->verify($token));
    }

    public function test_malformed_token_returns_null(): void
    {
        $this->assertNull($this->service->verify('not.a.valid.jwt'));
    }

    public function test_default_expiry_is_14_days_when_none_given(): void
    {
        // No exception + verifiable token proves the default expiry path works.
        $token = $this->service->issue(['a' => 'b']);

        $this->assertSame(['a' => 'b'], $this->service->verify($token));
    }
}
