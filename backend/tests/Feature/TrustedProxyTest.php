<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The API is only reachable through Caddy, and Caddy writes the client
 * address into `X-Forwarded-For`. Without trusting that proxy, every request
 * arrives from the proxy's container address and every rate limiter collapses
 * into one portal-wide bucket (login, API, checkout quota, Turnstile).
 *
 * These tests pin the trust rule: the direct peer is trusted, the forwarded
 * chain is read from the right, and a client-supplied entry cannot replace the
 * client address.
 */
class TrustedProxyTest extends TestCase
{
    public function test_the_client_ip_is_the_forwarded_address_written_by_the_proxy(): void
    {
        $request = $this->requestThroughProxy('203.0.113.7');

        $this->assertSame('203.0.113.7', $request->ip());
    }

    public function test_a_client_supplied_forwarded_entry_cannot_replace_the_client_ip(): void
    {
        // The chain is read from the right: the rightmost entry is the one the
        // proxy wrote, whatever the client put in front of it.
        $request = $this->requestThroughProxy('198.51.100.9, 203.0.113.7');

        $this->assertSame('203.0.113.7', $request->ip());
    }

    public function test_without_the_forwarded_header_the_peer_address_is_used(): void
    {
        $request = Request::create('/api/ping', 'GET', server: ['REMOTE_ADDR' => '172.18.0.3']);

        $this->handleThroughTrustProxies($request);

        $this->assertSame('172.18.0.3', $request->ip());
    }

    public function test_the_forwarded_proto_is_read_from_the_proxy(): void
    {
        $request = Request::create('/api/ping', 'GET', server: [
            'REMOTE_ADDR' => '172.18.0.3',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->handleThroughTrustProxies($request);

        $this->assertTrue($request->isSecure());
    }

    private function requestThroughProxy(string $forwardedFor): Request
    {
        $request = Request::create('/api/ping', 'GET', server: [
            'REMOTE_ADDR' => '172.18.0.3',
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]);

        $this->handleThroughTrustProxies($request);

        return $request;
    }

    private function handleThroughTrustProxies(Request $request): void
    {
        app(TrustProxies::class)->handle($request, static fn (Request $request): Request => $request);
    }
}
