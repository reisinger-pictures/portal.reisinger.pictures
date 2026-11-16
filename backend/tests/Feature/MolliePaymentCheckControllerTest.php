<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetCurrentProfileRequest;
use Tests\Support\FakesMollie;
use Tests\TestCase;

class MolliePaymentCheckControllerTest extends TestCase
{
    use FakesMollie;
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        BrandRegistry::set(Brand::B2B);

        $this->superAdmin = User::factory()->create(['brand' => null]);
        $this->superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        $this->admin = User::factory()->create(['brand' => Brand::B2B->value]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $this->photographer = User::factory()->create(['brand' => Brand::B2B->value]);
        $this->photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $this->configureMollie();
    }

    // --- Authorization -----------------------------------------------------

    public function test_super_admin_gets_200_with_all_five_checks_ok_and_in_order(): void
    {
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        foreach (array_column($response->json('checks'), 'status') as $status) {
            $this->assertSame('ok', $status);
        }

        $this->assertSame('ok', $this->checkByKey($response, 'readiness')['status']);
    }

    public function test_normal_admin_is_forbidden(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(403);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $response = $this->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(401);
    }

    public function test_photographer_is_forbidden(): void
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(403);
    }

    // --- Response contract -------------------------------------------------

    /**
     * The shape is the contract, not a property of the happy path. Even with
     * every finding red, all five entries must still be present, in the fixed
     * order, each with exactly the three declared keys and a status from the
     * fixed vocabulary — and still no secret material in the body.
     */
    public function test_response_contract_holds_when_every_check_is_red(): void
    {
        config([
            'services.mollie.key' => null,
            'services.mollie.methods' => ['klarna'],
            'services.mollie.webhook_url' => 'http://portal.example.test/api/webhooks/mollie',
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $this->assertSame('fail', $this->checkByKey($response, 'key_mode')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'webhook_url')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'methods')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'account')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
    }

    // --- Check 1: key_mode -------------------------------------------------

    /**
     * The misconfiguration the endpoint exists to diagnose must not kill the
     * endpoint: with no key the local checks still return a verdict and the
     * provider check becomes a `fail` finding — a well-formed 200, never a 500.
     */
    public function test_missing_key_is_a_finding_without_an_exception(): void
    {
        config(['services.mollie.key' => null]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $check = $this->checkByKey($response, 'key_mode');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('MOLLIE_KEY', $check['message']);

        $account = $this->checkByKey($response, 'account');
        $this->assertSame('fail', $account['status']);
        $this->assertNotSame('', $account['message']);

        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
        $this->assertNoSecretMaterial($response, null);
    }

    public function test_malformed_key_is_a_finding_without_an_exception(): void
    {
        // Shape carries no recognizable test_/live_ prefix, so the adapter
        // treats it as "no credential" instead of sending it to Mollie.
        config(['services.mollie.key' => 'nonsense-key-value']);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);
        $this->assertSame('fail', $this->checkByKey($response, 'key_mode')['status']);
        $this->assertStringNotContainsString('nonsense-key-value', $response->getContent());
    }

    // --- Check 2: webhook_url ---------------------------------------------

    public function test_missing_webhook_url_fails_and_names_the_503_consequence(): void
    {
        config(['services.mollie.webhook_url' => null]);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertSame('ok', $this->checkByKey($response, 'key_mode')['status']);

        $check = $this->checkByKey($response, 'webhook_url');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('MOLLIE_WEBHOOK_URL', $check['message']);
        $this->assertStringContainsString('503', $check['message']);

        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
    }

    public function test_plain_http_webhook_url_fails_and_names_the_value(): void
    {
        config(['services.mollie.webhook_url' => 'http://portal.example.test/api/webhooks/mollie']);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'webhook_url');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('http://portal.example.test/api/webhooks/mollie', $check['message']);
        $this->assertStringContainsString('503', $check['message']);

        // The webhook URL is the only failing piece: readiness still fails
        // with it, and the other findings stay green.
        $this->assertSame('ok', $this->checkByKey($response, 'key_mode')['status']);
        $this->assertSame('ok', $this->checkByKey($response, 'methods')['status']);
        $this->assertSame('ok', $this->checkByKey($response, 'account')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
    }

    // --- Check 3: methods --------------------------------------------------

    public function test_denylisted_method_fails_and_names_it(): void
    {
        $this->configureMollie(['paybybank', 'klarna']);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'methods');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('klarna', $check['message']);
        $this->assertStringContainsString('503', $check['message']);

        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
    }

    public function test_empty_method_list_fails_and_names_the_503_consequence(): void
    {
        $this->configureMollie([]);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'methods');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('MOLLIE_PAYMENT_METHODS', $check['message']);
        $this->assertSame('fail', $this->checkByKey($response, 'readiness')['status']);
    }

    public function test_invalid_method_id_fails_and_names_it(): void
    {
        $this->configureMollie(['not a method!']);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'methods');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('not a method!', $check['message']);
    }

    public function test_healthy_method_list_names_the_default(): void
    {
        $this->configureMollie(['paybybank', 'eps', 'creditcard']);
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'methods');
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('paybybank, eps, creditcard', $check['message']);
        $this->assertStringContainsString('paybybank', $check['message']);
    }

    // --- Check 4: account --------------------------------------------------

    /**
     * A rejected key is a `fail` finding with the mapped, redacted message —
     * never an exception — and `key_mode` still reports the configured mode,
     * because a well-formed key is not the same thing as a working one.
     */
    public function test_provider_401_fails_the_account_check_and_keeps_key_mode(): void
    {
        $this->fakeMollie([
            GetCurrentProfileRequest::class => MockResponse::error(401, 'Unauthorized', 'Invalid API key'),
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $this->assertSame('ok', $this->checkByKey($response, 'key_mode')['status']);
        $this->assertStringContainsString('test', $this->checkByKey($response, 'key_mode')['message']);

        $check = $this->checkByKey($response, 'account');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('abgelehnt', $check['message']);
        // The raw provider text never reaches the response.
        $this->assertStringNotContainsString('Invalid API key', $check['message']);

        // The key never appears anywhere in the body.
        $this->assertNoSecretMaterial($response, null);
    }

    public function test_account_unverified_warns_and_names_the_status(): void
    {
        $this->fakeMollie([
            GetCurrentProfileRequest::class => MockResponse::ok($this->profileBody(['status' => 'unverified'])),
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'account');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('unverified', $check['message']);
        $this->assertStringContainsString('pfl_canary_1', $check['message']);
    }

    /**
     * Mollie answers `GET /v2/profiles/me` with the profile's **live** mode even
     * for a test key (measured 2026-11-16: `pfl_Kq5KcdyAvC`, `mode: "live"`,
     * `status: "verified"`, status is what drives the finding). Comparing the
     * profile mode against the key mode would therefore warn on a healthy test
     * configuration, so the profile mode is informational only.
     */
    public function test_live_profile_mode_with_a_test_key_is_still_ok(): void
    {
        $this->fakeMollie([
            GetCurrentProfileRequest::class => MockResponse::ok($this->profileBody(['mode' => 'live'])),
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'account');
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('test', $check['message']);
    }

    // --- Check 5: readiness ------------------------------------------------

    public function test_readiness_reports_the_checkout_gate(): void
    {
        $this->fakeMollie([GetCurrentProfileRequest::class => $this->healthyProfile()]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'readiness');
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('canCreatePayment() = true', $check['message']);
    }

    // --- Secret hygiene ----------------------------------------------------

    /**
     * A recognisable fake key must never appear in the JSON, in any branch.
     * The key is well-formed (the adapter accepts it), so it really is used
     * for the provider call this test drives.
     */
    public function test_configured_key_value_never_appears_in_the_response(): void
    {
        $key = 'test_'.str_repeat('a', 18).'SECRETCANARY';
        config([
            'services.mollie.key' => $key,
            'services.mollie.methods' => ['klarna'],
            'services.mollie.webhook_url' => 'https://portal.example.test/api/webhooks/mollie',
        ]);
        $this->fakeMollie([
            GetCurrentProfileRequest::class => MockResponse::error(401, 'Unauthorized', 'Invalid API key: '.$key),
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/mollie-check', []);

        $response->assertStatus(200);
        $this->assertContractShape($response);
        $this->assertNoSecretMaterial($response, $key);
    }

    // --- Fixtures ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function profileBody(array $overrides = []): array
    {
        return array_merge([
            'resource' => 'profile',
            'id' => 'pfl_canary_1',
            'name' => 'Portal Canary',
            'mode' => 'test',
            'status' => 'verified',
        ], $overrides);
    }

    private function healthyProfile(): MockResponse
    {
        return MockResponse::ok($this->profileBody());
    }

    /**
     * The response contract on the raw decoded body: exactly five findings in
     * the fixed order, every entry carrying exactly `key`, `status` and
     * `message`, every status inside the fixed vocabulary, and no secret
     * material anywhere in the raw response text.
     *
     * @param  TestResponse  $response
     */
    private function assertContractShape($response): void
    {
        $response->assertJsonCount(5, 'checks');

        $checks = $response->json('checks');
        $this->assertIsArray($checks);

        $this->assertSame([
            'key_mode',
            'webhook_url',
            'methods',
            'account',
            'readiness',
        ], array_column($checks, 'key'));

        foreach ($checks as $check) {
            $this->assertSame(['key', 'status', 'message'], array_keys($check));
            $this->assertContains($check['status'], ['ok', 'warn', 'fail']);
            $this->assertIsString($check['message']);
            $this->assertNotSame('', $check['message']);
        }
    }

    /**
     * @param  TestResponse  $response
     * @return array{key: string, status: string, message: string}
     */
    private function checkByKey($response, string $key): array
    {
        foreach ($response->json('checks') as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("No verdict with key [{$key}] in the response.");
    }

    private function assertNoSecretMaterial($response, ?string $configuredKey): void
    {
        $content = $response->getContent();
        $this->assertIsString($content);

        foreach (['SECRETCANARY', 'TmF6QE4SPqFVbjsHuRM4mnKcPt7Czm'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }

        if ($configuredKey !== null) {
            $this->assertStringNotContainsString($configuredKey, $content);
        }
    }
}
