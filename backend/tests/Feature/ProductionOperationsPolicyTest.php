<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\InvoiceMailDispatcher;
use App\Support\ProductionOperationsPolicy;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionOperationsPolicyTest extends TestCase
{
    public function test_valid_production_topology_passes_the_policy(): void
    {
        $violations = $this->policy()->violations(
            $this->validConfig(),
            60,
            5,
            true,
        );

        $this->assertSame([], $violations);
    }

    public function test_laravel13_symfony_transport_uses_scheme_and_requires_tls(): void
    {
        $originalMailer = config('mail.mailers.smtp');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'require_tls' => true,
                'host' => 'smtp.example.com',
                'port' => 587,
                'username' => 'mailer',
                'password' => 'test-secret',
            ],
        ]);

        try {
            $manager = app('mail.manager');
            $manager->forgetMailers();
            $transport = $manager->mailer('smtp')->getSymfonyTransport();

            $this->assertInstanceOf(EsmtpTransport::class, $transport);
            $this->assertTrue($transport->isAutoTls());
            $this->assertTrue($transport->isTlsRequired());
        } finally {
            config(['mail.mailers.smtp' => $originalMailer]);
            app('mail.manager')->forgetMailers();
        }
    }

    public function test_queue_connection_and_timeout_invariants_fail_closed(): void
    {
        $config = $this->validConfig();
        $config['queue']['connections']['database']['connection'] = 'other';
        $config['queue']['connections']['database']['retry_after'] = 60;

        $violations = $this->policy()->violations($config, 60, 5, true);

        $this->assertContains(
            'DB_QUEUE_CONNECTION must equal DB_CONNECTION for transactional queue writes.',
            $violations,
        );
        $this->assertContains(
            'QUEUE_WORKER_TIMEOUT must be less than DB_QUEUE_RETRY_AFTER.',
            $violations,
        );
    }

    public function test_invoice_dispatch_rejects_an_unresolved_production_queue_connection(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
            'database.default' => 'mariadb',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invoice mail requires the transactional database queue in production.');

            app(InvoiceMailDispatcher::class)->queueOnce(new Order);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_missing_production_smtp_and_sender_values_fail_closed(): void
    {
        $config = $this->validConfig();
        $config['mail']['default'] = 'log';
        $config['mail']['mailers']['smtp']['scheme'] = 'tls';
        $config['mail']['mailers']['smtp']['require_tls'] = false;
        $config['mail']['mailers']['smtp']['username'] = '';
        $config['mail']['mailers']['smtp']['password'] = '';
        $config['mail']['from']['address'] = 'HELLO@EXAMPLE.COM';
        $config['mail']['from']['name'] = '';

        $violations = $this->policy()->violations($config, 60, 5, true);

        $this->assertContains('MAIL_MAILER must be smtp in production.', $violations);
        $this->assertContains('MAIL_SCHEME must be smtp or smtps in production.', $violations);
        $this->assertContains('MAIL_REQUIRE_TLS must be true in production.', $violations);
        $this->assertContains('MAIL_USERNAME must be configured for production SMTP.', $violations);
        $this->assertContains('MAIL_PASSWORD must be configured for production SMTP.', $violations);
        $this->assertContains(
            'MAIL_FROM_ADDRESS must not use the placeholder sender address.',
            $violations,
        );
        $this->assertContains(
            'MAIL_FROM_NAME must be configured for production SMTP.',
            $violations,
        );
    }

    public function test_on_one_server_requires_a_shared_cache_but_unshared_cache_is_allowed_without_it(): void
    {
        $config = $this->validConfig();
        $config['cache']['default'] = 'file';
        $config['cache']['stores']['file'] = ['driver' => 'file'];

        $withoutOnOneServer = $this->policy()->violations($config, 60, 5, false);
        $withOnOneServer = $this->policy()->violations($config, 60, 5, true);

        $this->assertSame([], $withoutOnOneServer);
        $this->assertContains(
            'CACHE_STORE must be a shared store for onOneServer scheduler events.',
            $withOnOneServer,
        );

        $config = $this->validConfig();
        $config['cache']['stores']['database']['driver'] = 'file';
        $this->assertContains(
            'The scheduler cache store driver must match CACHE_STORE.',
            $this->policy()->violations($config, 60, 5, true),
        );
    }

    public function test_policy_command_fails_closed_for_invalid_production_configuration(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'queue.default' => 'sync',
            'queue.connections.database.connection' => 'other',
            'queue.connections.database.retry_after' => 60,
            'operations.queue_worker_timeout' => 60,
            'operations.queue_worker_restart_delay' => 5,
        ]);

        try {
            $this->artisan('ops:validate-production')
                ->expectsOutput('DB_QUEUE_CONNECTION must equal DB_CONNECTION for transactional queue writes.')
                ->assertExitCode(1);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_policy_command_is_a_noop_outside_production(): void
    {
        $this->artisan('ops:validate-production')
            ->expectsOutput('Production operations policy skipped outside production.')
            ->assertExitCode(0);
    }

    public function test_policy_command_accepts_a_valid_production_topology_with_a_tls_transport(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        $this->applyValidProductionConfig();
        app('mail.manager')->forgetMailers();

        try {
            $this->artisan('ops:validate-production')
                ->expectsOutput('Production operations policy passed.')
                ->assertExitCode(0);
        } finally {
            app('mail.manager')->forgetMailers();
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_policy_command_fails_when_the_built_transport_does_not_require_tls(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        $config = $this->validConfig();
        // A config array that passes the value checks can still build a
        // plaintext transport, so the gate must inspect what Laravel builds.
        $config['mail']['mailers']['smtp']['require_tls'] = false;
        $this->applyValidProductionConfig($config);
        app('mail.manager')->forgetMailers();

        try {
            $this->artisan('ops:validate-production')
                ->expectsOutput('MAIL_REQUIRE_TLS must be true in production.')
                ->expectsOutput(
                    'The production SMTP transport must require TLS: neither implicit TLS (MAIL_SCHEME=smtps) '
                    .'nor a required STARTTLS upgrade (MAIL_SCHEME=smtp with MAIL_REQUIRE_TLS=true) is effective.',
                )
                ->assertExitCode(1);
        } finally {
            app('mail.manager')->forgetMailers();
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_raw_preflight_rejects_whitespace_fractional_and_invalid_topology(): void
    {
        foreach ([
            ['DB_QUEUE_RETRY_AFTER', ' 90 '],
            ['DB_QUEUE_RETRY_AFTER', '90.5'],
            ['QUEUE_WORKER_TIMEOUT', '60.5'],
            ['DB_QUEUE_CONNECTION', ' other '],
            ['QUEUE_CONNECTION', 'sync'],
            ['MAIL_SCHEME', 'tls'],
            ['MAIL_REQUIRE_TLS', 'false'],
        ] as [$variable, $value]) {
            $environment = $this->validPreflightEnvironment();
            $environment[$variable] = $value;

            $process = new Process(
                ['sh', $this->projectPath('deployment/validate-production-env.sh')],
                $this->projectPath(),
                $environment,
            );
            $process->run();

            $this->assertFalse($process->isSuccessful(), $variable.' should fail raw preflight');
            $this->assertStringContainsString(
                $variable,
                $process->getErrorOutput().$process->getOutput(),
                'The raw preflight must identify the invalid variable',
            );
        }
    }

    public function test_raw_preflight_accepts_valid_values_and_only_stops_on_the_missing_supervisor(): void
    {
        $process = new Process(
            ['sh', $this->projectPath('deployment/validate-production-env.sh')],
            $this->projectPath(),
            $this->validPreflightEnvironment(),
        );
        $process->run();

        // The value validation must be complete before the supervisor
        // availability gate, so a stale image is the only possible blocker.
        if (is_executable('/usr/local/bin/portal-backend-supervisor')) {
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('Production environment preflight passed.', $process->getOutput());

            return;
        }

        $this->assertFalse($process->isSuccessful());
        $this->assertSame(
            "FATAL: Supervisor binary is missing; rebuild ghcr.io/reisinger-pictures/portal-base, push GHCR, and update the compose digest before starting.\n",
            $process->getErrorOutput(),
        );
        $this->assertStringNotContainsString('Production environment preflight passed.', $process->getOutput());
    }

    public function test_deployment_wires_the_production_policy_and_worker_health_contract(): void
    {
        $compose = $this->read('deployment/docker-compose.yml');
        $supervisor = $this->read('deployment/backend-supervisor.sh');
        $preflight = $this->read('deployment/validate-production-env.sh');
        $dockerfile = $this->read('deployment/Dockerfile');
        $baseImageWorkflow = $this->read('.github/workflows/base-image.yml');
        $queueConfig = $this->read('backend/config/queue.php');
        $mailConfig = $this->read('backend/config/mail.php');
        $policySource = $this->read('backend/app/Support/ProductionOperationsPolicy.php');
        $consoleRoutes = $this->read('backend/routes/console.php');
        $phpunitXml = $this->read('backend/phpunit.xml');
        $e2eScript = $this->read('scripts/e2e-up.sh');
        $localEnv = $this->read('backend/.env.example');
        $ciEnv = $this->read('backend/.env.ci');

        foreach ([
            'APP_ENV=${APP_ENV:-production}',
            'DB_CONNECTION=${DB_CONNECTION:-mariadb}',
            'DB_QUEUE_CONNECTION=${DB_QUEUE_CONNECTION:-mariadb}',
            'DB_QUEUE_RETRY_AFTER=${DB_QUEUE_RETRY_AFTER:-90}',
            'QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}',
            'QUEUE_FAILED_DRIVER=${QUEUE_FAILED_DRIVER:-database-uuids}',
            'QUEUE_WORKER_TIMEOUT=${QUEUE_WORKER_TIMEOUT:-60}',
            'QUEUE_WORKER_RESTART_DELAY=${QUEUE_WORKER_RESTART_DELAY:-5}',
            'CACHE_STORE=${CACHE_STORE:-database}',
            'DB_CACHE_CONNECTION=${DB_CACHE_CONNECTION:-mariadb}',
            'DB_CACHE_LOCK_CONNECTION=${DB_CACHE_LOCK_CONNECTION:-mariadb}',
            'MAIL_SCHEME=${MAIL_SCHEME}',
            'MAIL_REQUIRE_TLS=${MAIL_REQUIRE_TLS:-true}',
        ] as $environmentLine) {
            $this->assertStringContainsString('- '.$environmentLine, $compose);
        }

        $this->assertStringContainsString('/usr/local/bin/validate-production-env || exit 1;', $compose);
        $this->assertStringContainsString('php artisan cache:clear || exit 1;', $compose);
        $this->assertStringContainsString('php artisan optimize || exit 1;', $compose);
        $this->assertStringContainsString('php artisan ops:validate-production || exit 1;', $compose);
        $this->assertStringContainsString('APP_ENV muss im Produktions-Stack production sein', $compose);
        $this->assertStringContainsString('if [ ! -x /usr/local/bin/validate-production-env ]', $compose);
        $this->assertStringContainsString('[ ! -x /usr/local/bin/portal-backend-supervisor ]', $compose);
        $this->assertStringContainsString('Rebuild portal-base:8.5, push GHCR, update the pinned compose digest', $compose);
        $this->assertStringContainsString('exec /usr/local/bin/portal-backend-supervisor', $compose);
        $availabilityPosition = strpos($compose, 'if [ ! -x /usr/local/bin/validate-production-env ]');
        $preflightPosition = strpos($compose, '/usr/local/bin/validate-production-env || exit 1;');
        $cacheClearPosition = strpos($compose, 'php artisan cache:clear || exit 1;');
        $policyPosition = strpos($compose, 'php artisan ops:validate-production || exit 1;');
        $migrationPosition = strpos($compose, 'php artisan migrate --force');
        $supervisorPosition = strpos($compose, 'exec /usr/local/bin/portal-backend-supervisor');
        $this->assertNotFalse($availabilityPosition);
        $this->assertNotFalse($preflightPosition);
        $this->assertNotFalse($cacheClearPosition);
        $this->assertNotFalse($policyPosition);
        $this->assertNotFalse($migrationPosition);
        $this->assertNotFalse($supervisorPosition);
        $this->assertLessThan($migrationPosition, $availabilityPosition);
        $this->assertLessThan($cacheClearPosition, $availabilityPosition);
        $this->assertLessThan($cacheClearPosition, $preflightPosition);
        $this->assertLessThan($migrationPosition, $preflightPosition);
        $this->assertLessThan($migrationPosition, $policyPosition);
        $this->assertLessThan($supervisorPosition, $policyPosition);
        $this->assertStringContainsString('is_positive_integer()', $preflight);
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER must be a raw positive integer.', $preflight);
        $this->assertStringContainsString('MAIL_REQUIRE_TLS must be true.', $preflight);
        $this->assertStringContainsString('strcasecmp', $policySource);
        $this->assertStringContainsString('/tmp/portal-queue-supervisor.pid', $compose);
        $this->assertStringContainsString('/tmp/portal-queue-worker.pid', $compose);
        $this->assertStringContainsString('/tmp/portal-scheduler.pid', $compose);
        $this->assertStringContainsString('kill -0 "$$(cat /tmp/portal-queue-worker.pid)"', $compose);
        $this->assertStringContainsString('wait "$worker_pid"', $supervisor);
        $this->assertStringContainsString('--tries=3', $supervisor);
        $this->assertStringContainsString('--timeout="$QUEUE_WORKER_TIMEOUT"', $supervisor);
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-90}"', $supervisor);
        $this->assertStringContainsString('QUEUE_CONNECTION muss im Produktions-Stack database', $supervisor);
        $this->assertStringContainsString('DB_QUEUE_CONNECTION muss DB_CONNECTION entsprechen', $supervisor);
        $this->assertStringContainsString('QUEUE_WORKER_TIMEOUT muss kleiner als DB_QUEUE_RETRY_AFTER', $supervisor);
        $this->assertStringContainsString('sleep "$QUEUE_WORKER_RESTART_DELAY"', $supervisor);
        $this->assertStringContainsString('exec php-fpm -F', $supervisor);
        $this->assertStringContainsString(
            'COPY backend-supervisor.sh /usr/local/bin/portal-backend-supervisor',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'COPY validate-production-env.sh /usr/local/bin/validate-production-env',
            $dockerfile,
        );
        $this->assertStringContainsString("- 'deployment/backend-supervisor.sh'", $baseImageWorkflow);
        $this->assertStringContainsString("- 'deployment/validate-production-env.sh'", $baseImageWorkflow);
        $this->assertStringContainsString(
            "'connection' => env('DB_QUEUE_CONNECTION', env('DB_CONNECTION'))",
            $queueConfig,
        );
        $this->assertStringContainsString("'scheme' => env('MAIL_SCHEME')", $mailConfig);
        $this->assertStringContainsString("'require_tls' => env('MAIL_REQUIRE_TLS'", $mailConfig);
        $this->assertStringNotContainsString("'encryption' => env('MAIL_ENCRYPTION')", $mailConfig);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION', $compose);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION', $preflight);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION', $phpunitXml);
        $this->assertStringNotContainsString('MAIL_ENCRYPTION', $e2eScript);
        $this->assertStringNotContainsString(
            "'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com')",
            $mailConfig,
        );
        $this->assertStringContainsString("Schedule::useCache(config('cache.default'));", $consoleRoutes);

        foreach ([$localEnv, $ciEnv] as $environmentFile) {
            $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=sync$/m', $environmentFile);
            $this->assertMatchesRegularExpression('/^CACHE_STORE=database$/m', $environmentFile);
            $this->assertMatchesRegularExpression('/^MAIL_SCHEME=smtp$/m', $environmentFile);
            $this->assertMatchesRegularExpression('/^MAIL_REQUIRE_TLS=false$/m', $environmentFile);
            $this->assertDoesNotMatchRegularExpression('/^MAIL_ENCRYPTION=/m', $environmentFile);
        }
        $this->assertDoesNotMatchRegularExpression('/^DB_QUEUE_CONNECTION=/m', $ciEnv);
        $this->assertDoesNotMatchRegularExpression('/^DB_CACHE_CONNECTION=/m', $ciEnv);
        $this->assertDoesNotMatchRegularExpression('/^DB_CACHE_LOCK_CONNECTION=/m', $ciEnv);
    }

    public function test_supervisor_and_preflight_scripts_pass_shell_syntax_validation(): void
    {
        foreach ([
            'deployment/backend-supervisor.sh',
            'deployment/validate-production-env.sh',
        ] as $script) {
            $process = new Process(
                ['sh', '-n', $this->projectPath($script)],
                $this->projectPath(),
            );
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                $script.': '.$process->getErrorOutput().$process->getOutput(),
            );
        }
    }

    private function policy(): ProductionOperationsPolicy
    {
        return new ProductionOperationsPolicy;
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return [
            'database' => [
                'default' => 'mariadb',
            ],
            'queue' => [
                'default' => 'database',
                'connections' => [
                    'database' => [
                        'driver' => 'database',
                        'connection' => 'mariadb',
                        'retry_after' => 90,
                    ],
                ],
                'failed' => [
                    'driver' => 'database-uuids',
                    'database' => 'mariadb',
                ],
                'batching' => [
                    'database' => 'mariadb',
                ],
            ],
            'cache' => [
                'default' => 'database',
                'stores' => [
                    'database' => [
                        'driver' => 'database',
                        'connection' => 'mariadb',
                        'lock_connection' => 'mariadb',
                    ],
                ],
            ],
            'mail' => [
                'default' => 'smtp',
                'mailers' => [
                    'smtp' => [
                        'transport' => 'smtp',
                        'scheme' => 'smtp',
                        'require_tls' => true,
                        'host' => 'smtp.example.com',
                        'port' => 587,
                        'username' => 'mailer',
                        'password' => 'test-secret',
                    ],
                ],
                'from' => [
                    'address' => 'noreply@example.com',
                    'name' => 'Portal',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function applyValidProductionConfig(?array $config = null): void
    {
        $config ??= $this->validConfig();

        config([
            'queue' => $config['queue'],
            'database.default' => $config['database']['default'],
            'cache.default' => $config['cache']['default'],
            'cache.stores' => $config['cache']['stores'],
            'mail.default' => $config['mail']['default'],
            'mail.mailers' => $config['mail']['mailers'],
            'mail.from' => $config['mail']['from'],
            'operations.queue_worker_timeout' => 60,
            'operations.queue_worker_restart_delay' => 5,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validPreflightEnvironment(): array
    {
        return [
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'mariadb',
            'DB_QUEUE_CONNECTION' => 'mariadb',
            'QUEUE_CONNECTION' => 'database',
            'QUEUE_FAILED_DRIVER' => 'database-uuids',
            'DB_QUEUE_RETRY_AFTER' => '90',
            'QUEUE_WORKER_TIMEOUT' => '60',
            'QUEUE_WORKER_RESTART_DELAY' => '5',
            'CACHE_STORE' => 'database',
            'DB_CACHE_CONNECTION' => 'mariadb',
            'DB_CACHE_LOCK_CONNECTION' => 'mariadb',
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => 'smtp',
            'MAIL_REQUIRE_TLS' => 'true',
            'MAIL_HOST' => 'smtp.example.com',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'mailer',
            'MAIL_PASSWORD' => 'test-secret',
            'MAIL_FROM_ADDRESS' => 'noreply@example.com',
            'MAIL_FROM_NAME' => 'Portal',
        ];
    }

    private function read(string $relativePath): string
    {
        $path = $this->projectPath($relativePath);
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }

    private function projectPath(string $relativePath = ''): string
    {
        $root = dirname(__DIR__, 3);

        return $relativePath === '' ? $root : $root.'/'.$relativePath;
    }
}
