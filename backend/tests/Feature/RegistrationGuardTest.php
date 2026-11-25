<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Mail\ActivateAccountMail;
use App\Models\User;
use App\Services\RegistrationGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract of features/auth/05-registration-hardening.md (D-92).
 *
 * The guard keeps the 25.11.2026 registration wave in check without changing
 * AUTH-2: an existing address still gets the identical generic success. Every
 * attempt is counted first, then the rejections are decided in the order
 * blocked domain → rate limit → optional MX check.
 */
class RegistrationGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_IP = '127.0.0.1';

    private const PROXY_IP = '172.18.0.3';

    private const FORWARDED_IP = '203.0.113.7';

    private const OTHER_FORWARDED_IP = '198.51.100.2';

    private const USER_AGENT = 'RegistrationGuardTest';

    private const WINDOW_SECONDS = 300;

    private TestDnsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set([
            'registration.blocked_domains' => [],
            'registration.rate_limit.per_ip' => 3,
            'registration.rate_limit.per_domain' => 3,
            'registration.rate_limit.window_seconds' => self::WINDOW_SECONDS,
            'registration.mx_check.enabled' => false,
        ]);

        // The system resolver must never perform DNS in a test. The provider
        // binding is overridden here; no other suite sees this instance.
        $this->resolver = new TestDnsResolver(true);
        $this->app->instance(DnsResolver::class, $this->resolver);

        // Clear every counter this test can address, so the deterministic
        // config above is not influenced by an earlier test or cache store.
        $guard = $this->app->make(RegistrationGuardService::class);
        foreach ([self::CLIENT_IP, self::PROXY_IP, self::FORWARDED_IP, self::OTHER_FORWARDED_IP] as $ip) {
            foreach ([
                'euploading.com', 'example.com', 'registration-guard.test',
                'blocked-guard.test', 'domain-guard.test', 'mx-guard.test',
            ] as $domain) {
                $guard->clear($ip, $domain);
            }
        }
    }

    #[DataProvider('blockedDomainProvider')]
    public function test_blocked_domain_returns_422_without_account_or_mail_and_logs_the_reason(string $domain): void
    {
        config()->set('registration.blocked_domains', ['euploading.com', 'example.com']);
        Log::spy();

        $response = $this->register('Blocked Bot', 'bot@'.$domain);

        $response->assertStatus(422)->assertExactJson([
            'error' => 'Registrierung mit dieser E-Mail-Domain ist nicht möglich.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'bot@'.$domain]);
        Mail::assertNothingQueued();
        $this->assertRejectionLogged('blocked_domain', $domain, self::CLIENT_IP);
    }

    public static function blockedDomainProvider(): array
    {
        return [
            'spam domain' => ['euploading.com'],
            'reserved domain' => ['example.com'],
        ];
    }

    public function test_allowed_domain_creates_the_account_and_queues_the_activation_mail(): void
    {
        config()->set('registration.blocked_domains', ['euploading.com']);

        $response = $this->register('Allowed Person', 'person@registration-guard.test');

        $response->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'person@registration-guard.test']);
        Mail::assertQueued(ActivateAccountMail::class);
    }

    public function test_per_ip_limit_of_three_counts_every_attempt_and_returns_retry_after(): void
    {
        config()->set([
            'registration.rate_limit.per_ip' => 3,
            'registration.rate_limit.per_domain' => 100,
        ]);
        Log::spy();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->register('IP User '.$attempt, 'ip'.$attempt.'@registration-guard.test')->assertOk();
        }

        $response = $this->register('IP User 4', 'ip4@registration-guard.test');

        $response->assertStatus(429)->assertExactJson([
            'error' => 'Zu viele Registrierungsversuche. Bitte versuche es später erneut.',
        ]);
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(self::WINDOW_SECONDS, $retryAfter);
        $this->assertDatabaseMissing('users', ['email' => 'ip4@registration-guard.test']);
        $this->assertRejectionLogged('rate_limit_ip', 'registration-guard.test', self::CLIENT_IP);
    }

    public function test_per_domain_limit_of_three_denies_the_fourth_address(): void
    {
        config()->set([
            'registration.rate_limit.per_ip' => 100,
            'registration.rate_limit.per_domain' => 3,
        ]);
        Log::spy();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->register('Domain User '.$attempt, 'domain'.$attempt.'@domain-guard.test')->assertOk();
        }

        $response = $this->register('Domain User 4', 'domain4@domain-guard.test');

        $response->assertStatus(429)->assertExactJson([
            'error' => 'Zu viele Registrierungsversuche. Bitte versuche es später erneut.',
        ]);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertDatabaseMissing('users', ['email' => 'domain4@domain-guard.test']);
        $this->assertRejectionLogged('rate_limit_domain', 'domain-guard.test', self::CLIENT_IP);
    }

    public function test_blocked_and_existing_addresses_consume_the_same_budget(): void
    {
        config()->set([
            'registration.blocked_domains' => ['blocked-guard.test'],
            'registration.rate_limit.per_ip' => 2,
            'registration.rate_limit.per_domain' => 100,
        ]);
        $existing = User::factory()->create(['email' => 'known@registration-guard.test']);

        $this->register('Blocked', 'blocked@blocked-guard.test')->assertStatus(422);
        // AUTH-2: the taken address is indistinguishable from a fresh one.
        $this->register('Known', $existing->email)->assertOk();
        $this->register('Fresh', 'fresh@registration-guard.test')->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'fresh@registration-guard.test']);
    }

    public function test_forwarded_client_ip_not_the_proxy_is_the_limiter_identity(): void
    {
        config()->set([
            'registration.rate_limit.per_ip' => 1,
            'registration.rate_limit.per_domain' => 100,
        ]);
        Log::spy();

        $this->registerThroughProxy(self::FORWARDED_IP, 'proxy1@registration-guard.test')->assertOk();
        // The rightmost forwarded entry is the one the proxy wrote; a client
        // prefix cannot turn this into a different identity.
        $this->registerThroughProxy('198.51.100.9, '.self::FORWARDED_IP, 'proxy2@registration-guard.test')
            ->assertStatus(429);
        // A different real client behind the SAME proxy gets its own bucket,
        // proving the limiter did not collapse onto 172.18.0.3.
        $this->registerThroughProxy(self::OTHER_FORWARDED_IP, 'proxy3@registration-guard.test')->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'proxy1@registration-guard.test']);
        $this->assertDatabaseHas('users', ['email' => 'proxy3@registration-guard.test']);
        $this->assertDatabaseMissing('users', ['email' => 'proxy2@registration-guard.test']);
        $this->assertRejectionLogged('rate_limit_ip', 'registration-guard.test', self::FORWARDED_IP);
    }

    public function test_mx_check_disabled_does_not_consult_the_resolver(): void
    {
        config()->set('registration.mx_check.enabled', false);
        $this->resolver->result = false;

        $this->register('No DNS', 'mx@mx-guard.test')->assertOk();

        $this->assertSame([], $this->resolver->checked);
        $this->assertDatabaseHas('users', ['email' => 'mx@mx-guard.test']);
    }

    public function test_enabled_mx_check_rejects_a_domain_without_mail_exchange(): void
    {
        config()->set('registration.mx_check.enabled', true);
        $this->resolver->result = false;
        Log::spy();

        $response = $this->register('Undeliverable', 'undeliverable@mx-guard.test');

        $response->assertStatus(422)->assertExactJson([
            'error' => 'Für diese E-Mail-Domain ist keine Zustellung möglich.',
        ]);
        $this->assertSame(['mx-guard.test'], $this->resolver->checked);
        $this->assertDatabaseMissing('users', ['email' => 'undeliverable@mx-guard.test']);
        Mail::assertNothingQueued();
        $this->assertRejectionLogged('mx_missing', 'mx-guard.test', self::CLIENT_IP);
    }

    public function test_enabled_mx_check_allows_a_domain_with_mail_exchange(): void
    {
        config()->set('registration.mx_check.enabled', true);
        $this->resolver->result = true;

        $this->register('Deliverable', 'deliverable@mx-guard.test')->assertOk();

        $this->assertSame(['mx-guard.test'], $this->resolver->checked);
        $this->assertDatabaseHas('users', ['email' => 'deliverable@mx-guard.test']);
    }

    private function register(string $name, string $email): TestResponse
    {
        return $this->withHeaders(['User-Agent' => self::USER_AGENT])
            ->postJson('/api/auth/register', ['name' => $name, 'email' => $email]);
    }

    private function registerThroughProxy(string $forwardedFor, string $email): TestResponse
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => self::PROXY_IP,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ])->withHeaders(['User-Agent' => self::USER_AGENT])
            ->postJson('/api/auth/register', ['name' => 'Proxy User', 'email' => $email]);
    }

    private function assertRejectionLogged(string $reason, string $domain, string $ip): void
    {
        Log::shouldHaveReceived('warning')->with('registration.rejected', Mockery::on(
            function (array $context) use ($reason, $domain, $ip): bool {
                $timestamp = \DateTimeImmutable::createFromFormat(
                    \DateTimeInterface::ATOM,
                    (string) ($context['timestamp'] ?? ''),
                );

                return ($context['reason'] ?? null) === $reason
                    && ($context['domain'] ?? null) === $domain
                    && ($context['ip'] ?? null) === $ip
                    && ($context['user_agent'] ?? null) === self::USER_AGENT
                    && $timestamp !== false
                    && $timestamp->format(\DateTimeInterface::ATOM) === $context['timestamp']
                    && ! array_key_exists('email', $context)
                    && ! str_contains((string) json_encode($context), '@');
            }
        ))->once();
    }
}

final class TestDnsResolver implements DnsResolver
{
    /** @var list<string> */
    public array $checked = [];

    public function __construct(public bool $result) {}

    public function hasMailExchange(string $domain): bool
    {
        $this->checked[] = $domain;

        return $this->result;
    }
}
