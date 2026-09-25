<?php

namespace App\Support;

use RuntimeException;

/**
 * Validates the production process topology that cannot be inferred safely from
 * a Laravel config default alone.
 */
final class ProductionOperationsPolicy
{
    /**
     * Cache stores that can coordinate scheduler mutexes across containers.
     *
     * The current deployment uses the already provisioned MariaDB database
     * store. The other stores remain valid only when their infrastructure is
     * explicitly provisioned and configured.
     */
    public const SHARED_CACHE_STORES = [
        'database',
        'redis',
        'memcached',
        'dynamodb',
    ];

    public const SMTP_SCHEMES = ['smtp', 'smtps'];

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function violations(
        array $config,
        int $workerTimeout,
        int $workerRestartDelay,
        bool $usesOnOneServerScheduler,
    ): array {
        $violations = [];

        $this->validateQueue($config, $workerTimeout, $violations);
        $this->validateWorkerRestartDelay($workerRestartDelay, $violations);
        $this->validateScheduler($config, $usesOnOneServerScheduler, $violations);
        $this->validateMail($config, $violations);

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     */
    public function assertValid(
        array $config,
        int $workerTimeout,
        int $workerRestartDelay,
        bool $usesOnOneServerScheduler,
    ): void {
        $violations = $this->violations(
            $config,
            $workerTimeout,
            $workerRestartDelay,
            $usesOnOneServerScheduler,
        );

        if ($violations !== []) {
            throw new RuntimeException(
                'Production operations policy failed: '.implode(' ', $violations),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     */
    private function validateQueue(array $config, int $workerTimeout, array &$violations): void
    {
        $databaseDefault = $this->stringValue($config['database']['default'] ?? null);
        $this->addViolation(
            $databaseDefault === null,
            $violations,
            'DB_CONNECTION must identify the application database.',
        );

        $queueDefault = $this->stringValue($config['queue']['default'] ?? null);
        $this->addViolation(
            $queueDefault !== 'database',
            $violations,
            'QUEUE_CONNECTION must be database in production.',
        );

        $databaseQueue = $config['queue']['connections']['database'] ?? null;
        if (! is_array($databaseQueue)) {
            $this->addViolation(true, $violations, 'The database queue connection is missing.');

            return;
        }

        $this->addViolation(
            ($databaseQueue['driver'] ?? null) !== 'database',
            $violations,
            'The database queue connection must use the database driver.',
        );

        $queueConnection = $this->stringValue($databaseQueue['connection'] ?? null);
        $this->addViolation(
            $queueConnection === null,
            $violations,
            'DB_QUEUE_CONNECTION must explicitly identify the application database connection.',
        );
        $this->addViolation(
            $queueConnection !== null
                && $databaseDefault !== null
                && $queueConnection !== $databaseDefault,
            $violations,
            'DB_QUEUE_CONNECTION must equal DB_CONNECTION for transactional queue writes.',
        );

        $retryAfter = $this->positiveInteger($databaseQueue['retry_after'] ?? null);
        $this->addViolation(
            $retryAfter === null,
            $violations,
            'DB_QUEUE_RETRY_AFTER must be a positive integer.',
        );
        $this->addViolation(
            $workerTimeout < 1,
            $violations,
            'QUEUE_WORKER_TIMEOUT must be a positive integer.',
        );
        $this->addViolation(
            $retryAfter !== null && $workerTimeout > 0 && $workerTimeout >= $retryAfter,
            $violations,
            'QUEUE_WORKER_TIMEOUT must be less than DB_QUEUE_RETRY_AFTER.',
        );

        $failedQueue = $config['queue']['failed'] ?? null;
        if (! is_array($failedQueue)) {
            $this->addViolation(true, $violations, 'The failed-job store is missing.');

            return;
        }

        $this->addViolation(
            ($failedQueue['driver'] ?? null) !== 'database-uuids',
            $violations,
            'QUEUE_FAILED_DRIVER must be database-uuids in production.',
        );
        $failedConnection = $this->stringValue($failedQueue['database'] ?? null);
        $this->addViolation(
            $failedConnection === null,
            $violations,
            'The failed-job store must explicitly use the application database connection.',
        );
        $this->addViolation(
            $failedConnection !== null
                && $databaseDefault !== null
                && $failedConnection !== $databaseDefault,
            $violations,
            'The failed-job store must use the same database connection as the application.',
        );

        $batching = $config['queue']['batching'] ?? null;
        if (! is_array($batching)) {
            $this->addViolation(true, $violations, 'The job-batching store is missing.');

            return;
        }

        $batchingConnection = $this->stringValue($batching['database'] ?? null);
        $this->addViolation(
            $batchingConnection === null,
            $violations,
            'The job-batching store must explicitly use the application database connection.',
        );
        $this->addViolation(
            $batchingConnection !== null
                && $databaseDefault !== null
                && $batchingConnection !== $databaseDefault,
            $violations,
            'The job-batching store must use the same database connection as the application.',
        );
    }

    /**
     * @param  list<string>  $violations
     */
    private function validateWorkerRestartDelay(int $workerRestartDelay, array &$violations): void
    {
        $this->addViolation(
            $workerRestartDelay < 1,
            $violations,
            'QUEUE_WORKER_RESTART_DELAY must be a positive integer.',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     */
    private function validateScheduler(
        array $config,
        bool $usesOnOneServerScheduler,
        array &$violations,
    ): void {
        if (! $usesOnOneServerScheduler) {
            return;
        }

        $cacheStore = $this->stringValue($config['cache']['default'] ?? null);
        $this->addViolation(
            $cacheStore === null,
            $violations,
            'CACHE_STORE must be configured for onOneServer scheduler events.',
        );
        $this->addViolation(
            $cacheStore !== null && ! in_array($cacheStore, self::SHARED_CACHE_STORES, true),
            $violations,
            'CACHE_STORE must be a shared store for onOneServer scheduler events.',
        );

        if ($cacheStore === null) {
            return;
        }

        $store = $config['cache']['stores'][$cacheStore] ?? null;
        $this->addViolation(
            ! is_array($store),
            $violations,
            'The configured scheduler cache store is missing.',
        );

        if (! is_array($store)) {
            return;
        }

        $this->addViolation(
            ($store['driver'] ?? null) !== $cacheStore,
            $violations,
            'The scheduler cache store driver must match CACHE_STORE.',
        );

        if ($cacheStore !== 'database') {
            return;
        }

        $databaseDefault = $this->stringValue($config['database']['default'] ?? null);
        $cacheConnection = $this->stringValue($store['connection'] ?? null);
        $this->addViolation(
            $cacheConnection === null,
            $violations,
            'DB_CACHE_CONNECTION must explicitly identify the shared database cache connection.',
        );
        $this->addViolation(
            $cacheConnection !== null
                && $databaseDefault !== null
                && $cacheConnection !== $databaseDefault,
            $violations,
            'DB_CACHE_CONNECTION must equal DB_CONNECTION for the database scheduler cache.',
        );

        $lockConnection = $this->stringValue($store['lock_connection'] ?? null);
        $this->addViolation(
            $lockConnection === null,
            $violations,
            'DB_CACHE_LOCK_CONNECTION must explicitly identify the scheduler lock connection.',
        );
        $this->addViolation(
            $lockConnection !== null
                && $cacheConnection !== null
                && $lockConnection !== $cacheConnection,
            $violations,
            'DB_CACHE_LOCK_CONNECTION must equal DB_CACHE_CONNECTION for the scheduler mutex.',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $violations
     */
    private function validateMail(array $config, array &$violations): void
    {
        $mailer = $this->stringValue($config['mail']['default'] ?? null);
        $this->addViolation(
            $mailer !== 'smtp',
            $violations,
            'MAIL_MAILER must be smtp in production.',
        );

        $smtp = $config['mail']['mailers']['smtp'] ?? null;
        if (! is_array($smtp)) {
            $this->addViolation(true, $violations, 'The SMTP mailer configuration is missing.');

            return;
        }

        $this->addViolation(
            ($smtp['transport'] ?? null) !== 'smtp',
            $violations,
            'The production mailer must use the SMTP transport.',
        );
        $this->addViolation(
            $this->stringValue($smtp['host'] ?? null) === null,
            $violations,
            'MAIL_HOST must be configured for production SMTP.',
        );

        $port = $this->positiveInteger($smtp['port'] ?? null);
        $this->addViolation(
            $port === null || $port > 65535,
            $violations,
            'MAIL_PORT must be an integer between 1 and 65535.',
        );

        $scheme = $this->stringValue($smtp['scheme'] ?? null);
        $this->addViolation(
            $scheme === null || ! in_array($scheme, self::SMTP_SCHEMES, true),
            $violations,
            'MAIL_SCHEME must be smtp or smtps in production.',
        );

        $requireTls = $this->booleanValue($smtp['require_tls'] ?? null);
        $this->addViolation(
            $requireTls !== true,
            $violations,
            'MAIL_REQUIRE_TLS must be true in production.',
        );
        $this->addViolation(
            $this->stringValue($smtp['username'] ?? null) === null,
            $violations,
            'MAIL_USERNAME must be configured for production SMTP.',
        );
        $this->addViolation(
            $this->stringValue($smtp['password'] ?? null) === null,
            $violations,
            'MAIL_PASSWORD must be configured for production SMTP.',
        );

        $from = $config['mail']['from'] ?? null;
        if (! is_array($from)) {
            $this->addViolation(true, $violations, 'The production mail sender configuration is missing.');

            return;
        }

        $fromAddress = $this->stringValue($from['address'] ?? null);
        $this->addViolation(
            $fromAddress === null || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false,
            $violations,
            'MAIL_FROM_ADDRESS must be a valid sender address.',
        );
        $this->addViolation(
            $fromAddress !== null && strcasecmp($fromAddress, 'hello@example.com') === 0,
            $violations,
            'MAIL_FROM_ADDRESS must not use the placeholder sender address.',
        );
        $fromName = $this->stringValue($from['name'] ?? null);
        $this->addViolation(
            $fromName === null,
            $violations,
            'MAIL_FROM_NAME must be configured for production SMTP.',
        );
        $this->addViolation(
            $fromName !== null && preg_match('/[\x00-\x1F\x7F]/', $fromName) === 1,
            $violations,
            'MAIL_FROM_NAME must not contain control characters.',
        );
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => null,
        };
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : (int) $integer;
    }

    /**
     * @param  list<string>  $violations
     */
    private function addViolation(bool $condition, array &$violations, string $message): void
    {
        if ($condition) {
            $violations[] = $message;
        }
    }
}
