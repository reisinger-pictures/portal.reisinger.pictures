<?php

namespace Tests\Feature\Middleware;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_response_includes_security_headers(): void
    {
        // The /up health route is a simple public route to inspect headers.
        $response = $this->getJson('/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_header_absent_over_http(): void
    {
        // In the test environment requests are HTTP — HSTS must NOT be set,
        // otherwise a dev environment would get pinned.
        $response = $this->getJson('/up');

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
