<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\DisputeMailDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * FINAL-2: DisputeMailDispatcher claimed "at-most-once enqueue that stays
 * retryable" while its sibling InvoiceMailDispatcher additionally required the
 * transactional database queue. Without that guard a misconfigured production
 * host would deliver the chargeback alert non-atomically and could duplicate
 * it, instead of failing closed the way the invoice path does.
 *
 * These tests pin the guard, not the mail flow itself.
 */
class DisputeMailQueuePolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableQueueProvider(): array
    {
        return [
            'inline sync driver' => [[
                'queue.default' => 'sync',
                'queue.connections.database.driver' => 'database',
                'queue.connections.database.connection' => 'sqlite',
            ]],
            'separate queue connection' => [[
                'queue.default' => 'database',
                'queue.connections.database.driver' => 'database',
                'queue.connections.database.connection' => 'some_other_connection',
            ]],
            'empty queue connection' => [[
                'queue.default' => 'database',
                'queue.connections.database.driver' => 'database',
                'queue.connections.database.connection' => '',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('unusableQueueProvider')]
    public function test_non_production_environment_with_an_unusable_queue_refuses_the_durable_claim(array $config): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');

        $original = [];
        foreach ($config as $key => $value) {
            $original[$key] = config($key);
            config([$key => $value]);
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Dispute mail requires the transactional database queue outside local test environments.',
            );

            app(DisputeMailDispatcher::class)->queueOnce(new Order);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
            foreach ($original as $key => $value) {
                config([$key => $value]);
            }
        }
    }

    public function test_after_commit_deferral_is_refused_even_with_an_otherwise_usable_queue(): void
    {
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'staging');

        $originalDefault = config('queue.default');
        $originalDriver = config('queue.connections.database.driver');
        $originalConnection = config('queue.connections.database.connection');
        $originalAfterCommit = config('queue.connections.database.after_commit');
        $originalDatabase = config('database.default');

        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'database.default' => 'sqlite',
            'queue.connections.database.after_commit' => true,
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Dispute mail requires the transactional database queue outside local test environments.',
            );

            app(DisputeMailDispatcher::class)->queueOnce(new Order);
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
            config([
                'queue.default' => $originalDefault,
                'queue.connections.database.driver' => $originalDriver,
                'queue.connections.database.connection' => $originalConnection,
                'queue.connections.database.after_commit' => $originalAfterCommit,
                'database.default' => $originalDatabase,
            ]);
        }
    }

    public function test_local_environment_keeps_the_inline_driver(): void
    {
        $originalDefault = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            // Local/test intentionally keep the inline driver, so the guard must
            // not fire. Only the guard is under test here; the dispatch itself
            // legitimately fails on an unsaved order without a snapshot, and
            // that must not be the queue-policy rejection.
            try {
                app(DisputeMailDispatcher::class)->queueOnce(new Order);
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString(
                    'transactional database queue',
                    $exception->getMessage(),
                    'The queue policy guard must not fire in the local environment.',
                );
            }
        } finally {
            config(['queue.default' => $originalDefault]);
        }
    }
}
