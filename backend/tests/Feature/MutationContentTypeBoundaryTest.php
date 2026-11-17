<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mutation boundary allows JSON and the listed multipart paths only.
 *
 * Mollie's webhook posts `application/x-www-form-urlencoded` with a single
 * `id`. Real traffic proved the gap: the paid test payment's webhook was
 * answered with 415 and retried by Mollie, so the order settled only through
 * the buyer's return page. The allowance is scoped to that one ingress — a form
 * body anywhere else must stay rejected, otherwise the boundary is gone.
 */
class MutationContentTypeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mollie_webhook_accepts_a_form_encoded_body(): void
    {
        $response = $this->post('/api/webhooks/mollie', ['id' => 'tr_formEncodedBoundary']);

        // Without a credential the endpoint answers 503 (fail-closed); what
        // matters here is that the boundary let the request reach it at all.
        $this->assertNotSame(415, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_a_form_encoded_body_is_still_rejected_elsewhere(): void
    {
        $this->post('/api/orders/checkout', ['items' => []])->assertStatus(415);
    }

    public function test_the_json_contract_is_unchanged(): void
    {
        // Only the boundary is under test: JSON must reach the endpoint. What
        // it answers depends on the environment — 200 for an unknown id, 503
        // when no Mollie credential is configured (fail-closed) — so pinning
        // one of them made the test pass locally and fail in CI.
        $response = $this->postJson('/api/webhooks/mollie', ['id' => 'tr_JsonBoundary']);

        $this->assertNotSame(415, $response->getStatusCode());
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
