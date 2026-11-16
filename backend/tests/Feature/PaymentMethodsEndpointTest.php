<?php

namespace Tests\Feature;

use App\Http\Middleware\BrandContextMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mollie\Api\Fake\MockMollieClient;
use Tests\Support\FakesMollie;
use Tests\TestCase;

/**
 * The cart reads the portal-side Mollie allowlist from one read-only,
 * authenticated endpoint. It never exposes a credential and never calls the
 * provider.
 */
class PaymentMethodsEndpointTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    private MockMollieClient $mollieClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        $this->mollieClient = $this->fakeMollie([]);
        $this->withoutMiddleware(BrandContextMiddleware::class);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/payment-methods')->assertStatus(401);
    }

    public function test_it_returns_the_configured_allowlist_in_order_without_a_provider_call(): void
    {
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertExactJson(['methods' => ['paybybank', 'eps', 'creditcard']]);

        $this->assertCount(0, $this->mollieClient->getHttpClient()->recorded());
    }

    public function test_an_invalid_or_forbidden_entry_returns_an_empty_list(): void
    {
        $this->configureMollie(['paybybank', 'klarna']);
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertExactJson(['methods' => []]);
    }
}
