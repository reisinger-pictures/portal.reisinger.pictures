<?php

namespace App\Support;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Creates UUID-keyed database queue connections for the repository schema.
 */
final class UuidDatabaseQueueConnector implements ConnectorInterface
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): UuidDatabaseQueue
    {
        return new UuidDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'] ?? 'jobs',
            $config['queue'] ?? 'default',
            (int) ($config['retry_after'] ?? 90),
            (bool) ($config['after_commit'] ?? false),
        );
    }
}
