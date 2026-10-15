<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\MocksStripeClient;
use Tests\TestCase;

class StripeKeyCheckControllerTest extends TestCase
{
    use MocksStripeClient;
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

        $this->applyHealthyStripeConfig();
    }

    protected function tearDown(): void
    {
        $this->resetStripeHttpClient();

        parent::tearDown();
    }

    // --- Authorization -----------------------------------------------------

    public function test_super_admin_gets_200_with_all_five_checks_ok_and_in_order(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        foreach (array_column($response->json('checks'), 'status') as $status) {
            $this->assertSame('ok', $status);
        }
    }

    public function test_normal_admin_is_forbidden(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(403);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $response = $this->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(401);
    }

    public function test_photographer_is_forbidden(): void
    {
        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

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
            'services.stripe.key' => 'pk_test_FRONTCANARY',
            'services.stripe.secret' => 'sk_test_SECRETCANARY',
            'app.env' => 'production', // Soll-Modus live, die konfigurierten Keys sind test
        ]);
        $this->mockStripeApi($this->healthyStripeResponder([
            'charges_enabled' => false,
            'endpoints' => [],
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'live']);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $this->assertSame('fail', $this->checkByKey($response, 'local_key_mode')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'frontend_bundle_mode')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'stripe_balance')['status']);
        $this->assertSame('warn', $this->checkByKey($response, 'stripe_account')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'stripe_webhook_endpoint')['status']);
    }

    // --- Check 1: local_key_mode ------------------------------------------

    public function test_mixed_key_pair_flags_local_key_mode_as_fail(): void
    {
        config([
            'services.stripe.key' => 'pk_test_FRONTCANARY',
            'services.stripe.secret' => 'sk_live_SECRETCANARY',
            'app.env' => 'production',
        ]);
        $this->mockStripeApi($this->healthyStripeResponder([
            'livemode' => true,
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'live']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'local_key_mode');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('unterschiedliche Modi', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_missing_webhook_secret_flags_local_key_mode_as_fail(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $this->mockStripeApi($this->healthyStripeResponder([]));

        // "Missing" means neither the env var nor the local tunnel artifact.
        // The artifact is machine-local, so its absence must be enforced.
        $this->withoutTunnelArtifact(function (): void {
            $response = $this->actingAs($this->superAdmin, 'api')
                ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

            $response->assertStatus(200);
            // Never echo the webhook secret value here — only name its absence.
            $this->assertStringNotContainsString('whsec_', $response->getContent());
            $this->assertStringNotContainsString('WHSECCANARY', $response->getContent());

            $check = $this->checkByKey($response, 'local_key_mode');
            $this->assertSame('fail', $check['status']);
            $this->assertStringContainsString('WEBHOOK_SECRET', $check['message']);
            $this->assertStringContainsString('nicht gesetzt', $check['message']);
            $this->assertNoSecretMaterial($response);
        });
    }

    /**
     * Check 1 honours the same local tunnel fallback the webhook ingress uses
     * (both go through `App\Support\StripeWebhookSecret`): with the env var
     * empty and the artifact present in `testing`, the secret counts as set and
     * the message names the source instead of claiming it is missing.
     */
    public function test_tunnel_artifact_satisfies_local_key_mode_and_names_the_source(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $this->mockStripeApi($this->healthyStripeResponder([]));

        $this->withTunnelArtifact('whsec_tunnel_canary', function (): void {
            $response = $this->actingAs($this->superAdmin, 'api')
                ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

            $response->assertStatus(200);
            $check = $this->checkByKey($response, 'local_key_mode');
            $this->assertSame('ok', $check['status']);
            $this->assertStringContainsString('Tunnel-Artefakt', $check['message']);
            // The source is named, the value never is.
            $this->assertStringNotContainsString('whsec', $response->getContent());
            $this->assertNoSecretMaterial($response);
        });
    }

    /**
     * The misconfiguration the endpoint exists to diagnose must not kill the
     * endpoint: with no buildable Stripe client (empty secret key) the two
     * local checks still return a verdict, the three network checks become
     * `fail` findings, and the response stays a well-formed 200 with five
     * entries — never a 500 from the constructor.
     */
    public function test_unbuildable_stripe_client_becomes_findings_not_a_500(): void
    {
        config([
            'services.stripe.key' => '',
            'services.stripe.secret' => '',
            'services.stripe.webhook_secret' => '',
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        // Checks 1 and 2 are purely local and must still yield a result.
        $this->assertSame('fail', $this->checkByKey($response, 'local_key_mode')['status']);
        $this->assertSame('fail', $this->checkByKey($response, 'frontend_bundle_mode')['status']);

        foreach (['stripe_balance', 'stripe_account', 'stripe_webhook_endpoint'] as $key) {
            $check = $this->checkByKey($response, $key);
            $this->assertSame('fail', $check['status']);
            $this->assertNotSame('', $check['message']);
        }
    }

    // --- Check 2: frontend_bundle_mode ------------------------------------

    public function test_bundle_mode_mismatch_flags_frontend_bundle_mode_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'live']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'frontend_bundle_mode');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('live', $check['message']);
        $this->assertStringContainsString('test', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_missing_bundle_mode_is_a_finding_not_a_422(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', []);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'frontend_bundle_mode');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('VITE_STRIPE_PUBLIC_KEY', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_explicit_null_bundle_mode_is_a_finding_not_a_422(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => null]);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'frontend_bundle_mode');
        $this->assertSame('fail', $check['status']);
        $this->assertNoSecretMaterial($response);
    }

    // --- Check 3: stripe_balance ------------------------------------------

    public function test_expired_key_is_reported_verbatim_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['balance_error' => 'api_key_expired']));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_balance');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('api_key_expired', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_permission_error_code_is_passed_through(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['balance_error' => 'more_permissions_required']));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_balance');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('more_permissions_required', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    /**
     * Defense-in-depth: an error message that itself embeds key material must
     * not carry it into the JSON. The machine-readable code survives, the key
     * does not (see `StripeKeyCheckService::redactSecrets()`).
     */
    public function test_key_material_inside_a_stripe_error_is_redacted(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([
            'balance_error' => 'api_key_expired',
            'balance_error_message' => 'Invalid API Key provided: sk_test_SECRETCANARY',
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_balance');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('api_key_expired', $check['message']);
        $this->assertStringNotContainsString('sk_test_', $check['message']);
        $this->assertStringNotContainsString('SECRETCANARY', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    /**
     * Defense-in-depth has no word-boundary anchor: a key glued directly to a
     * preceding word character must be redacted too. Over-redaction is the
     * safe direction; leaving a value through is not.
     */
    public function test_key_material_glued_to_a_word_character_is_redacted(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([
            'balance_error' => 'api_key_expired',
            'balance_error_message' => 'Request failed for key=abcsk_test_SECRETCANARY&mode=test',
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_balance');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('api_key_expired', $check['message']);
        $this->assertStringNotContainsString('sk_test_', $check['message']);
        $this->assertStringNotContainsString('SECRETCANARY', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_balance_mode_mismatch_flags_as_fail(): void
    {
        // Soll-Modus ist 'test' (APP_ENV=testing), die Balance meldet aber live.
        $this->mockStripeApi($this->healthyStripeResponder(['livemode' => true]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_balance');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('live', $check['message']);
        $this->assertStringContainsString('test', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    // --- Check 4: stripe_account ------------------------------------------

    public function test_account_without_charges_enabled_warns_and_names_flag(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['charges_enabled' => false]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_account');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('charges_enabled', $check['message']);
        $this->assertStringContainsString('acct_', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    /**
     * A Stripe error on the account path becomes a `fail` finding (contract,
     * not a 500), carries the machine-readable code, and is redacted like the
     * balance path — key material inside the Stripe message must not survive.
     */
    public function test_account_retrieval_error_is_reported_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([
            'account_error' => 'more_permissions_required',
            'account_error_message' => 'The key sk_test_SECRETCANARY cannot read this account.',
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $check = $this->checkByKey($response, 'stripe_account');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('more_permissions_required', $check['message']);
        $this->assertStringNotContainsString('sk_test_', $check['message']);
        $this->assertStringNotContainsString('SECRETCANARY', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    // --- Check 5: stripe_webhook_endpoint --------------------------------

    public function test_missing_webhook_endpoint_flags_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['endpoints' => []]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_webhook_endpoint');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Kein Webhook-Endpunkt', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_disabled_webhook_endpoint_flags_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['status' => 'disabled']));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_webhook_endpoint');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('deaktiviert', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    public function test_incomplete_webhook_events_warn_and_name_missing_event(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder(['events' => [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'charge.dispute.created',
        ]]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $check = $this->checkByKey($response, 'stripe_webhook_endpoint');
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('charge.refunded', $check['message']);
        $this->assertStringContainsString('we_', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    /**
     * A Stripe error on the webhook-endpoint path becomes a `fail` finding
     * (contract, not a 500), carries the machine-readable code, and is
     * redacted like the balance path.
     */
    public function test_webhook_endpoint_retrieval_error_is_reported_as_fail(): void
    {
        $this->mockStripeApi($this->healthyStripeResponder([
            'endpoints_error' => 'more_permissions_required',
            'endpoints_error_message' => 'The key sk_test_SECRETCANARY cannot list webhook endpoints.',
        ]));

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/settings/stripe-check', ['bundle_mode' => 'test']);

        $response->assertStatus(200);
        $this->assertContractShape($response);

        $check = $this->checkByKey($response, 'stripe_webhook_endpoint');
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('more_permissions_required', $check['message']);
        $this->assertStringNotContainsString('sk_test_', $check['message']);
        $this->assertStringNotContainsString('SECRETCANARY', $check['message']);
        $this->assertNoSecretMaterial($response);
    }

    // --- Fixtures ---------------------------------------------------------

    /**
     * Run the callback with the local tunnel artifact removed, so a check that
     * means "no secret configured" does not depend on the developer machine's
     * leftover tunnel file. The original file is restored afterwards.
     *
     * @param  callable(): void  $callback
     */
    private function withoutTunnelArtifact(callable $callback): void
    {
        $this->withTunnelArtifact(null, $callback);
    }

    /**
     * Run the callback with the local tunnel artifact set to `$contents` (or
     * removed when null), restoring any pre-existing file afterwards. Mirrors
     * `WebhookSecretFallbackTest::withSecretFile()`.
     *
     * @param  callable(): void  $callback
     */
    private function withTunnelArtifact(?string $contents, callable $callback): void
    {
        $secretFile = storage_path('app/private/stripe_secret.txt');
        $existed = file_exists($secretFile);
        $original = $existed ? (string) file_get_contents($secretFile) : null;

        if (! is_dir(dirname($secretFile))) {
            mkdir(dirname($secretFile), 0755, true);
        }

        if ($contents === null) {
            @unlink($secretFile);
        } else {
            file_put_contents($secretFile, $contents);
        }

        try {
            $callback();
        } finally {
            if ($existed) {
                file_put_contents($secretFile, (string) $original);
            } else {
                @unlink($secretFile);
            }
        }
    }

    private function applyHealthyStripeConfig(): void
    {
        config([
            'services.stripe.key' => 'pk_test_FRONTCANARY',
            'services.stripe.secret' => 'sk_test_SECRETCANARY',
            'services.stripe.webhook_secret' => 'whsec_WHSECCANARY',
            'app.env' => 'testing',
            'app.frontend_url' => 'https://portal.test',
        ]);
    }

    /**
     * A URL-routing responder that produces a fully healthy Stripe surface by
     * default. Override keys: `livemode`, `charges_enabled`, `status`, `events`,
     * `balance_error`, `balance_error_message`, `account_error`,
     * `account_error_message`, `endpoints_error`, `endpoints_error_message`,
     * `endpoints`.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function healthyStripeResponder(array $overrides): callable
    {
        return function (string $absUrl) use ($overrides): array {
            if (str_contains($absUrl, '/v1/webhook_endpoints')) {
                if (isset($overrides['endpoints_error'])) {
                    return $this->stripeErrorResponse(
                        $overrides['endpoints_error'],
                        $overrides['endpoints_error_message'] ?? null,
                    );
                }

                return $this->stripeJson([
                    'object' => 'list',
                    'data' => $overrides['endpoints'] ?? [$this->healthyEndpoint($overrides)],
                ]);
            }

            if (str_ends_with($absUrl, '/v1/account')) {
                if (isset($overrides['account_error'])) {
                    return $this->stripeErrorResponse(
                        $overrides['account_error'],
                        $overrides['account_error_message'] ?? null,
                    );
                }

                return $this->stripeJson([
                    'id' => 'acct_canary_1',
                    'object' => 'account',
                    'charges_enabled' => $overrides['charges_enabled'] ?? true,
                    'payouts_enabled' => true,
                    'details_submitted' => true,
                ]);
            }

            if (str_contains($absUrl, '/v1/balance')) {
                if (isset($overrides['balance_error'])) {
                    return $this->stripeErrorResponse(
                        $overrides['balance_error'],
                        $overrides['balance_error_message'] ?? null,
                    );
                }

                return $this->stripeJson([
                    'object' => 'balance',
                    'livemode' => $overrides['livemode'] ?? false,
                ]);
            }

            return $this->stripeJson(['object' => 'unknown']);
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function healthyEndpoint(array $overrides): array
    {
        return [
            'id' => 'we_canary_1',
            'object' => 'webhook_endpoint',
            'url' => 'https://portal.test/api/webhooks/stripe',
            'status' => $overrides['status'] ?? 'enabled',
            'enabled_events' => $overrides['events'] ?? [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.dispute.created',
                'charge.refunded',
            ],
        ];
    }

    private function stripeJson(array $body): array
    {
        return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
    }

    private function stripeErrorResponse(string $code, ?string $message = null): array
    {
        $messages = [
            'api_key_expired' => 'Expired API Key provided.',
            'more_permissions_required' => 'The provided key does not have the required permission.',
        ];

        return [
            json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message ?? $messages[$code] ?? 'Stripe error.',
                    'type' => 'invalid_request_error',
                ],
            ], JSON_THROW_ON_ERROR),
            401,
            [],
        ];
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
            'local_key_mode',
            'frontend_bundle_mode',
            'stripe_balance',
            'stripe_account',
            'stripe_webhook_endpoint',
        ], array_column($checks, 'key'));

        foreach ($checks as $check) {
            $this->assertSame(['key', 'status', 'message'], array_keys($check));
            $this->assertContains($check['status'], ['ok', 'warn', 'fail']);
            $this->assertIsString($check['message']);
            $this->assertNotSame('', $check['message']);
        }

        $this->assertNoSecretMaterial($response);
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

    private function assertNoSecretMaterial($response): void
    {
        $content = $response->getContent();
        $this->assertIsString($content);

        foreach (['sk_test_', 'sk_live_', 'pk_test_', 'pk_live_', 'whsec_', 'SECRETCANARY', 'WHSECCANARY'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
    }
}
