<?php

namespace Tests\Feature;

use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutRiskService;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutRiskTurnstileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        config([
            'services.turnstile.site_key' => null,
            'services.turnstile.secret' => null,
            'services.turnstile.allowed_hostnames' => '',
            'app.turnstile_user_threshold_per_hour' => 3,
            'app.turnstile_ip_threshold_per_hour' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Http::preventStrayRequests(false);
        Cache::flush();
        parent::tearDown();
    }

    public function test_turnstile_is_inactive_when_either_credential_is_missing(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest();

        foreach ([[null, 'server-secret'], ['', 'server-secret'], ['site-key', null], ['site-key', '']] as [$siteKey, $secret]) {
            config([
                'services.turnstile.site_key' => $siteKey,
                'services.turnstile.secret' => $secret,
            ]);
            Cache::flush();
            Http::fake();

            app(CheckoutRiskService::class)->assertImmediateStripeAllowed($request, $user);
            Http::assertNothingSent();
        }

        Http::assertNothingSent();
    }

    public function test_calls_below_thresholds_do_not_require_turnstile(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 3,
            'app.turnstile_ip_threshold_per_hour' => 5,
        ]);
        $user = User::factory()->create();
        $service = app(CheckoutRiskService::class);
        Http::fake();

        $service->assertImmediateStripeAllowed($this->makeRequest(), $user);
        $service->assertImmediateStripeAllowed($this->makeRequest(), $user);

        Http::assertNothingSent();
    }

    public function test_missing_token_returns_403_with_turnstile_required(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();

        $this->assertDenied(
            fn () => app(CheckoutRiskService::class)->assertImmediateStripeAllowed($this->makeRequest(), $user),
            403,
            ['turnstile_required' => true]
        );
    }

    public function test_valid_token_is_verified_with_server_side_context(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();
        $token = 'valid-turnstile-token';
        Http::fake([
            '*siteverify' => Http::response($this->validVerification($user)),
        ]);

        app(CheckoutRiskService::class)->assertImmediateStripeAllowed(
            $this->makeRequest($token, '198.51.100.20'),
            $user
        );

        Http::assertSent(function (ClientRequest $request) use ($token): bool {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request->isForm()
                && $request->data() === [
                    'secret' => 'server-secret',
                    'response' => $token,
                    'remoteip' => '198.51.100.20',
                ];
        });
    }

    #[DataProvider('invalidVerificationResponses')]
    public function test_wrong_action_cdata_or_hostname_is_rejected(array $overrides): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();
        Http::fake([
            '*siteverify' => Http::response($this->validVerification($user, $overrides)),
        ]);
        Log::spy();

        $this->assertDenied(
            fn () => app(CheckoutRiskService::class)->assertImmediateStripeAllowed(
                $this->makeRequest('invalid-token'),
                $user
            ),
            403,
            ['turnstile_required' => true]
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidVerificationResponses(): array
    {
        return [
            'wrong action' => [['action' => 'login']],
            'wrong cdata' => [['cdata' => 'checkout-someone-else']],
            'wrong hostname' => [['hostname' => 'evil.example.test']],
        ];
    }

    public function test_unsuccessful_siteverify_response_is_rejected_without_logging(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();
        Http::fake([
            '*siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);
        Log::spy();

        $this->assertDenied(
            fn () => app(CheckoutRiskService::class)->assertImmediateStripeAllowed(
                $this->makeRequest('invalid-token'),
                $user
            ),
            403
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_siteverify_transport_failure_fails_closed_with_503(): void
    {
        $this->enableTurnstile();
        $user = User::factory()->create();
        Http::fake([
            '*siteverify' => Http::failedConnection(),
        ]);

        $this->assertUnavailable(
            fn () => app(CheckoutRiskService::class)->assertImmediateStripeAllowed(
                $this->makeRequest('token'),
                $user
            )
        );
    }

    public function test_user_threshold_counts_the_current_attempt(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 3,
            'app.turnstile_ip_threshold_per_hour' => 100,
        ]);
        $user = User::factory()->create();
        $service = app(CheckoutRiskService::class);
        Http::fake();

        $service->assertImmediateStripeAllowed($this->makeRequest(), $user);
        $service->assertImmediateStripeAllowed($this->makeRequest(), $user);

        $this->assertDenied(
            fn () => $service->assertImmediateStripeAllowed($this->makeRequest(), $user),
            403
        );
    }

    public function test_ip_threshold_is_independent_from_user_threshold(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 100,
            'app.turnstile_ip_threshold_per_hour' => 3,
        ]);
        $service = app(CheckoutRiskService::class);
        Http::fake();

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $thirdUser = User::factory()->create();
        $ip = '198.51.100.30';

        $service->assertImmediateStripeAllowed($this->makeRequest(null, $ip), $firstUser);
        $service->assertImmediateStripeAllowed($this->makeRequest(null, $ip), $secondUser);

        $this->assertDenied(
            fn () => $service->assertImmediateStripeAllowed($this->makeRequest(null, $ip), $thirdUser),
            403
        );
    }

    public function test_verified_failure_velocity_requires_turnstile_even_below_attempt_thresholds(): void
    {
        $this->enableTurnstile();
        config([
            'app.turnstile_user_threshold_per_hour' => 100,
            'app.turnstile_ip_threshold_per_hour' => 100,
            'app.turnstile_failure_user_threshold_per_hour' => 2,
            'app.turnstile_failure_ip_threshold_per_hour' => 100,
        ]);
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'ip_address' => '198.51.100.31',
        ]);
        $service = app(CheckoutRiskService::class);
        $request = $this->makeRequest(null, '198.51.100.31');
        Http::fake();

        $service->recordVerifiedPaymentFailure($order);
        $service->recordVerifiedPaymentFailure($order);

        $this->assertDenied(
            fn () => $service->assertImmediateStripeAllowed($this->makeRequest(null, '198.51.100.31'), $user),
            403,
            ['turnstile_required' => true]
        );
        Http::assertNothingSent();
    }

    public function test_frontend_url_host_is_the_safe_fallback_when_no_hostname_is_configured(): void
    {
        $this->enableTurnstile();
        config([
            'services.turnstile.allowed_hostnames' => '',
            'app.frontend_url' => 'https://frontend.example.test',
        ]);
        $user = User::factory()->create();
        Http::fake([
            '*siteverify' => Http::response($this->validVerification($user, [
                'hostname' => 'frontend.example.test',
            ])),
        ]);

        app(CheckoutRiskService::class)->assertImmediateStripeAllowed(
            $this->makeRequest('token'),
            $user
        );

        Http::assertSentCount(1);
    }

    public function test_turnstile_token_is_limited_to_2048_characters(): void
    {
        $rule = (new CheckoutRequest)->rules()['turnstile_token'];
        $validator = Validator::make([
            'turnstile_token' => str_repeat('x', 2049),
        ], ['turnstile_token' => $rule]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('turnstile_token', $validator->errors()->toArray());

        $validValidator = Validator::make([
            'turnstile_token' => str_repeat('x', 2048),
        ], ['turnstile_token' => $rule]);
        $this->assertFalse($validValidator->fails());
    }

    public function test_cdata_is_bounded_and_keeps_short_ids_literal(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $cdata = TurnstileService::cDataForUserId($uuid);

        $this->assertSame('checkout-'.substr($uuid, 0, 23), $cdata);
        $this->assertSame(32, strlen($cdata));
        $this->assertSame($cdata, TurnstileService::cDataForUserId($uuid));
        $this->assertSame('checkout-short-user', TurnstileService::cDataForUserId('short-user'));
    }

    public function test_official_dummy_response_bypass_requires_explicit_test_opt_in(): void
    {
        $user = User::factory()->create();
        config([
            'services.turnstile.site_key' => '1x00000000000000000000AA',
            'services.turnstile.secret' => '1x0000000000000000000000000000000AA',
            'services.turnstile.allow_dummy_test_keys' => true,
            'services.turnstile.allowed_hostnames' => 'checkout.example.test',
        ]);
        Http::fake([
            '*siteverify' => Http::response(['success' => true]),
        ]);

        app(TurnstileService::class)->assertValid('official-dummy-token', $user);

        config(['services.turnstile.allow_dummy_test_keys' => false]);
        $this->assertDenied(
            fn () => app(TurnstileService::class)->assertValid('official-dummy-token', $user),
            403
        );
    }

    private function enableTurnstile(): void
    {
        config([
            'services.turnstile.site_key' => 'site-key',
            'services.turnstile.secret' => 'server-secret',
            'services.turnstile.allowed_hostnames' => 'checkout.example.test',
            'app.turnstile_user_threshold_per_hour' => 1,
            'app.turnstile_ip_threshold_per_hour' => 1,
        ]);
    }

    private function makeRequest(?string $token = null, string $ip = '198.51.100.10'): Request
    {
        return Request::create(
            '/api/orders/checkout',
            'POST',
            $token === null ? [] : ['turnstile_token' => $token],
            [],
            [],
            ['REMOTE_ADDR' => $ip]
        );
    }

    private function validVerification(User $user, array $overrides = []): array
    {
        return array_replace([
            'success' => true,
            'action' => 'checkout',
            'cdata' => TurnstileService::cDataForUserId((string) $user->getAuthIdentifier()),
            'hostname' => 'checkout.example.test',
        ], $overrides);
    }

    private function assertDenied(callable $callback, int $status, array $json = []): void
    {
        try {
            $callback();
            $this->fail('Expected checkout risk rejection was not thrown.');
        } catch (HttpResponseException $exception) {
            $this->assertSame($status, $exception->getResponse()->getStatusCode());
            foreach ($json as $key => $value) {
                $this->assertSame($value, $exception->getResponse()->getData(true)[$key] ?? null);
            }
        }
    }

    private function assertUnavailable(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a fail-closed response was not thrown.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(503, $exception->getResponse()->getStatusCode());
        }
    }
}
