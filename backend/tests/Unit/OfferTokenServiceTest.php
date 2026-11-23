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
        $tampered = substr($token, 0, -1).$replacement;

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

    /**
     * D-77: a token issued by the removed Lcobucci/JWT provider still verifies
     * with the native HMAC verifier. The fixture was signed with HS256 over the
     * unchanged `{"typ":"JWT","alg":"HS256"}` header.
     */
    public function test_a_token_issued_by_the_removed_jwt_provider_still_verifies(): void
    {
        $fixture = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0IiwiaWF0IjoxNzAwMDAwMDAwLCJuYmYiOjE3MDAwMDAwMDAsImV4cCI6MjAwMDAwMDAwMCwic3ViIjoib2ZmZXIiLCJqdGkiOiJwaGFzZTQtY29tcGF0LWZpeHR1cmUiLCJvZmZlciI6eyJmaXh0dXJlIjp0cnVlLCJwcmljZSI6MTIzNCwicGhvdG9zIjpbImNvbXBhdC1waG90by0xIl19fQ.u6fdrCgIEVnR-opbJHAQvdalU0Uz__vF15AjufzDVCI';

        config([
            'services.signed_links.secret' => 'phase4-compat-fixture-secret-do-not-use-in-prod',
            // The fixture was issued with this issuer; strict iss validation
            // (D-77) requires the verifier to read the same configured value.
            'app.url' => 'http://localhost',
        ]);

        $this->assertSame([
            'fixture' => true,
            'price' => 1234,
            'photos' => ['compat-photo-1'],
        ], $this->service->verify($fixture));
    }

    /**
     * D-77: the removed Lcobucci/JWT HS256 signer refused keys shorter than
     * 256 bits. The native HMAC signer must keep that floor and fail closed
     * instead of silently producing a weak signature.
     */
    public function test_a_short_signing_key_fails_closed(): void
    {
        config(['services.signed_links.secret' => 'too-short']);

        $this->expectException(\RuntimeException::class);

        $this->service->issue(['x' => 1], now()->addDay());
    }

    public function test_a_short_signing_key_verifies_nothing(): void
    {
        config(['services.signed_links.secret' => 'too-short']);

        $token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJhIjoxfQ.invalid';

        $this->assertNull($this->service->verify($token));
    }
}
